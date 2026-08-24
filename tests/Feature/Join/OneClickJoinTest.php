<?php

use App\Services\Bot\BotClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;

// One-click join, end to end from Discord's callback (TOG-80). Every test here is
// a rule a visitor would notice breaking, so if you delete one, delete the
// behaviour with it.
//
// The rule underneath most of them: this page always has something to offer. A
// visitor who reaches it must never get a dead click, whatever the bot, Discord
// or the CEO's allowlist flag happen to be doing.

const BOT_BASE = 'http://127.0.0.1:3001';
const BOT_ACTIONS = BOT_BASE.'/internal/actions';
const BOT_SECRET = 'test-shared-secret-that-is-long-enough-32';
const JOIN_INVITE = 'https://discord.gg/testinvite';
const JOINER_ID = '111222333444555666';
const JOINER_TOKEN = 'stub-join-access-token';

beforeEach(function () {
    config([
        'services.discord.client_id' => 'test-client-id',
        'services.discord.client_secret' => 'test-client-secret',
        'services.discord.invite_url' => JOIN_INVITE,
        'services.bot.url' => BOT_BASE,
        'services.bot.secret' => BOT_SECRET,
        'services.bot.key_id' => 'web-test',
        'services.bot.add_member_timeout' => 2,
    ]);
});

/** Stub the OAuth leg so no test ever talks to Discord. */
function stubJoinSocialite(?Throwable $throws = null): void
{
    $user = new SocialiteUser;
    $user->setRaw(['id' => JOINER_ID])->map(['id' => JOINER_ID, 'nickname' => 'wren']);
    $user->token = JOINER_TOKEN;

    // The concrete OAuth2 provider, not the generic contract: the controller
    // narrows to it on purpose, because only it promises setScopes(),
    // redirectUrl() and a token on the user.
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('stateless')->andReturnSelf();

    if ($throws !== null) {
        $provider->shouldReceive('user')->andThrow($throws);
    } else {
        $provider->shouldReceive('user')->andReturn($user);
    }

    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);
}

/** The bot answering `guild.add_member` with one of its two success outcomes. */
function botSucceeds(string $outcome): void
{
    Http::fake([BOT_ACTIONS => Http::response([
        'ok' => true,
        'result' => ['outcome' => $outcome],
        'request_id' => '01JTESTREQUESTID',
    ], 200)]);
}

/** The bot answering with its typed error envelope (INTERNAL_ACTIONS.md §2). */
function botRefuses(string $code, int $status, bool $retryable = false): void
{
    Http::fake([BOT_ACTIONS => Http::response([
        'ok' => false,
        'error' => ['code' => $code, 'message' => 'nope', 'retryable' => $retryable],
        'request_id' => '01JTESTREQUESTID',
    ], $status)]);
}

/** Walk through Discord's callback the way a returning visitor does. */
function arriveFromDiscord(array $query = ['code' => 'valid-code']): TestResponse
{
    return test()->get('/join/callback?'.http_build_query($query));
}

// ---------------------------------------------------------------------------
// The two successes. Both are successes, and they say different things.
// ---------------------------------------------------------------------------

it('puts a visitor in the server and tells them they are in', function () {
    stubJoinSocialite();
    botSucceeds('added');

    arriveFromDiscord()
        ->assertRedirect(route('join'))
        ->assertSessionHas('join_result', 'added');

    $page = test()->get('/join');

    $page->assertSee(__('join.result.added'));
    // The way into the server, not an invite they have to be talked into using.
    $page->assertSee('data-testid="server-link"', false);
    $page->assertSee(JOIN_INVITE, false);
});

it('treats an existing member as a success, not an error', function () {
    // Discord answers 204 when they were already in. Telling somebody who is
    // already a member that something went wrong is both a lie and a dead end.
    stubJoinSocialite();
    botSucceeds('already_member');

    arriveFromDiscord()->assertSessionHas('join_result', 'already_member');

    $page = test()->get('/join');

    $page->assertSee(__('join.result.already_member'));
    $page->assertSee('data-testid="server-link"', false);
    // A success is announced, not shouted: role=alert would interrupt a screen
    // reader user to tell them good news.
    $page->assertSee('role="status"', false);
});

it('tells an added member about rules screening, because joined is not yet active', function () {
    // With Rules Screening on, Discord adds people as `pending`: they are in the
    // server and cannot post. A member who thinks they have joined and then
    // cannot talk assumes we are broken.
    expect(__('join.result.added'))->toContain('rules');
});

// ---------------------------------------------------------------------------
// The degraded path. This is the half that matters most: one-click routes
// through the bot, so it is only up while the bot is up.
// ---------------------------------------------------------------------------

it('falls back to a plain invite link when the bot refuses', function (string $code, int $status) {
    stubJoinSocialite();
    botRefuses($code, $status);

    arriveFromDiscord()->assertSessionHas('join_result', 'unavailable');

    $page = test()->get('/join');

    $page->assertSee(__('join.result.unavailable'));
    $page->assertSee('data-testid="invite-link"', false);
    $page->assertSee(JOIN_INVITE, false);
})->with([
    // The answer until the CEO sets TWO_INTERNAL_ALLOW_ADD_MEMBER=1 on the bot.
    // It is a configuration state, not a fault, and it must degrade like one.
    'action switched off' => ['action_not_allowed', 403],
    'discord said no' => ['discord_rejected', 422],
    'discord unreachable' => ['discord_unavailable', 502],
    'bot timed out on discord' => ['upstream_timeout', 504],
    'signature rejected' => ['unauthorized', 401],
    'clock drift' => ['stale_request', 401],
]);

