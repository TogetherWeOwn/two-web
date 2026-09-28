<?php

use App\Filament\StagingPages\BotSettings;
use App\Models\User;
use App\Services\Bot\BotSettingKey;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Livewire\livewire;

function configureBotSettingsClient(): void
{
    config()->set([
        'services.bot.url' => 'http://bot-settings.internal:3001',
        'services.bot.secret' => 'feature-test-secret-at-least-32-characters',
        'services.bot.key_id' => 'web-feature-test',
        'services.bot.timeout' => 5,
    ]);
}

/** @param array<string, mixed> $stored */
function fakeBotSettings(array $stored = []): void
{
    configureBotSettingsClient();

    Http::fake(function (Request $request) use ($stored) {
        $body = json_decode($request->body(), true);
        $key = is_string($body['key'] ?? null) ? $body['key'] : '';

        if (($body['action'] ?? null) === 'settings.get') {
            $hasValue = array_key_exists($key, $stored);

            return Http::response([
                'ok' => true,
                'result' => [
                    'key' => $key,
                    'value' => $hasValue ? $stored[$key] : null,
                    'source' => $hasValue ? 'store' : 'unset',
                ],
                'request_id' => 'read-'.$key,
            ]);
        }

        if (($body['action'] ?? null) === 'settings.set') {
            return Http::response([
                'ok' => true,
                'result' => [
                    'key' => $key,
                    'outcome' => ($body['value'] ?? null) === null ? 'unset' : 'saved',
                ],
                'request_id' => 'write-'.$key,
            ]);
        }

        return Http::response([
            'ok' => false,
            'error' => ['code' => 'malformed', 'message' => 'unexpected action', 'retryable' => false],
            'request_id' => 'unexpected',
        ], 400);
    });
}

/** @return list<array<string, mixed>> */
function capturedSettingWrites(): array
{
    return collect(Http::recorded())
        ->map(fn (array $record): array => [
            'request' => $record[0],
            'body' => json_decode($record[0]->body(), true),
        ])
        ->filter(fn (array $record): bool => ($record['body']['action'] ?? null) === 'settings.set')
        ->values()
        ->all();
}

it('redirects a guest from the bot settings page to Discord login', function () {
    $this->get('/admin/bot-settings')->assertRedirect(route('login'));

    Http::assertNothingSent();
});

it('answers a signed-in non-admin with 403', function () {
    $member = User::factory()->create(['is_moderator' => false]);

    $this->actingAs($member)->get('/admin/bot-settings')->assertForbidden();

    Http::assertNothingSent();
});

it('loads bot settings for an admin and distinguishes store from environment fallback', function () {
    fakeBotSettings([
        'TWO_AUTOMOD_REPEAT_COUNT' => 4,
    ]);
    $admin = User::factory()->moderator()->create();

    $this->actingAs($admin)
        ->get('/admin/bot-settings')
        ->assertOk()
        ->assertSee('Bot settings')
        ->assertSee('Environment fallback')
        ->assertSee('Stored dashboard override')
        ->assertSee('operator restart card');

    Http::assertSentCount(count(BotSettingKey::cases()));
    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Idempotency-Key'));
});

it('saves one changed threshold with the admin Discord id and a UUID idempotency key', function () {
    fakeBotSettings();
    $admin = User::factory()->moderator()->create([
        'discord_id' => '111111111111111111',
    ]);
    $this->actingAs($admin);

    $page = livewire(BotSettings::class)
        ->set('data.settings.TWO_AUTOMOD_REPEAT_COUNT.source', 'store')
        ->set('data.settings.TWO_AUTOMOD_REPEAT_COUNT.value', 4)
        ->call('save')
        ->assertHasNoErrors();

    $writes = capturedSettingWrites();

    expect($writes)->toHaveCount(1)
        ->and($writes[0]['body'])->toBe([
            'action' => 'settings.set',
            'key' => 'TWO_AUTOMOD_REPEAT_COUNT',
            'value' => 4,
            'updated_by' => '111111111111111111',
        ])
        ->and(Str::isUuid($writes[0]['request']->header('Idempotency-Key')[0]))->toBeTrue();

    // The confirmed result updates the page snapshot, so a second submit of the
    // same state is a no-op rather than a second audit row.
    $page->call('save')->assertHasNoErrors();

    expect(capturedSettingWrites())->toHaveCount(1);
});

