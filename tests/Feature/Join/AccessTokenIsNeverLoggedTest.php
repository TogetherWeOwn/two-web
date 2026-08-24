<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;

/**
 * A member's OAuth access token must appear in no log line, ever.
 *
 * It is a live credential: anyone holding it can act as that person against
 * every scope they approved. It exists for the length of one request on two
 * hops and is written down nowhere — not the session, not the `jobs` table
 * (which is why this call is synchronous rather than queued), and not the log.
 *
 * The bot enforces the same promise on its side with the same three cases. This
 * is the website's half. Conventions rot; a failing test does not.
 */
const LOGGED_TOKEN = 'super-secret-member-access-token';
const LOGGED_BOT_ACTIONS = 'http://127.0.0.1:3001/internal/actions';

beforeEach(function () {
    config([
        'services.discord.client_id' => 'test-client-id',
        'services.discord.client_secret' => 'test-client-secret',
        'services.discord.invite_url' => 'https://discord.gg/testinvite',
        'services.bot.url' => 'http://127.0.0.1:3001',
        'services.bot.secret' => 'test-shared-secret-that-is-long-enough-32',
        'services.bot.key_id' => 'web-test',
        'services.bot.add_member_timeout' => 2,
    ]);

    $user = new SocialiteUser;
    $user->setRaw(['id' => '111222333444555666'])->map(['id' => '111222333444555666', 'nickname' => 'wren']);
    $user->token = LOGGED_TOKEN;

    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn($user);

    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);
});

/** Swap in a real Monolog handler, so this reads what was actually written. */
function captureLog(): TestHandler
{
    $handler = new TestHandler;

    // A real handler rather than a spy on the facade: the token could survive
    // into a context array, a formatted message, or an exception attached to the
    // record, and only the written record catches all three.
    Log::swap(new LaravelLogger(new Monolog('testing', [$handler])));

    return $handler;
}

/** Everything written, message and context together, as one searchable string. */
function everythingLogged(TestHandler $handler): string
{
    // `formatted` is the line the handler actually produced — Monolog's default
    // LineFormatter appends the context and extra arrays to it. Reading that
    // rather than just the message is the point: a token that leaked into a
    // context key would still reach a log file, and asserting on the message
    // alone would sail straight past it.
    return collect($handler->getRecords())
        ->map(fn ($record) => (string) $record['formatted'])
        ->implode("\n");
}

it('keeps the token out of the log when the join succeeds', function () {
    $handler = captureLog();

    Http::fake([LOGGED_BOT_ACTIONS => Http::response([
        'ok' => true,
        'result' => ['outcome' => 'added'],
        'request_id' => '01JTESTREQUESTID',
    ], 200)]);

    test()->get('/join/callback?code=valid-code')->assertSessionHas('join_result', 'added');

    // The success path logs an outcome and a request id, which is what makes an
    // incident joinable across the two services. Neither is the token.
    expect(everythingLogged($handler))
        ->toContain('01JTESTREQUESTID')
        ->not->toContain(LOGGED_TOKEN);
});

it('keeps the token out of the log when the bot refuses', function () {
    $handler = captureLog();

    Http::fake([LOGGED_BOT_ACTIONS => Http::response([
        'ok' => false,
        'error' => ['code' => 'action_not_allowed', 'message' => 'nope', 'retryable' => false],
        'request_id' => '01JTESTREQUESTID',
    ], 403)]);

    test()->get('/join/callback?code=valid-code')->assertSessionHas('join_result', 'unavailable');

    expect(everythingLogged($handler))
        ->toContain('action_not_allowed')
        ->not->toContain(LOGGED_TOKEN);
});

it('keeps the token out of the log even when the exception itself quotes it', function () {
    // The hard case, and the reason the client redacts rather than merely
    // declining to log. An exception message is written by somebody else's
    // library and can quote whatever it likes, including the request it failed
    // to send.
    $handler = captureLog();

    Http::fake(fn () => throw new ConnectionException(
        'cURL error 28: Operation timed out sending {"access_token":"'.LOGGED_TOKEN.'"}'
    ));

    test()->get('/join/callback?code=valid-code')->assertSessionHas('join_result', 'unavailable');

    expect(everythingLogged($handler))
        ->toContain('[redacted]')
        ->not->toContain(LOGGED_TOKEN);
});

it('keeps the token out of the session, where it would outlive the request', function () {
    Http::fake([LOGGED_BOT_ACTIONS => Http::response([
        'ok' => true,
        'result' => ['outcome' => 'added'],
        'request_id' => '01JTESTREQUESTID',
    ], 200)]);

    test()->get('/join/callback?code=valid-code');

    $session = session()->all();

    // Assert we are looking at the right session before asserting an absence —
    // otherwise this passes just as happily against an empty array.
    expect($session)->toHaveKey('join_result');

    // The redirect carries an outcome string and nothing else. A token flashed to
    // the session would be written to the session store and survive the request
    // that was supposed to be its whole life.
    expect(json_encode($session))->not->toContain(LOGGED_TOKEN);
});
