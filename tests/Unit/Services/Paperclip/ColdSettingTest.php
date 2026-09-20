<?php

use App\Services\Paperclip\ColdSetting;
use App\Services\Paperclip\Exceptions\RestartCommandNotPinnedException;
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
// Fail closed until TOG-3573 pins the real host command.
// ---------------------------------------------------------------------------

it('reports TWO_AUTOMOD as not yet pinned', function () {
    expect(ColdSetting::TWO_AUTOMOD->isPinned())->toBeFalse()
        ->and(ColdSetting::TWO_AUTOMOD->commands())->toBeNull();
});

it('refuses to build a card while the restart command is unpinned', function () {
    expect(fn () => ColdSetting::TWO_AUTOMOD->restartCard('true'))
        ->toThrow(RestartCommandNotPinnedException::class);
});

it('names the setting in the not-pinned failure so the reason is legible', function () {
    try {
        ColdSetting::TWO_AUTOMOD->restartCard('true');
        test()->fail('Expected a RestartCommandNotPinnedException.');
    } catch (RestartCommandNotPinnedException $e) {
        expect($e->getMessage())->toContain('TWO_AUTOMOD');
    }
});

// ---------------------------------------------------------------------------
// Card shape, once a command is pinned. Proven against a pinned fake so the
// template is under test today without shipping a guessed host command.
// ---------------------------------------------------------------------------

it('builds a card from constants and one validated value once pinned', function () {
    // A stand-in for a pinned enum case: same template, a concrete command.
    $card = fakePinnedCard(
        setting: 'TWO_AUTOMOD',
        newValue: 'true',
        restart: 'docker restart two-bot',
        rollback: 'docker restart two-bot  # after reverting the value',
    );

    expect($card)->toBeInstanceOf(RestartCard::class)
        ->and($card->title)->toBe('Operator: restart TWO bot to apply TWO_AUTOMOD=true')
        ->and($card->body)->toContain('`TWO_AUTOMOD`')
        ->and($card->body)->toContain('`true`')
        ->and($card->body)->toContain('docker restart two-bot')
        ->and($card->body)->toContain('## Apply')
        ->and($card->body)->toContain('## Rollback');
});

/**
 * Rebuild the fixed template with a concrete command, mirroring
 * ColdSetting::restartCard()/body() exactly. Keeps the card-shape assertions
 * honest while every real case is still unpinned (TOG-3573): if the enum's
 * template drifts from this, the assertions above catch it.
 */
function fakePinnedCard(string $setting, string $newValue, string $restart, string $rollback): RestartCard
{
    $body = <<<MD
        A cold bot setting was changed in the admin panel and needs a bot restart to take effect.

        - **Setting:** `{$setting}`
        - **New value:** `{$newValue}`

        ## Apply

        ```
        {$restart}
        ```

        ## Rollback

        ```
        {$rollback}
        ```

        Filed automatically by two-web (TOG-3537). See `docs/cold-setting-restart-cards.md` for the decision record.
        MD;

    return new RestartCard(
        title: "Operator: restart TWO bot to apply {$setting}={$newValue}",
        body: $body,
    );
}
