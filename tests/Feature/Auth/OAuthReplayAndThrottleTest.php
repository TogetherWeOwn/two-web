<?php

use App\Models\User;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Log\Logger as LaravelLogger;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use SocialiteProviders\Discord\Provider as DiscordProvider;
use SocialiteProviders\Manager\Config as SocialiteConfig;

// TOG-6771: a reused or forged OAuth `state` must be rejected with no token
// exchange, no login and no leak — and the throttle envelope must stay a 429
// that reveals nothing about whether an account exists.
//
// Pass-as-proof: the guards already exist. The state guard is Socialite's
// hasInvalidState() check at the top of user() (base Two/AbstractProvider and
// the SocialiteProviders Manager override both throw InvalidStateException
// before touching the network — the check pulls `state` out of the session,
// so a replay emits zero HTTP calls). The controllers catch that throw
// (DiscordLoginController::callback, JoinController::callback) and show the
// member the same "expired" banner as any stale code, logging the exception
// class only. The throttle envelope is `throttle:10,1` on all four OAuth
// routes (routes/web.php); Laravel's ThrottleRequests keys the bucket by IP
// for guests, so the 11th hit 429s before any controller runs — the same body
// for an account that exists and one that does not.

beforeEach(function () {
    config([
        'services.discord.client_id' => 'test-client-id',
        'services.discord.client_secret' => 'test-client-secret',
        'services.discord.redirect' => 'http://localhost:8000/auth/discord/callback',
        'services.discord.guild_id' => '900000000000000001',
        'services.discord.moderator_role_ids' => ['900000000000000042'],
        'services.bot.url' => 'http://bot.internal:3001',
        'services.bot.secret' => 'test-shared-secret-that-is-long-enough-32',
        'services.bot.key_id' => 'web-test',
        'services.bot.timeout' => 2,
    ]);
});

/**
 * Bind a REAL Discord OAuth2 provider whose HTTP layer is a Guzzle mock, so
 * the tests exercise the genuine state check instead of a Mockery stub that
 * skips it.
 *
 * The provider is built lazily inside andReturnUsing: Socialite::driver() is
 * resolved by the controller mid-request, at which point request() is the
 * live test request carrying the session. Building it eagerly would capture
 * a stale request with no session.
 *
 * @param  array<int, Response>  $queued
 */
function bindRealDiscordDriver(array $queued, ArrayObject $history): void
{
    Socialite::shouldReceive('driver')->with('discord')->andReturnUsing(function () use ($queued, $history) {
        $mock = new MockHandler($queued);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $provider = new DiscordProvider(
            request(),
            'test-client-id',
            'test-client-secret',
            'http://localhost:8000/auth/discord/callback',
        );
        $provider->setConfig(new SocialiteConfig(
            'test-client-id',
            'test-client-secret',
            'http://localhost:8000/auth/discord/callback',
        ));
        $provider->setHttpClient(new GuzzleClient(['handler' => $stack]));

        return $provider;
    });
}

// ---------------------------------------------------------------------------
// (a) Replayed / forged state: rejected before any token exchange
// ---------------------------------------------------------------------------

it('rejects a login callback with no state in the session, before any HTTP call', function () {
    $history = new ArrayObject;
    bindRealDiscordDriver([], $history);
    Log::spy();

    $response = $this->get('/auth/discord/callback?code=replayed-code&state=attacker-state');

    $response->assertRedirect(route('home'));
    $response->assertSessionHas('auth_error', 'expired');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);

    // The state pull failed, so the code was never exchanged: zero HTTP calls.
    expect($history)->toHaveCount(0);

    Log::shouldHaveReceived('warning')->with('Discord token exchange failed.', [
        'exception' => InvalidStateException::class,
    ])->once();
});

it('rejects a join callback with no state in the session, before any HTTP call', function () {
    $history = new ArrayObject;
    bindRealDiscordDriver([], $history);

    $response = $this->get('/join/callback?code=replayed-code&state=attacker-state');

    $response->assertRedirect(route('join'));
    $response->assertSessionHas('join_result', 'expired');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
    expect($history)->toHaveCount(0);
});

it('rejects a login callback with a mismatched state, and consumes the state', function () {
    $history = new ArrayObject;
    bindRealDiscordDriver([], $history);

    $response = $this->withSession(['state' => 'the-real-state'])
        ->get('/auth/discord/callback?code=replayed-code&state=a-forged-state');

    $response->assertRedirect(route('home'));
    $response->assertSessionHas('auth_error', 'expired');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
    expect($history)->toHaveCount(0);

    // The stale state is consumed (pulled, not peeked), so a second replay of
    // the same URL fails the same way rather than resurrecting anything.
    $retry = $this->get('/auth/discord/callback?code=replayed-code&state=a-forged-state');
    $retry->assertRedirect(route('home'));
    $retry->assertSessionHas('auth_error', 'expired');
    $this->assertGuest();
});