it('retries an uncertain save with the same operation key instead of reading a stale cache', function () {
    configureBotSettingsClient();
    $admin = User::factory()->moderator()->create([
        'discord_id' => '111111111111111111',
    ]);
    $this->actingAs($admin);

    $writeAttempts = 0;
    Http::fake(function (Request $request) use (&$writeAttempts) {
        $body = json_decode($request->body(), true);
        $key = is_string($body['key'] ?? null) ? $body['key'] : '';

        if (($body['action'] ?? null) === 'settings.get') {
            return Http::response([
                'ok' => true,
                'result' => ['key' => $key, 'value' => null, 'source' => 'unset'],
                'request_id' => 'read-'.$key,
            ]);
        }

        $writeAttempts++;

        if ($writeAttempts === 1) {
            return Http::response('upstream response lost', 504);
        }

        return Http::response([
            'ok' => true,
            'result' => ['key' => $key, 'outcome' => 'saved'],
            'request_id' => 'write-'.$key,
        ], 200, ['Idempotent-Replay' => 'true']);
    });

    $page = livewire(BotSettings::class)
        ->set('data.settings.TWO_AUTOMOD_REPEAT_COUNT.source', 'store')
        ->set('data.settings.TWO_AUTOMOD_REPEAT_COUNT.value', 4)
        ->call('save')
        ->assertHasNoErrors();

    $firstWrite = capturedSettingWrites()[0];
    $operationKey = $firstWrite['request']->header('Idempotency-Key')[0];

    expect($page->get('pendingWrites')['TWO_AUTOMOD_REPEAT_COUNT']['idempotency_key'])->toBe($operationKey);

    $readsBeforeReload = collect(Http::recorded())
        ->filter(fn (array $record): bool => (json_decode($record[0]->body(), true)['action'] ?? null) === 'settings.get')
        ->count();

    $page->call('reloadSettings')->assertHasNoErrors();

    expect($page->get('pendingWrites')['TWO_AUTOMOD_REPEAT_COUNT']['idempotency_key'])->toBe($operationKey)
        ->and(collect(Http::recorded())
            ->filter(fn (array $record): bool => (json_decode($record[0]->body(), true)['action'] ?? null) === 'settings.get')
            ->count())->toBe($readsBeforeReload);

    $page->call('save')->assertHasNoErrors();

    $writes = capturedSettingWrites();

    expect($writes)->toHaveCount(2)
        ->and($writes[1]['body'])->toBe($writes[0]['body'])
        ->and($writes[1]['request']->header('Idempotency-Key')[0])->toBe($operationKey)
        ->and($page->get('pendingWrites'))->toBe([]);
});

it('keeps the cold automod master switch read-only until restart cards are wired', function () {
    fakeBotSettings();
    $this->actingAs(User::factory()->moderator()->create([
        'discord_id' => '111111111111111111',
    ]));

    livewire(BotSettings::class)
        ->set('data.settings.TWO_AUTOMOD.source', 'store')
        ->set('data.settings.TWO_AUTOMOD.value', '1')
        ->call('save')
        ->assertHasNoErrors();

    expect(capturedSettingWrites())->toBe([])
        ->and(BotSettingKey::Automod->isEditable())->toBeFalse();
});

it('sends an explicit null to restore environment fallback', function () {
    fakeBotSettings([
        'TWO_ONBOARDING_DRY_RUN' => true,
    ]);
    $admin = User::factory()->moderator()->create([
        'discord_id' => '111111111111111111',
    ]);
    $this->actingAs($admin);

    livewire(BotSettings::class)
        ->set('data.settings.TWO_ONBOARDING_DRY_RUN.source', 'unset')
        ->call('save')
        ->assertHasNoErrors();

    $writes = capturedSettingWrites();

    expect($writes)->toHaveCount(1)
        ->and($writes[0]['body'])->toMatchArray([
            'action' => 'settings.set',
            'key' => 'TWO_ONBOARDING_DRY_RUN',
            'value' => null,
            'updated_by' => '111111111111111111',
        ]);
});

