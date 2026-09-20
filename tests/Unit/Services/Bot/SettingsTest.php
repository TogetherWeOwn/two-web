<?php

use App\Jobs\CallInternalAction;
use App\Services\Bot\Exceptions\BotTransportException;
use App\Services\Bot\Exceptions\InvalidActionRequestException;
use App\Services\Bot\InternalActionClient;
use App\Services\Bot\InternalActionFailure;
use App\Services\Bot\SettingMutation;
use App\Services\Bot\SettingRead;
use App\Services\Bot\SettingReadResult;
use App\Services\Bot\SettingSource;
use App\Services\Bot\SettingWriteOutcome;
use App\Services\Bot\SettingWriteResult;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;

const SETTINGS_BOT_URL = 'http://bot-settings.internal:3001';
const SETTINGS_BOT_ENDPOINT = 'http://bot-settings.internal:3001/internal/actions';
const SETTINGS_BOT_SECRET = 'settings-test-secret-at-least-32-characters';
const SETTINGS_BOT_KEY_ID = 'web-settings-test';

function settingsBotClient(): InternalActionClient
{
    return new InternalActionClient(
        SETTINGS_BOT_URL,
        SETTINGS_BOT_SECRET,
        SETTINGS_BOT_KEY_ID,
        5,
    );
}

function settingSuccess(array $result, string $requestId = '01JSETTING0123456789'): array
{
    return [
        'ok' => true,
        'result' => $result,
        'request_id' => $requestId,
    ];
}

it('reads a stored setting without an idempotency key', function () {
    Http::fake([
        SETTINGS_BOT_ENDPOINT => Http::response(settingSuccess([
            'key' => 'DISCORD_LANDING_CHANNEL_IDS',
            'value' => ['11111111111111111', '22222222222222222'],
            'source' => 'store',
        ])),
    ]);

    $result = settingsBotClient()->getSetting(new SettingRead('DISCORD_LANDING_CHANNEL_IDS'));

    expect($result)->toBeInstanceOf(SettingReadResult::class)
        ->and($result->key)->toBe('DISCORD_LANDING_CHANNEL_IDS')
        ->and($result->value)->toBe(['11111111111111111', '22222222222222222'])
        ->and($result->source)->toBe(SettingSource::Store)
        ->and($result->requestId)->toBe('01JSETTING0123456789');

    Http::assertSent(fn (Request $request) => $request->body() === '{"action":"settings.get","key":"DISCORD_LANDING_CHANNEL_IDS"}'
        && ! $request->hasHeader('Idempotency-Key'));
});

it('represents environment fallback without disclosing its value', function () {
    Http::fake([
        SETTINGS_BOT_ENDPOINT => Http::response(settingSuccess([
            'key' => 'TWO_AUTOMOD_REPEAT_COUNT',
            'value' => null,
            'source' => 'unset',
        ])),
    ]);

    $result = settingsBotClient()->getSetting(new SettingRead('TWO_AUTOMOD_REPEAT_COUNT'));

    expect($result)->toBeInstanceOf(SettingReadResult::class)
        ->and($result->source)->toBe(SettingSource::Unset)
        ->and($result->value)->toBeNull();
});

it('writes a setting with the actor and one operation idempotency key', function () {
    Http::fake([
        SETTINGS_BOT_ENDPOINT => Http::response(settingSuccess([
            'key' => 'TWO_AUTOMOD_REPEAT_COUNT',
            'outcome' => 'saved',
        ])),
    ]);

    $idempotencyKey = Str::uuid()->toString();
    $result = settingsBotClient()->setSetting(
        new SettingMutation('TWO_AUTOMOD_REPEAT_COUNT', 4, '111111111111111111'),
        $idempotencyKey,
    );

    expect($result)->toBeInstanceOf(SettingWriteResult::class)
        ->and($result->key)->toBe('TWO_AUTOMOD_REPEAT_COUNT')
        ->and($result->outcome)->toBe(SettingWriteOutcome::Saved)
        ->and($result->replayed)->toBeFalse();

    Http::assertSent(fn (Request $request) => $request->body() === '{"action":"settings.set","key":"TWO_AUTOMOD_REPEAT_COUNT","value":4,"updated_by":"111111111111111111"}'
        && $request->header('Idempotency-Key') === [$idempotencyKey]);
});

it('sends null explicitly to restore the environment fallback', function () {
    Http::fake([
        SETTINGS_BOT_ENDPOINT => Http::response(settingSuccess([
            'key' => 'TWO_ONBOARDING_DRY_RUN',
            'outcome' => 'unset',
        ])),
    ]);

    $result = settingsBotClient()->setSetting(
        new SettingMutation('TWO_ONBOARDING_DRY_RUN', null, '111111111111111111'),
        Str::uuid()->toString(),
    );

    expect($result)->toBeInstanceOf(SettingWriteResult::class)
        ->and($result->outcome)->toBe(SettingWriteOutcome::Unset);

    Http::assertSent(function (Request $request) {
        $body = json_decode($request->body(), true);

        return array_key_exists('value', $body) && $body['value'] === null;
    });
});

it('reuses the operation key while refreshing the nonce for a retried write', function () {
    Http::fake([
        SETTINGS_BOT_ENDPOINT => Http::response(settingSuccess([
            'key' => 'TWO_AUTOMOD_MENTION_LIMIT',
            'outcome' => 'saved',
        ])),
    ]);

    $client = settingsBotClient();
    $operation = new SettingMutation('TWO_AUTOMOD_MENTION_LIMIT', 6, '111111111111111111');
    $idempotencyKey = Str::uuid()->toString();

    $client->setSetting($operation, $idempotencyKey);
    $client->setSetting($operation, $idempotencyKey);

    $sent = Http::recorded();

    expect($sent)->toHaveCount(2)
        ->and($sent[0][0]->header('Idempotency-Key')[0])->toBe($idempotencyKey)
        ->and($sent[1][0]->header('Idempotency-Key')[0])->toBe($idempotencyKey)
        ->and($sent[1][0]->header('X-TWO-Nonce')[0])->not->toBe($sent[0][0]->header('X-TWO-Nonce')[0]);
});

