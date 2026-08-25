<?php

use App\Services\Bot\InternalAction;
use App\Services\Bot\InternalActionClient;
use App\Services\Bot\InternalActionMisconfigured;
use App\Services\Bot\InternalActionSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * The client against a faked bot.
 *
 * This lives in Unit rather than Feature deliberately. Feature carries
 * RefreshDatabase (see tests/Pest.php) and would demand a Postgres for tests
 * that never touch one — and these are the tests most worth being able to run
 * on a laptop with nothing else installed, because they are what stands between
 * us and a silent 401 on staging.
 */
beforeEach(function () {
    config([
        'services.bot.url' => 'http://127.0.0.1:3001',
        'services.bot.key_id' => 'web-testing',
        'services.bot.secret' => 'test-secret-do-not-use',
        'services.bot.timeout' => 5,
    ]);

    Carbon::setTestNow(Carbon::createFromTimestamp(1787173135));
});

afterEach(function () {
    Carbon::setTestNow();
});

function okResponse(array $result = ['outcome' => 'assigned'], array $headers = [])
{
    return Http::response(['ok' => true, 'result' => $result, 'request_id' => '01JREQ'], 200, $headers);
}

it('signs the exact bytes it puts on the wire', function () {
    Http::fake(['*' => okResponse()]);

    $action = InternalAction::roleAssign('900000000000009999', 'rocketleague');
    (new InternalActionClient)->send($action);

    Http::assertSent(function (Request $request) {
        $raw = $request->body();

        // The check that matters: recompute the signature over the bytes that
        // were actually sent. If anything re-serialised the payload between
        // signing and sending — the §1 mistake — these disagree.
        $expected = InternalActionSigner::sign(
            'test-secret-do-not-use',
            $request->header('X-TWO-Timestamp')[0],
            $request->header('X-TWO-Nonce')[0],
            $raw,
        );

        expect($request->header('X-TWO-Signature')[0])->toBe($expected);
        expect($raw)->toBe('{"action":"role.assign","discord_id":"900000000000009999","role_key":"rocketleague"}');

        return true;
    });
});

it('sends every header the endpoint requires', function () {
    Http::fake(['*' => okResponse()]);

    (new InternalActionClient)->send(InternalAction::roleAssign('900000000000009999', 'member'));

    Http::assertSent(function (Request $request) {
        expect($request->method())->toBe('POST');
        expect($request->url())->toBe('http://127.0.0.1:3001/internal/actions');
        expect($request->header('X-TWO-Key-Id')[0])->toBe('web-testing');
        expect($request->header('X-TWO-Timestamp')[0])->toBe('1787173135');
        expect($request->header('X-TWO-Nonce')[0])->toMatch('/^[0-9a-f]{32}$/');
        expect($request->header('X-TWO-Signature')[0])->toMatch('/^sha256=[0-9a-f]{64}$/');
        expect($request->header('Content-Type')[0])->toContain('application/json');

        // role.assign is naturally idempotent, so no key is sent (§3).
        expect($request->hasHeader('Idempotency-Key'))->toBeFalse();

        return true;
    });
});

it('appends the signed path to a base URL and leaves a full endpoint alone', function () {
    Http::fake(['*' => okResponse()]);
    $action = InternalAction::roleAssign('900000000000009999', 'member');

    config(['services.bot.url' => 'http://127.0.0.1:3001/']);
    (new InternalActionClient)->send($action);

    // Somebody will paste the full endpoint out of the bot's docs. That must
    // not produce /internal/actions/internal/actions.
    config(['services.bot.url' => 'http://127.0.0.1:3001/internal/actions']);
    (new InternalActionClient)->send($action);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => $r->url() === 'http://127.0.0.1:3001/internal/actions');
});

it('sends a fresh nonce on every attempt while the idempotency key holds', function () {
    Http::fake(['*' => okResponse(['outcome' => 'posted', 'message_id' => '42'])]);

    $client = new InternalActionClient;
    $action = InternalAction::announcementPost('announcements', 'Hello');

    $client->send($action, 'key-held-across-retries');
    $client->send($action, 'key-held-across-retries');

    $nonces = [];
    Http::assertSent(function (Request $request) use (&$nonces) {
        $nonces[] = $request->header('X-TWO-Nonce')[0];
        expect($request->header('Idempotency-Key')[0])->toBe('key-held-across-retries');

        return true;
    });

    // §1 calls this "the one that will bite you": same key, new nonce. Reusing
    // the nonce would be a 409 replayed that never becomes anything else.
    expect($nonces)->toHaveCount(2);
    expect($nonces[0])->not->toBe($nonces[1]);
});

it('refuses to send a needs-key action without a key', function () {
    Http::fake(['*' => okResponse()]);

    expect(fn () => (new InternalActionClient)->send(InternalAction::announcementPost('announcements', 'Hi')))
        ->toThrow(InvalidArgumentException::class, 'requires an Idempotency-Key');

    // A malformed round trip is worse than useless: it consumes the rate limit
    // and returns a code that suggests the body was wrong.
    Http::assertNothingSent();
});

