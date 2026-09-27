<?php

use App\Services\Paperclip\BotEnvironment;
use App\Services\Paperclip\ColdSetting;
use App\Services\Paperclip\RestartCard;

// ---------------------------------------------------------------------------
// The allowlist is the guarantee that no caller string reaches the board.
// ---------------------------------------------------------------------------

it('names each cold setting by its exact environment variable', function () {
    // The value IS the env var the bot reads, so a rename here is a real
    // behaviour change, not a cosmetic one.
    expect(ColdSetting::TWO_AUTOMOD->value)->toBe('TWO_AUTOMOD');
});

it('is a closed enum, so an unknown setting cannot be constructed from a string', function () {
    expect(ColdSetting::tryFrom('TWO_AUTOMOD'))->toBe(ColdSetting::TWO_AUTOMOD)
        ->and(ColdSetting::tryFrom('DROP TABLE users'))->toBeNull()
        ->and(ColdSetting::tryFrom('BOT_SHARED_SECRET'))->toBeNull();
});

// ---------------------------------------------------------------------------
// The pinned restart command (TOG-3573) and the fixed card around it.
// ---------------------------------------------------------------------------

it('pins each bot environment to its own Coolify application', function () {
    expect(BotEnvironment::Staging->coolifyAppUuid())->toBe('uy4d9ndeygjcem6lgayhxgub')
        ->and(BotEnvironment::Production->coolifyAppUuid())->toBe('cangagerae31txrk2vfvzzyq')
        ->and(BotEnvironment::tryFrom('prod'))->toBeNull();
});

it('restarts TWO_AUTOMOD through the Coolify API for the target environment only', function () {
    $staging = ColdSetting::TWO_AUTOMOD->restartCommand(BotEnvironment::Staging);

    expect($staging)->toBe('curl -fsS -X POST -H "Authorization: Bearer $COOLIFY_TOKEN" '
        .'"$COOLIFY_URL/api/v1/applications/uy4d9ndeygjcem6lgayhxgub/restart"')
        ->and($staging)->not->toContain('cangagerae31txrk2vfvzzyq')
        ->and(ColdSetting::TWO_AUTOMOD->restartCommand(BotEnvironment::Production))
        ->toContain('/applications/cangagerae31txrk2vfvzzyq/restart');
});

it('builds a card from constants, the environment and one validated value', function () {
    $card = ColdSetting::TWO_AUTOMOD->restartCard('true', BotEnvironment::Staging);

    expect($card)->toBeInstanceOf(RestartCard::class)
        ->and($card->title)->toBe('Operator: restart TWO bot (staging) to apply TWO_AUTOMOD=true')
        ->and($card->body)->toContain('`TWO_AUTOMOD`')
        ->and($card->body)->toContain('`true`')
        ->and($card->body)->toContain('two-bot staging (Coolify app `uy4d9ndeygjcem6lgayhxgub`)')
        ->and($card->body)->toContain('## Apply')
        ->and($card->body)->toContain('## Rollback')
        ->and($card->body)->toContain('back to its previous value')
        ->and(substr_count($card->body, ColdSetting::TWO_AUTOMOD->restartCommand(BotEnvironment::Staging)))->toBe(2);
});