it('falls back to a plain invite link when the bot is not listening at all', function () {
    stubJoinSocialite();
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    arriveFromDiscord()->assertSessionHas('join_result', 'unavailable');

    test()->get('/join')->assertSee('data-testid="invite-link"', false);
});

it('falls back when the bot answers with something that is not the envelope', function () {
    // Usually means something that is not the bot is answering on that port.
    stubJoinSocialite();
    Http::fake([BOT_ACTIONS => Http::response('<html>nginx</html>', 200)]);

    arriveFromDiscord()->assertSessionHas('join_result', 'unavailable');
});

it('falls back when the bot reports an outcome this site has no sentence for', function () {
    stubJoinSocialite();
    botSucceeds('teleported');

    arriveFromDiscord()->assertSessionHas('join_result', 'unavailable');
});

it('says so plainly when a visitor cancels at Discord', function () {
    // Not an error. They said no.
    stubJoinSocialite();
    Http::fake();

    arriveFromDiscord(['error' => 'access_denied'])
        ->assertSessionHas('join_result', 'denied');

    // A cancelled approval must not reach the bot at all.
    Http::assertNothingSent();
});

it('recovers from an expired or replayed callback', function () {
    stubJoinSocialite(throws: new RuntimeException('Invalid state'));
    Http::fake();

    arriveFromDiscord()->assertSessionHas('join_result', 'expired');

    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------
// Not configured — which is the state of every box today, and of local dev.
// ---------------------------------------------------------------------------

it('offers the plain invite link when one-click cannot work', function () {
    config(['services.bot.secret' => '']);

    $page = test()->get('/join');

    $page->assertSee('data-testid="invite-link"', false);
    $page->assertDontSee('data-testid="one-click-join"', false);
});

it('does not spend a visitor consent it cannot honour', function () {
    // Approving "add you to servers" and then being handed an invite link anyway
    // is worse than being handed the invite link in the first place.
    config(['services.bot.secret' => '']);
    Http::fake();

    test()->get('/join/discord')
        ->assertRedirect(route('join'))
        ->assertSessionHas('join_result', 'unavailable');

    Http::assertNothingSent();
});

it('says the join link is not set up rather than rendering a link to nowhere', function () {
    config(['services.bot.secret' => '', 'services.discord.invite_url' => '']);

    test()->get('/join')->assertSee('data-testid="join-unconfigured"', false);
});

// ---------------------------------------------------------------------------
// The wire. These pin the contract in two-bot docs/INTERNAL_ACTIONS.md §1.
// ---------------------------------------------------------------------------

it('signs exactly the bytes it sends', function () {
    stubJoinSocialite();
    botSucceeds('added');

    arriveFromDiscord();

    Http::assertSent(function (ClientRequest $request) {
        $raw = $request->body();

        // Re-serialising the body between signing and sending is the classic way
        // to break this, and it fails as an unexplained 401.
        expect($raw)->toBe(json_encode([
            'action' => 'guild.add_member',
            'discord_id' => JOINER_ID,
            'access_token' => JOINER_TOKEN,
        ]));

        expect($request->header('X-TWO-Signature')[0])->toBe(BotClient::signature(
            BOT_SECRET,
            $request->header('X-TWO-Timestamp')[0],
            $request->header('X-TWO-Nonce')[0],
            $raw,
        ));

        expect($request->header('X-TWO-Key-Id')[0])->toBe('web-test');
        expect($request->url())->toBe(BOT_ACTIONS);

        return true;
    });
});

it('sends a timestamp the bot will accept as fresh', function () {
    stubJoinSocialite();
    botSucceeds('added');

    arriveFromDiscord();

    Http::assertSent(function (ClientRequest $request) {
        // Unix seconds, and the bot rejects anything beyond 120s of its own
        // clock. Sending milliseconds here would reject every request forever.
        expect(abs(time() - (int) $request->header('X-TWO-Timestamp')[0]))->toBeLessThan(10);

        return true;
    });
});

it('sends a fresh nonce on every attempt', function () {
    stubJoinSocialite();
    botSucceeds('added');

    arriveFromDiscord();
    arriveFromDiscord();

    $nonces = [];

    Http::assertSent(function (ClientRequest $request) use (&$nonces) {
        $nonces[] = $request->header('X-TWO-Nonce')[0];

        return true;
    });

    // Reusing one is a replay and the bot rejects it, so a fixed nonce would mean
    // exactly one visitor could ever join per 240 seconds.
    expect($nonces)->toHaveCount(2);
    expect(array_unique($nonces))->toHaveCount(2);

    foreach ($nonces as $nonce) {
        expect($nonce)->toMatch('/^[0-9a-f]{32}$/');
    }
});

it('sends no idempotency key, because Discord makes the repeat harmless', function () {
    // guild.add_member is naturally idempotent — 204 if they are already in — so
    // it is on the pre-Postgres slice. Sending a key would imply a durable store
    // the bot does not have yet.
    stubJoinSocialite();
    botSucceeds('added');

    arriveFromDiscord();

    Http::assertSent(fn (ClientRequest $request) => $request->header('Idempotency-Key') === []);
});