it('allows a blank stored channel id to override and disable an environment destination', function () {
    fakeBotSettings();
    $this->actingAs(User::factory()->moderator()->create([
        'discord_id' => '111111111111111111',
    ]));

    livewire(BotSettings::class)
        ->set('data.settings.DISCORD_ANCHOR_WELCOME_CHANNEL_ID.source', 'store')
        ->set('data.settings.DISCORD_ANCHOR_WELCOME_CHANNEL_ID.value', '')
        ->call('save')
        ->assertHasNoErrors();

    $writes = capturedSettingWrites();

    expect($writes)->toHaveCount(1)
        ->and($writes[0]['body']['value'])->toBe('');
});

it('validates Discord id lists before calling settings.set', function () {
    fakeBotSettings();
    $this->actingAs(User::factory()->moderator()->create());

    livewire(BotSettings::class)
        ->set('data.settings.DISCORD_LANDING_CHANNEL_IDS.source', 'store')
        ->set('data.settings.DISCORD_LANDING_CHANNEL_IDS.value', "11111111111111111\nnot-a-channel")
        ->call('save')
        ->assertHasErrors(['data.settings.DISCORD_LANDING_CHANNEL_IDS.value']);

    expect(capturedSettingWrites())->toBe([]);
});

it('validates automod sanctions before calling settings.set', function () {
    fakeBotSettings();
    $this->actingAs(User::factory()->moderator()->create());

    livewire(BotSettings::class)
        ->set('data.settings.TWO_AUTOMOD_SANCTIONS.source', 'store')
        ->set('data.settings.TWO_AUTOMOD_SANCTIONS.value', '2:delete,2:warn')
        ->call('save')
        ->assertHasErrors(['data.settings.TWO_AUTOMOD_SANCTIONS.value']);

    expect(capturedSettingWrites())->toBe([]);
});

it('has a closed dashboard allowlist with no environment-only capability keys', function () {
    $keys = array_map(fn (BotSettingKey $setting): string => $setting->value, BotSettingKey::cases());

    expect($keys)->toEqualCanonicalizing([
        'DISCORD_LANDING_CHANNEL_IDS',
        'DISCORD_ANCHOR_WELCOME_CHANNEL_ID',
        'DISCORD_GOODBYE_CHANNEL_IDS',
        'DISCORD_SESSION_LOBBY_VOICE_CHANNEL_ID',
        'DISCORD_SESSION_LOOKING_TO_PLAY_CHANNEL_ID',
        'TWO_ONBOARDING_DRY_RUN',
        'TWO_AUTOMOD',
        'TWO_AUTOMOD_ENFORCE',
        'TWO_AUTOMOD_BAD_WORDS',
        'TWO_AUTOMOD_BLOCKED_ATTACHMENT_EXTENSIONS',
        'TWO_AUTOMOD_ALLOWED_DOMAINS',
        'TWO_AUTOMOD_REPEAT_COUNT',
        'TWO_AUTOMOD_REPEAT_WINDOW_SECONDS',
        'TWO_AUTOMOD_MENTION_LIMIT',
        'TWO_AUTOMOD_BYPASS_ROLE_IDS',
        'TWO_AUTOMOD_EXEMPT_CHANNEL_IDS',
        'TWO_AUTOMOD_SANCTIONS',
    ])
        ->not->toContain('TWO_ONBOARDING_MODE')
        ->not->toContain('TWO_MODERATION')
        ->not->toContain('DISCORD_BOT_TOKEN')
        ->and(array_filter($keys, fn (string $key): bool => str_starts_with($key, 'TWO_INTERNAL_')))
        ->toBe([]);
});