it('refuses to send when the key id or secret is missing', function () {
    Http::fake(['*' => okResponse()]);
    $action = InternalAction::roleAssign('900000000000009999', 'member');

    config(['services.bot.key_id' => null]);
    expect(fn () => (new InternalActionClient)->send($action))
        ->toThrow(InternalActionMisconfigured::class, 'services.bot.key_id');

    config(['services.bot.key_id' => 'web-testing', 'services.bot.secret' => '']);
    expect(fn () => (new InternalActionClient)->send($action))
        ->toThrow(InternalActionMisconfigured::class, 'services.bot.secret');

    Http::assertNothingSent();
});

it('reads a success and its result', function () {
    Http::fake(['*' => okResponse(['outcome' => 'posted', 'message_id' => '999'])]);

    $result = (new InternalActionClient)->send(InternalAction::announcementPost('announcements', 'Hi'), 'k1');

    expect($result->ok)->toBeTrue();
    expect($result->status)->toBe(200);
    expect($result->result)->toBe(['outcome' => 'posted', 'message_id' => '999']);
    expect($result->requestId)->toBe('01JREQ');
    expect($result->replayed)->toBeFalse();
    expect($result->retryable)->toBeFalse();
});

it('notices Idempotent-Replay on a 200', function () {
    Http::fake(['*' => okResponse(['outcome' => 'posted', 'message_id' => '999'], ['Idempotent-Replay' => 'true'])]);

    $result = (new InternalActionClient)->send(InternalAction::announcementPost('announcements', 'Hi'), 'k1');

    // §2: still a success, and the result is byte-identical to the original
    // attempt's. The header is for logging and for saying "already posted".
    expect($result->ok)->toBeTrue();
    expect($result->replayed)->toBeTrue();
    expect($result->result['message_id'])->toBe('999');
});

it('branches on error.retryable and not on the status code', function (int $status, string $code, bool $retryable) {
    Http::fake(['*' => Http::response([
        'ok' => false,
        'error' => ['code' => $code, 'message' => 'irrelevant prose', 'retryable' => $retryable],
        'request_id' => '01JERR',
    ], $status)]);

    $result = (new InternalActionClient)->send(InternalAction::roleAssign('900000000000009999', 'member'));

    expect($result->ok)->toBeFalse();
    expect($result->status)->toBe($status);
    expect($result->errorCode)->toBe($code);
    expect($result->retryable)->toBe($retryable);
    expect($result->requestId)->toBe('01JERR');
})->with([
    // The pair that proves the point: same status, opposite answers. Nothing
    // but the boolean separates them.
    '409 replayed is final' => [409, 'replayed', false],
    '409 in_progress is retryable' => [409, 'in_progress', true],
    '400 malformed' => [400, 'malformed', false],
    '401 unauthorized' => [401, 'unauthorized', false],
    '401 stale_request' => [401, 'stale_request', false],
    '403 action_not_allowed' => [403, 'action_not_allowed', false],
    '422 discord_rejected' => [422, 'discord_rejected', false],
    '429 rate_limited' => [429, 'rate_limited', true],
    '500 internal' => [500, 'internal', true],
    '502 discord_unavailable' => [502, 'discord_unavailable', true],
    '504 upstream_timeout' => [504, 'upstream_timeout', true],
]);

it('honours Retry-After on a rate limit', function () {
    Http::fake(['*' => Http::response([
        'ok' => false,
        'error' => ['code' => 'rate_limited', 'message' => 'slow down', 'retryable' => true],
        'request_id' => '01JRL',
    ], 429, ['Retry-After' => '42'])]);

    $result = (new InternalActionClient)->send(InternalAction::roleAssign('900000000000009999', 'member'));

    expect($result->retryable)->toBeTrue();
    expect($result->retryAfter)->toBe(42);
});

it('falls back to the status when the answer did not come from the bot', function (int $status, bool $retryable) {
    // A reverse proxy error page carries no error.retryable to obey, so the
    // status is all there is. Conservative on anything unrecognised: a retry
    // loop against an endpoint that is answering clearly is worse than one
    // failed job.
    Http::fake(['*' => Http::response("<html>{$status}</html>", $status)]);

    $result = (new InternalActionClient)->send(InternalAction::roleAssign('900000000000009999', 'member'));

    expect($result->ok)->toBeFalse();
    expect($result->errorCode)->toBe('unparseable_response');
    expect($result->retryable)->toBe($retryable);
})->with([
    'a gateway is restarting' => [502, true],
    'the rate limiter answered' => [429, true],
    'the route is wrong' => [404, false],
    'a proxy rejected it' => [403, false],
]);

it('treats a connection failure as retryable', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $result = (new InternalActionClient)->send(InternalAction::roleAssign('900000000000009999', 'member'));

    expect($result->ok)->toBeFalse();
    expect($result->status)->toBe(0);
    expect($result->errorCode)->toBe('transport_failure');
    // The bot is on the private network and is most likely restarting. Every
    // action on this path is safe to re-send — the two needs-key ones because
    // of the key, role.assign because it is naturally idempotent.
    expect($result->retryable)->toBeTrue();
});