it('surfaces a replayed settings write', function () {
    Http::fake([
        SETTINGS_BOT_ENDPOINT => Http::response(
            settingSuccess(['key' => 'TWO_AUTOMOD_MENTION_LIMIT', 'outcome' => 'saved']),
            200,
            ['Idempotent-Replay' => 'true'],
        ),
    ]);

    $result = settingsBotClient()->setSetting(
        new SettingMutation('TWO_AUTOMOD_MENTION_LIMIT', 6, '111111111111111111'),
        Str::uuid()->toString(),
    );

    expect($result)->toBeInstanceOf(SettingWriteResult::class)
        ->and($result->replayed)->toBeTrue();
});

it('keeps bot refusals typed for settings calls', function () {
    Http::fake([
        SETTINGS_BOT_ENDPOINT => Http::response([
            'ok' => false,
            'error' => ['code' => 'action_not_allowed', 'message' => 'disabled', 'retryable' => false],
            'request_id' => '01JSETTINGDENIED',
        ], 403),
    ]);

    $result = settingsBotClient()->getSetting(new SettingRead('TWO_AUTOMOD_ENFORCE'));

    expect($result)->toBeInstanceOf(InternalActionFailure::class)
        ->and($result->code)->toBe('action_not_allowed')
        ->and($result->retryable)->toBeFalse()
        ->and($result->requestId)->toBe('01JSETTINGDENIED');
});

it('rejects a stored read with a null value', function () {
    Http::fake([
        SETTINGS_BOT_ENDPOINT => Http::response(settingSuccess([
            'key' => 'TWO_AUTOMOD_ENFORCE',
            'value' => null,
            'source' => 'store',
        ])),
    ]);

    expect(fn () => settingsBotClient()->getSetting(new SettingRead('TWO_AUTOMOD_ENFORCE')))
        ->toThrow(BotTransportException::class);
});

it('rejects success envelopes that name another setting', function () {
    Http::fake([
        SETTINGS_BOT_ENDPOINT => Http::response(settingSuccess([
            'key' => 'TWO_AUTOMOD_ENFORCE',
            'value' => true,
            'source' => 'store',
        ])),
    ]);

    expect(fn () => settingsBotClient()->getSetting(new SettingRead('TWO_ONBOARDING_DRY_RUN')))
        ->toThrow(BotTransportException::class);
});

it('rejects contradictory write outcomes', function () {
    Http::fake([
        SETTINGS_BOT_ENDPOINT => Http::response(settingSuccess([
            'key' => 'TWO_AUTOMOD_ENFORCE',
            'outcome' => 'unset',
        ])),
    ]);

    expect(fn () => settingsBotClient()->setSetting(
        new SettingMutation('TWO_AUTOMOD_ENFORCE', true, '111111111111111111'),
        Str::uuid()->toString(),
    ))->toThrow(BotTransportException::class);
});

it('validates setting names actor ids and encoded value size before sending', function () {
    Http::fake();

    expect(fn () => new SettingRead('two_automod'))
        ->toThrow(InvalidActionRequestException::class)
        ->and(fn () => new SettingMutation('TWO_AUTOMOD', true, 'not-a-snowflake'))
        ->toThrow(InvalidActionRequestException::class)
        ->and(fn () => new SettingMutation('TWO_AUTOMOD_BAD_WORDS', str_repeat('x', 8191), '111111111111111111'))
        ->toThrow(InvalidActionRequestException::class);

    Http::assertNothingSent();
});

it('never logs a setting value', function () {
    $handler = new TestHandler;
    Log::swap(new Logger(new MonologLogger('settings-test', [$handler])));

    Http::fake([
        SETTINGS_BOT_ENDPOINT => Http::response(settingSuccess([
            'key' => 'TWO_AUTOMOD_BAD_WORDS',
            'outcome' => 'saved',
        ])),
    ]);

    $job = new CallInternalAction(new SettingMutation(
        'TWO_AUTOMOD_BAD_WORDS',
        ['distinctive-private-policy-phrase'],
        '111111111111111111',
    ));
    $job->runInline(settingsBotClient());

    $written = collect($handler->getRecords())
        ->map(fn ($record) => $record->formatted ?: json_encode($record->context))
        ->implode("\n");

    expect($written)
        ->not->toContain('distinctive-private-policy-phrase')
        ->and($written)->not->toContain(SETTINGS_BOT_SECRET)
        ->and($written)->toContain('TWO_AUTOMOD_BAD_WORDS');
});

it('routes settings writes through the durable internal action job', function () {
    Http::fake([
        SETTINGS_BOT_ENDPOINT => Http::response(settingSuccess([
            'key' => 'TWO_AUTOMOD_REPEAT_COUNT',
            'outcome' => 'saved',
        ])),
    ]);

    $job = new CallInternalAction(
        new SettingMutation('TWO_AUTOMOD_REPEAT_COUNT', 4, '111111111111111111'),
    );
    $serialized = unserialize(serialize($job));

    expect($job->actionName())->toBe('settings.set')
        ->and($job->idempotencyKey)->not->toBeNull()
        ->and($serialized->idempotencyKey)->toBe($job->idempotencyKey);

    $job->handle(settingsBotClient());

    expect($job->lastResult)->toBeInstanceOf(SettingWriteResult::class)
        ->and($job->lastResult->outcome)->toBe(SettingWriteOutcome::Saved);
});