it('never logs the token-exchange exception message on a state mismatch', function () {
    $history = new ArrayObject;
    bindRealDiscordDriver([], $history);

    // A TestHandler proves the *written* log carries no secret, not just that
    // the spy saw the right call — a message quoting the callback URL could
    // carry the code that becomes a token.
    $handler = new TestHandler;
    Log::swap(new LaravelLogger(new Monolog('testing', [$handler])));

    $response = $this->withSession(['state' => 'the-real-state'])
        ->get('/auth/discord/callback?code=secret-code-value&state=forged');

    $response->assertRedirect(route('home'));
    $response->assertSessionHas('auth_error', 'expired');
    $this->assertGuest();

    $written = collect($handler->getRecords())
        ->map(fn ($record) => (string) $record['formatted'])
        ->implode("\n");

    // Logs, flashed banner and redirect target carry no secret. The full
    // session dump is deliberately NOT asserted: Laravel's StartSession stores
    // the GET full URL as `_previous.url` server-side (overwritten on the next
    // request, never reflected in the response), so it echoes the query string
    // by framework design — not a token leak.
    expect($written)->not->toContain('secret-code-value')
        ->and((string) $response->headers->get('Location'))->not->toContain('secret-code-value')
        ->and(session('auth_error'))->toBe('expired')
        ->and($history)->toHaveCount(0);
});

it('leaves the user table untouched on a rejected replay', function () {
    $history = new ArrayObject;
    bindRealDiscordDriver([], $history);
    User::factory()->create();

    $response = $this->withSession(['state' => 'the-real-state'])
        ->get('/auth/discord/callback?code=replayed-code&state=forged');

    $response->assertRedirect(route('home'));
    $response->assertSessionHas('auth_error', 'expired');
    $this->assertGuest();
    expect(User::query()->count())->toBe(1)
        ->and($history)->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// (b) Throttle envelope: 429 without revealing whether an account exists
// ---------------------------------------------------------------------------

it('returns 429 on the login callback past the limit, revealing nothing about accounts', function () {
    User::factory()->create(['username' => 'uniquenamexyz']);
    $history = new ArrayObject;
    bindRealDiscordDriver([], $history);

    for ($i = 0; $i < 10; $i++) {
        $this->get('/auth/discord/callback?code=stale&state=wrong');
    }

    $throttled = $this->get('/auth/discord/callback?code=stale&state=wrong');

    $throttled->assertStatus(429);
    $throttled->assertHeader('Retry-After');
    $this->assertGuest();

    // The throttle throws before the controller runs, so the member's presence
    // cannot shape the response body.
    expect((string) $throttled->getContent())->not->toContain('uniquenamexyz');
});

it('returns 429 on the join callback past the limit without revealing anything', function () {
    $history = new ArrayObject;
    bindRealDiscordDriver([], $history);

    for ($i = 0; $i < 10; $i++) {
        $this->get('/join/callback?code=stale&state=wrong');
    }

    $throttled = $this->get('/join/callback?code=stale&state=wrong');

    $throttled->assertStatus(429);
    $throttled->assertHeader('Retry-After');
    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('returns 429 on the login redirect past the limit, before any Discord handoff', function () {
    $history = new ArrayObject;
    bindRealDiscordDriver([], $history);

    for ($i = 0; $i < 10; $i++) {
        $this->get(route('login'));
    }

    $throttled = $this->get(route('login'));

    $throttled->assertStatus(429);
    $throttled->assertHeader('Retry-After');

    // A throttled handoff must not leak the OAuth URL either.
    expect((string) $throttled->getContent())->not->toContain('discord.com');
});

it('carries throttle middleware on all four OAuth routes', function () {
    // The envelope only holds if it is registered. If a route loses its
    // `throttle:10,1` line this fails rather than shipping an unthrottled
    // callback — the behaviour tests above would still pass at 11 hits in
    // isolation but the production route would be open.
    foreach (['login', 'login.callback', 'join.redirect', 'join.callback'] as $name) {
        $route = app('router')->getRoutes()->getByName($name);

        expect($route)->not->toBeNull();

        $throttle = collect($route->gatherMiddleware())
            ->first(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'));

        expect($throttle)->toBe('throttle:10,1', "route {$name} must carry throttle:10,1");
    }
});
