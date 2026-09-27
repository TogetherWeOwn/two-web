<?php

use App\Services\Bot\EventCancel;
use App\Services\Bot\EventCancelOutcome;
use App\Services\Bot\EventCancelResult;
use App\Services\Bot\EventUpsert;
use App\Services\Bot\EventUpsertOutcome;
use App\Services\Bot\EventUpsertResult;
use App\Services\Bot\Exceptions\BotNotConfiguredException;
use App\Services\Bot\Exceptions\BotTransportException;
use App\Services\Bot\Exceptions\InvalidActionRequestException;
use App\Services\Bot\InternalActionClient;
use App\Services\Bot\InternalActionErrorCode;
use App\Services\Bot\InternalActionFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Logger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;

const BOT_URL = 'http://bot.internal:3001';
const BOT_SECRET = 'two-web-test-secret-at-least-32-characters';
const BOT_KEY_ID = 'web-test';
const BOT_ENDPOINT = 'http://bot.internal:3001/internal/actions';

function botClient(?string $url = BOT_URL, ?string $secret = BOT_SECRET, ?string $keyId = BOT_KEY_ID): InternalActionClient
{
    return new InternalActionClient($url, $secret, $keyId, 5);
}

function movieNight(): EventUpsert
{
    return new EventUpsert(
        eventKey: 'movie-night-2026-09-01',
        name: 'Movie Night',
        startsAt: Carbon::parse('2026-09-01T18:00:00Z'),
        endsAt: Carbon::parse('2026-09-01T20:30:00Z'),
        location: 'https://twitch.tv/togetherweown',
        description: 'Bring popcorn.',
    );
}

/** The bot's success envelope for `event.upsert` (docs/INTERNAL_ACTIONS.md §2). */
function botCreated(string $requestId = '01JABCDEF0123456789'): array
{
    return ['ok' => true, 'result' => ['outcome' => 'created', 'event_id' => '1234567890'], 'request_id' => $requestId];
}

/** The bot's failure envelope. `retryable` is authoritative and normally present. */
function botError(string $code, bool $retryable): array
{
    return [
        'ok' => false,
        'error' => ['code' => $code, 'message' => 'the bot said no', 'retryable' => $retryable],
        'request_id' => '01JERROR0123456789',
    ];
}

/** An event built from raw arguments, for the validation dataset. */
function eventFrom(string $key, string $name, string $starts, string $ends, string $location, ?string $description = null): EventUpsert
{
    return new EventUpsert($key, $name, Carbon::parse($starts), Carbon::parse($ends), $location, $description);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-01T09:00:00Z');
});

afterEach(function () {
    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// The happy path, and the bytes on the wire.
// ---------------------------------------------------------------------------

it('posts to the bot internal actions path', function () {
    Http::fake([BOT_ENDPOINT => Http::response(botCreated())]);

    botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    Http::assertSent(fn (Request $r) => $r->url() === BOT_ENDPOINT && $r->method() === 'POST');
});

it('tolerates a trailing slash on the configured endpoint url', function () {
    Http::fake([BOT_ENDPOINT => Http::response(botCreated())]);

    botClient(url: BOT_URL.'/')->upsertEvent(movieNight(), Str::uuid()->toString());

    Http::assertSent(fn (Request $r) => $r->url() === BOT_ENDPOINT);
});

it('sends exactly the json body it means to send', function () {
    // Pinned byte for byte. The body is what gets hashed into the signature, so
    // it is not free to drift: a reordered key or an escaped slash changes the
    // digest, and the bot answers `unauthorized` with no clue as to why.
    Http::fake([BOT_ENDPOINT => Http::response(botCreated())]);

    botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    Http::assertSent(fn (Request $r) => $r->body() === '{"action":"event.upsert","event_key":"movie-night-2026-09-01",'
        .'"name":"Movie Night","starts_at":"2026-09-01T18:00:00Z","ends_at":"2026-09-01T20:30:00Z",'
        .'"location":"https://twitch.tv/togetherweown","description":"Bring popcorn."}');
});

it('sends location and never channel_key', function () {
    // The trap this card exists to close. The bot's channel-key map starts empty
    // and nobody has set TWO_INTERNAL_CHANNEL_KEYS, so a `channel_key` build
    // passes every test we could write and then fails on first contact with a
    // non-retryable `action_not_allowed`. Sending exactly one of the two is a
    // property of the type here: EventUpsert has no channel_key to set.
    Http::fake([BOT_ENDPOINT => Http::response(botCreated())]);

    botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    Http::assertSent(function (Request $r) {
        $body = json_decode($r->body(), true);

        return array_key_exists('location', $body) && ! array_key_exists('channel_key', $body);
    });
});

it('omits description entirely when there is none', function () {
    // A null is not the same as an absent field to a validator that checks types.
    Http::fake([BOT_ENDPOINT => Http::response(botCreated())]);

    botClient()->upsertEvent(
        eventFrom('raid-night', 'Raid Night', '2026-09-02T18:00:00Z', '2026-09-02T21:00:00Z', 'The Lounge'),
        Str::uuid()->toString(),
    );

    Http::assertSent(fn (Request $r) => ! array_key_exists('description', json_decode($r->body(), true)));
});

it('sends instants in utc whatever timezone they were built in', function () {
    // TOG-52 does timezones properly once, and this is where. An offset the bot
    // has to reinterpret is a bug waiting for the clocks to change.
    Http::fake([BOT_ENDPOINT => Http::response(botCreated())]);

    $event = new EventUpsert(
        eventKey: 'london-social',
        name: 'London Social',
        startsAt: Carbon::parse('2026-09-01 19:00:00', 'Europe/London'),
        endsAt: Carbon::parse('2026-09-01 21:00:00', 'Europe/London'),
        location: 'The Lounge',
    );

    botClient()->upsertEvent($event, Str::uuid()->toString());

    Http::assertSent(function (Request $r) {
        $body = json_decode($r->body(), true);

        // 19:00 BST is 18:00 UTC.
        return $body['starts_at'] === '2026-09-01T18:00:00Z' && $body['ends_at'] === '2026-09-01T20:00:00Z';
    });
});

it('returns a typed result carrying the outcome, the discord event id and the request id', function () {
    Http::fake([BOT_ENDPOINT => Http::response(botCreated('01JHAPPY0123456789'))]);

    $result = botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    expect($result)->toBeInstanceOf(EventUpsertResult::class)
        ->and($result->outcome)->toBe(EventUpsertOutcome::Created)
        ->and($result->discordEventId)->toBe('1234567890')
        ->and($result->requestId)->toBe('01JHAPPY0123456789')
        ->and($result->replayed)->toBeFalse();
});

it('reads updated as a distinct outcome from created', function () {
    Http::fake([BOT_ENDPOINT => Http::response([
        'ok' => true,
        'result' => ['outcome' => 'updated', 'event_id' => '999'],
        'request_id' => '01JUPD',
    ])]);

    $result = botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    expect($result)->toBeInstanceOf(EventUpsertResult::class)
        ->and($result->outcome)->toBe(EventUpsertOutcome::Updated);
});

it('surfaces the idempotent-replay header so a retry can say already posted', function () {
    Http::fake([BOT_ENDPOINT => Http::response(botCreated(), 200, ['Idempotent-Replay' => 'true'])]);

    $result = botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    expect($result)->toBeInstanceOf(EventUpsertResult::class)
        ->and($result->replayed)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Signing: what was signed is what was sent.
// ---------------------------------------------------------------------------

it('signs the exact bytes it transmits', function () {
    // The failure this catches: signing one serialisation and letting the HTTP
    // client re-serialise another. Http::post($url, $array) re-encodes, and the
    // re-encoded bytes are not guaranteed to be the bytes that were hashed. So
    // this re-derives the signature from the *captured* body.
    Http::fake([BOT_ENDPOINT => Http::response(botCreated())]);

    botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    Http::assertSent(function (Request $r) {
        $canonical = implode("\n", [
            'POST',
            '/internal/actions',
            $r->header('X-TWO-Timestamp')[0],
            $r->header('X-TWO-Nonce')[0],
            hash('sha256', $r->body()),
        ]);

        return $r->header('X-TWO-Signature')[0] === 'sha256='.hash_hmac('sha256', $canonical, BOT_SECRET);
    });
});

it('sends a fresh 32-hex-character nonce and a unix-second timestamp', function () {
    Http::fake([BOT_ENDPOINT => Http::response(botCreated())]);

    botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    Http::assertSent(fn (Request $r) => preg_match('/^[0-9a-f]{32}$/', $r->header('X-TWO-Nonce')[0]) === 1
        // Seconds, not milliseconds: the skew window is ±120 seconds.
        && $r->header('X-TWO-Timestamp')[0] === (string) Carbon::now()->getTimestamp());
});

it('declares the body as json', function () {
    Http::fake([BOT_ENDPOINT => Http::response(botCreated())]);

    botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    Http::assertSent(fn (Request $r) => $r->header('Content-Type')[0] === 'application/json');
});

// ---------------------------------------------------------------------------
// The nonce / idempotency split — the reason this card exists.
// ---------------------------------------------------------------------------

it('reuses the idempotency key and refreshes the nonce across attempts at one operation', function () {
    // Getting this backwards breaks retries in a way no happy-path test catches:
    // reuse the nonce and the retry is rejected as `replayed`; refresh the
    // idempotency key and the retry creates a *second* Discord event.
    Http::fake([BOT_ENDPOINT => Http::response(botCreated())]);

    $client = botClient();
    $key = Str::uuid()->toString();

    $client->upsertEvent(movieNight(), $key);
    $client->upsertEvent(movieNight(), $key);

    $sent = Http::recorded();

    expect($sent)->toHaveCount(2);

    [$first, $second] = [$sent[0][0], $sent[1][0]];

    expect($first->header('Idempotency-Key')[0])->toBe($key)
        ->and($second->header('Idempotency-Key')[0])->toBe($key)
        ->and($second->header('X-TWO-Nonce')[0])->not->toBe($first->header('X-TWO-Nonce')[0]);
});

it('never reuses a nonce even across different operations', function () {
    Http::fake([BOT_ENDPOINT => Http::response(botCreated())]);

    $client = botClient();
    $client->upsertEvent(movieNight(), Str::uuid()->toString());
    $client->upsertEvent(movieNight(), Str::uuid()->toString());

    $sent = Http::recorded();

    expect($sent[1][0]->header('X-TWO-Nonce')[0])->not->toBe($sent[0][0]->header('X-TWO-Nonce')[0]);
});

it('mints a fresh idempotency key per operation', function () {
    // The helper exists so callers have one obvious way to get a key. Two calls
    // to it are two operations; a *retry* stores the first key and reuses it.
    expect(InternalActionClient::newIdempotencyKey())
        ->not->toBe(InternalActionClient::newIdempotencyKey());

    expect(Str::isUuid(InternalActionClient::newIdempotencyKey()))->toBeTrue();
});

it('refuses an idempotency key that is not a uuid, without calling the bot', function () {
    // A *needs key* action with a malformed key is a `malformed` from the bot.
    // Better to fail here, where the message can say which value was wrong.
    Http::fake();

    expect(fn () => botClient()->upsertEvent(movieNight(), 'not-a-uuid'))
        ->toThrow(InvalidActionRequestException::class);

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// The error table. All eleven codes, with `in_progress` and `replayed` sitting
// on the same 409 with opposite answers.
// ---------------------------------------------------------------------------

dataset('bot error codes', [
    'malformed' => [400, 'malformed', false],
    'unauthorized' => [401, 'unauthorized', false],
    'stale_request' => [401, 'stale_request', false],
    'action_not_allowed' => [403, 'action_not_allowed', false],
    'replayed' => [409, 'replayed', false],
    'in_progress' => [409, 'in_progress', true],
    'discord_rejected' => [422, 'discord_rejected', false],
    'rate_limited' => [429, 'rate_limited', true],
    'internal' => [500, 'internal', true],
    'discord_unavailable' => [502, 'discord_unavailable', true],
    'upstream_timeout' => [504, 'upstream_timeout', true],
]);

it('returns a typed failure with the right retryable answer', function (int $status, string $code, bool $retryable) {
    Http::fake([BOT_ENDPOINT => Http::response(botError($code, $retryable), $status)]);

    $failure = botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    expect($failure)->toBeInstanceOf(InternalActionFailure::class)
        ->and($failure->code)->toBe($code)
        ->and($failure->retryable)->toBe($retryable)
        ->and($failure->status)->toBe($status)
        ->and($failure->message)->toBe('the bot said no')
        ->and($failure->requestId)->toBe('01JERROR0123456789')
        ->and($failure->knownCode())->toBe(InternalActionErrorCode::from($code));
})->with('bot error codes');

it('knows the documented retryable answer for itself when the bot omits the flag', function (int $status, string $code, bool $retryable) {
    // `retryable` is authoritative when it is there. When it is not — an older
    // bot, a proxy that mangled the body — fall back to the published table
    // rather than guessing from the status code, because the status code gets
    // 409 wrong in both directions.
    Http::fake([BOT_ENDPOINT => Http::response([
        'ok' => false,
        'error' => ['code' => $code, 'message' => 'the bot said no'],
        'request_id' => '01JERROR0123456789',
    ], $status)]);

    $failure = botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    expect($failure)->toBeInstanceOf(InternalActionFailure::class)
        ->and($failure->retryable)->toBe($retryable)
        ->and(InternalActionErrorCode::from($code)->isRetryable())->toBe($retryable);
})->with('bot error codes');

it('does not decide retryability from the status code', function () {
    // Both of these are 409. One is safe to retry and one never becomes anything
    // else. This is the assertion that catches a status-code implementation, so
    // it is spelled out rather than left implicit in the dataset above.
    Http::fakeSequence()
        ->push(botError('in_progress', true), 409)
        ->push(botError('replayed', false), 409);

    $client = botClient();

    $inProgress = $client->upsertEvent(movieNight(), Str::uuid()->toString());
    $replayed = $client->upsertEvent(movieNight(), Str::uuid()->toString());

    expect($inProgress)->toBeInstanceOf(InternalActionFailure::class)
        ->and($inProgress->retryable)->toBeTrue()
        ->and($replayed)->toBeInstanceOf(InternalActionFailure::class)
        ->and($replayed->retryable)->toBeFalse();
});

it('honours the retryable flag the bot actually sent over the published table', function () {
    // The doc calls `retryable` authoritative. If the running bot ever
    // contradicts its own markdown, we do what the running bot says.
    Http::fake([BOT_ENDPOINT => Http::response(botError('replayed', true), 409)]);

    $failure = botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    expect($failure)->toBeInstanceOf(InternalActionFailure::class)
        ->and($failure->retryable)->toBeTrue()
        ->and($failure->knownCode())->toBe(InternalActionErrorCode::Replayed);
});

it('treats an error code it has never heard of as not retryable', function () {
    // The error table grows: `in_progress` arrived in v0.3. An unknown code must
    // not crash us, and must not become a retry loop against a bot that has
    // already told us no.
    Http::fake([BOT_ENDPOINT => Http::response([
        'ok' => false,
        'error' => ['code' => 'something_new', 'message' => 'from a future bot'],
        'request_id' => '01JNEW',
    ], 418)]);

    $failure = botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    expect($failure)->toBeInstanceOf(InternalActionFailure::class)
        ->and($failure->code)->toBe('something_new')
        ->and($failure->knownCode())->toBeNull()
        ->and($failure->retryable)->toBeFalse();
});

it('has an enum case for every code in the published table and no others', function () {
    // If the bot adds a code and somebody adds the enum case without adding it to
    // the dataset above, the retryable table silently goes untested for it.
    expect(array_map(fn (InternalActionErrorCode $c) => $c->value, InternalActionErrorCode::cases()))
        ->toEqualCanonicalizing([
            'malformed', 'unauthorized', 'stale_request', 'action_not_allowed', 'replayed',
            'in_progress', 'discord_rejected', 'rate_limited', 'internal', 'discord_unavailable',
            'upstream_timeout',
        ])
        ->toHaveCount(11);
});

// ---------------------------------------------------------------------------
// Rate limiting.
// ---------------------------------------------------------------------------

it('surfaces retry-after in seconds on a 429', function () {
    Http::fake([BOT_ENDPOINT => Http::response(botError('rate_limited', true), 429, ['Retry-After' => '42'])]);

    $failure = botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    expect($failure)->toBeInstanceOf(InternalActionFailure::class)
        ->and($failure->retryAfterSeconds)->toBe(42)
        ->and($failure->retryable)->toBeTrue();
});

it('reports no retry-after when the bot did not send one', function () {
    Http::fake([BOT_ENDPOINT => Http::response(botError('rate_limited', true), 429)]);

    $failure = botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    expect($failure)->toBeInstanceOf(InternalActionFailure::class)
        ->and($failure->retryAfterSeconds)->toBeNull();
});

it('ignores a retry-after that is not a count of seconds', function () {
    // The doc says seconds. An HTTP-date is legal per RFC but is not what this
    // endpoint sends, and half-parsing one would produce a nonsense backoff.
    Http::fake([BOT_ENDPOINT => Http::response(botError('rate_limited', true), 429, [
        'Retry-After' => 'Wed, 01 Sep 2026 09:02:00 GMT',
    ])]);

    $failure = botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    expect($failure)->toBeInstanceOf(InternalActionFailure::class)
        ->and($failure->retryAfterSeconds)->toBeNull();
});

it('leaves retry-after null on failures that are not rate limits', function () {
    Http::fake([BOT_ENDPOINT => Http::response(botError('internal', true), 500, ['Retry-After' => '9'])]);

    $failure = botClient()->upsertEvent(movieNight(), Str::uuid()->toString());

    expect($failure)->toBeInstanceOf(InternalActionFailure::class)
        ->and($failure->retryAfterSeconds)->toBeNull();
});

// ---------------------------------------------------------------------------
// Not configured. A distinct, catchable exception — TOG-52c turns this into a
// terminal skip, and it cannot do that if it looks like a transport error.
// ---------------------------------------------------------------------------

it('refuses to sign with an empty shared secret and makes no http call', function () {
    Http::fake();

    expect(fn () => botClient(secret: '')->upsertEvent(movieNight(), Str::uuid()->toString()))
        ->toThrow(BotNotConfiguredException::class);

    Http::assertNothingSent();
});

it('refuses when the shared secret is absent entirely', function () {
    Http::fake();

    expect(fn () => botClient(secret: null)->upsertEvent(movieNight(), Str::uuid()->toString()))
        ->toThrow(BotNotConfiguredException::class);

    Http::assertNothingSent();
});

it('refuses when the endpoint url or the key id is missing', function () {
    // Both produce a guaranteed `unauthorized` on the wire. Failing here instead
    // means the message names the variable rather than the symptom.
    Http::fake();

    expect(fn () => botClient(url: '')->upsertEvent(movieNight(), Str::uuid()->toString()))
        ->toThrow(BotNotConfiguredException::class);
    expect(fn () => botClient(keyId: '')->upsertEvent(movieNight(), Str::uuid()->toString()))
        ->toThrow(BotNotConfiguredException::class);

    Http::assertNothingSent();
});

it('names the missing variable without quoting any value', function () {
    try {
        botClient(secret: '')->upsertEvent(movieNight(), Str::uuid()->toString());
    } catch (BotNotConfiguredException $e) {
        expect($e->getMessage())->toContain('BOT_SHARED_SECRET');

        return;
    }

    $this->fail('Expected a BotNotConfiguredException.');
});

it('keeps not-configured and transport failures as separate types', function () {
    // TOG-52c branches on these: one is a terminal skip, the other is a retry.
    expect(is_a(BotNotConfiguredException::class, BotTransportException::class, true))->toBeFalse()
        ->and(is_a(BotTransportException::class, BotNotConfiguredException::class, true))->toBeFalse();
});

// ---------------------------------------------------------------------------
// The bot did not answer, or answered something that is not the contract.
// ---------------------------------------------------------------------------

it('throws a transport exception when the bot cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(fn () => botClient()->upsertEvent(movieNight(), Str::uuid()->toString()))
        ->toThrow(BotTransportException::class);
});

it('throws a transport exception when the answer is not the documented envelope', function () {
    // A reverse proxy error page, say. There is no request_id to log and no
    // retryable flag to trust, so this cannot be an InternalActionFailure.
    Http::fake([BOT_ENDPOINT => Http::response('<html>502 Bad Gateway</html>', 502)]);

    expect(fn () => botClient()->upsertEvent(movieNight(), Str::uuid()->toString()))
        ->toThrow(BotTransportException::class);
});

it('throws a transport exception when a success is missing the discord event id', function () {
    Http::fake([BOT_ENDPOINT => Http::response(['ok' => true, 'result' => ['outcome' => 'created'], 'request_id' => '01J'])]);

    expect(fn () => botClient()->upsertEvent(movieNight(), Str::uuid()->toString()))
        ->toThrow(BotTransportException::class);
});

it('throws a transport exception on an outcome it does not recognise', function () {
    Http::fake([BOT_ENDPOINT => Http::response([
        'ok' => true,
        'result' => ['outcome' => 'teleported', 'event_id' => '1'],
        'request_id' => '01J',
    ])]);

    expect(fn () => botClient()->upsertEvent(movieNight(), Str::uuid()->toString()))
        ->toThrow(BotTransportException::class);
});

it('throws a transport exception when a failure carries no error code', function () {
    Http::fake([BOT_ENDPOINT => Http::response(['ok' => false, 'request_id' => '01J'], 500)]);

    expect(fn () => botClient()->upsertEvent(movieNight(), Str::uuid()->toString()))
        ->toThrow(BotTransportException::class);
});

// ---------------------------------------------------------------------------
// Validation before the wire. Our own error, not a round trip.
// ---------------------------------------------------------------------------

it('rejects an event that breaks the documented limits, before sending anything', function (array $args) {
    Http::fake();

    expect(fn () => eventFrom(...$args))->toThrow(InvalidActionRequestException::class);

    Http::assertNothingSent();
})->with([
    'name over 100 characters' => [['k', str_repeat('a', 101), '2026-09-01T18:00:00Z', '2026-09-01T19:00:00Z', 'here']],
    'description over 1000 characters' => [['k', 'Name', '2026-09-01T18:00:00Z', '2026-09-01T19:00:00Z', 'here', str_repeat('a', 1001)]],
    'ends_at before starts_at' => [['k', 'Name', '2026-09-01T19:00:00Z', '2026-09-01T18:00:00Z', 'here']],
    'ends_at equal to starts_at' => [['k', 'Name', '2026-09-01T18:00:00Z', '2026-09-01T18:00:00Z', 'here']],
    'blank name' => [['k', '   ', '2026-09-01T18:00:00Z', '2026-09-01T19:00:00Z', 'here']],
    'blank event key' => [['', 'Name', '2026-09-01T18:00:00Z', '2026-09-01T19:00:00Z', 'here']],
    'blank location' => [['k', 'Name', '2026-09-01T18:00:00Z', '2026-09-01T19:00:00Z', '   ']],
]);

it('accepts a name and a description exactly on the limit', function () {
    Http::fake([BOT_ENDPOINT => Http::response(botCreated())]);

    $event = eventFrom('k', str_repeat('a', 100), '2026-09-01T18:00:00Z', '2026-09-01T19:00:00Z', 'here', str_repeat('b', 1000));

    expect(botClient()->upsertEvent($event, Str::uuid()->toString()))->toBeInstanceOf(EventUpsertResult::class);
});

it('counts characters and not bytes against the limits', function () {
    // Discord's ceiling is characters. Counting bytes would refuse a legitimate
    // 100-character name with an accent in it, and our own error would be wrong.
    Http::fake([BOT_ENDPOINT => Http::response(botCreated())]);

    $event = eventFrom('k', str_repeat('é', 100), '2026-09-01T18:00:00Z', '2026-09-01T19:00:00Z', 'here');

    expect(botClient()->upsertEvent($event, Str::uuid()->toString()))->toBeInstanceOf(EventUpsertResult::class);
});

// ---------------------------------------------------------------------------
// Logging: the join key in, the secrets out.
// ---------------------------------------------------------------------------

it('logs the request id on success and on failure', function () {
    // request_id is the join key between our logs and the bot's. Without it, a
    // report of "the announcement did not post" has nothing to join on.
    $handler = new TestHandler;
    Log::swap(new Logger(new Monolog\Logger('testing', [$handler])));

    Http::fakeSequence()
        ->push(botCreated('01JSUCCESS'), 200)
        ->push(botError('discord_rejected', false), 422);

    $client = botClient();
    $client->upsertEvent(movieNight(), Str::uuid()->toString());
    $client->upsertEvent(movieNight(), Str::uuid()->toString());

    $logged = json_encode(array_map(fn ($r) => [$r->message, $r->context], $handler->getRecords()));

    expect($logged)->toContain('01JSUCCESS')
        ->and($logged)->toContain('01JERROR0123456789')
        ->and($logged)->toContain('event.upsert')
        ->and($logged)->toContain('discord_rejected');
});

it('never logs the shared secret, the signature or the canonical string', function () {
    $handler = new TestHandler;
    Log::swap(new Logger(new Monolog\Logger('testing', [$handler])));

    Http::fakeSequence()
        ->push(botCreated(), 200)
        ->push(botError('unauthorized', false), 401);

    $client = botClient();
    $client->upsertEvent(movieNight(), Str::uuid()->toString());
    $client->upsertEvent(movieNight(), Str::uuid()->toString());

    $signature = Http::recorded()[0][0]->header('X-TWO-Signature')[0];

    $logged = json_encode(array_map(fn ($r) => [$r->message, $r->context], $handler->getRecords()));

    expect($handler->getRecords())->not->toBeEmpty()
        ->and($logged)->not->toContain(BOT_SECRET)
        ->and($logged)->not->toContain($signature)
        // No fragment of a canonical string either.
        ->and($logged)->not->toContain('sha256=');
});

// ---------------------------------------------------------------------------
// event.cancel. The action that closes TOG-5863: a cancelled event must leave
// Discord through this, never through event.upsert.
// ---------------------------------------------------------------------------

/** The bot's success envelope for `event.cancel` (docs/INTERNAL_ACTIONS.md §3). */
function botCancelled(string $requestId = '01JCANCEL0123456789'): array
{
    return ['ok' => true, 'result' => ['outcome' => 'cancelled', 'event_id' => '1234567890'], 'request_id' => $requestId];
}

it('sends exactly the cancel json body it means to send', function () {
    // Pinned byte for byte, like the upsert: the body is what gets hashed into
    // the signature. And note what is absent — no name, no times, no location.
    // A cancelled event that carries live-event fields is the bug (TOG-5863).
    Http::fake([BOT_ENDPOINT => Http::response(botCancelled())]);

    botClient()->cancelEvent(new EventCancel('movie-night-2026-09-01'), Str::uuid()->toString());

    Http::assertSent(fn (Request $r) => $r->body() === '{"action":"event.cancel","event_key":"movie-night-2026-09-01"}');
});

it('returns a typed cancel result carrying the outcome, the discord event id and the request id', function () {
    Http::fake([BOT_ENDPOINT => Http::response(botCancelled('01JHAPPY0123456789'))]);

    $result = botClient()->cancelEvent(new EventCancel('movie-night-2026-09-01'), Str::uuid()->toString());

    expect($result)->toBeInstanceOf(EventCancelResult::class)
        ->and($result->outcome)->toBe(EventCancelOutcome::Cancelled)
        ->and($result->discordEventId)->toBe('1234567890')
        ->and($result->requestId)->toBe('01JHAPPY0123456789')
        ->and($result->replayed)->toBeFalse();
});

it('rejects a cancel with a blank event key, before sending anything', function () {
    Http::fake();

    expect(fn () => new EventCancel('   '))
        ->toThrow(InvalidActionRequestException::class);

    Http::assertNothingSent();
});

it('throws a transport exception on a cancel outcome it does not recognise', function () {
    Http::fake([BOT_ENDPOINT => Http::response([
        'ok' => true,
        'result' => ['outcome' => 'resurrected', 'event_id' => '1'],
        'request_id' => '01J',
    ])]);

    expect(fn () => botClient()->cancelEvent(new EventCancel('k'), Str::uuid()->toString()))
        ->toThrow(BotTransportException::class);
});

it('throws a transport exception when a cancel success is missing the discord event id', function () {
    Http::fake([BOT_ENDPOINT => Http::response(['ok' => true, 'result' => ['outcome' => 'cancelled'], 'request_id' => '01J'])]);

    expect(fn () => botClient()->cancelEvent(new EventCancel('k'), Str::uuid()->toString()))
        ->toThrow(BotTransportException::class);
});

it('refuses a cancel idempotency key that is not a uuid, without calling the bot', function () {
    Http::fake();

    expect(fn () => botClient()->cancelEvent(new EventCancel('k'), 'not-a-uuid'))
        ->toThrow(InvalidActionRequestException::class);

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// The container wiring. Config in one place, and the class resolvable.
// ---------------------------------------------------------------------------

it('resolves from the container against the bot config', function () {
    config()->set('services.bot.url', 'http://configured.internal:9999');
    config()->set('services.bot.secret', BOT_SECRET);
    config()->set('services.bot.key_id', 'configured-key');

    Http::fake(['http://configured.internal:9999/internal/actions' => Http::response(botCreated())]);

    app(InternalActionClient::class)->upsertEvent(movieNight(), Str::uuid()->toString());

    Http::assertSent(fn (Request $r) => $r->url() === 'http://configured.internal:9999/internal/actions'
        && $r->header('X-TWO-Key-Id')[0] === 'configured-key');
});
