<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;

// TOG-6787: session-fixation rotation + logout replay proof.
//
// Logout is POST-only (routes/web.php) and destroys the session
// (DiscordLoginController::logout: invalidate + regenerateToken). What was
// missing was proof that the session *id* turns over on every auth crossing
// and that a logged-out id is dead. That proof lives here.
//
// Why this formulation works when the naive one cannot: the HTTP test client
// sends no session cookie unless told to, so comparing "session id before"
// with "session id after" always sees two fresh ids even with a hand-rolled
// login. Instead each test below *presents* a chosen, attacker-known session
// id as the request cookie (via withCookies, which encrypts it exactly as the
// browser would carry it) and asserts the response carries a *different* id.
// If Auth::login ever stops migrating the session — e.g. a setUser hand-roll
// that skips SessionGuard::updateSession's regenerate(true) — the presented
// id comes straight back and the test goes red. Verified by mutation, not by
// hope: dropping invalidate() resurrects the user on replay; a non-migrating
// login echoes the presented id.
//
// Mechanism under test, all framework-owned and cited so a Laravel upgrade
// that moves it breaks loudly here rather than silently in production:
// SessionGuard::login -> updateSession -> session->regenerate(true), and
// Store::invalidate (flush + migrate with destroy) on logout.

const FIXATION_PRE_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const FIXATION_GUILD = '900000000000000001';
const FIXATION_MOD_ROLE = '900000000000000042';
const FIXATION_MEMBER_ROLE = '900000000000000007';

beforeEach(function () {
    config([
        'services.discord.client_id' => 'test-client-id',
        'services.discord.client_secret' => 'test-client-secret',
        'services.discord.redirect' => 'http://localhost:8000/auth/discord/callback',
        'services.discord.guild_id' => FIXATION_GUILD,
        'services.discord.moderator_role_ids' => [FIXATION_MOD_ROLE],
        'services.bot.url' => 'http://bot.internal:3001',
        'services.bot.secret' => 'test-shared-secret-that-is-long-enough-32',
        'services.bot.key_id' => 'web-test',
        'services.bot.timeout' => 2,
    ]);
});

/** A distinct Socialite user object so this file never depends on another file's helpers. */
function fixationDiscordUser(): SocialiteUser
{
    $user = new SocialiteUser;
    $user->setRaw([
        'id' => '111222333444555666',
        'username' => 'wren',
        'global_name' => 'Wren',
        'avatar' => 'abc123',
    ])->map([
        'id' => '111222333444555666',
        'nickname' => 'wren',
        'name' => 'wren',
        'avatar' => 'https://cdn.discordapp.com/avatars/111222333444555666/abc123.jpg',
    ]);
    $user->token = 'stub-access-token';

    return $user;
}

function stubFixationLoginProvider(): void
{
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('scopes')->andReturnSelf();
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn(fixationDiscordUser());
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);
}

function stubFixationJoinProvider(): void
{
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn(fixationDiscordUser());
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);
}

function stubFixationGuildMember(): void
{
    Http::fake([
        'discord.com/api/*/users/@me/guilds/*/member' => Http::response([
            'roles' => [FIXATION_MEMBER_ROLE],
            'joined_at' => '2024-03-01T12:00:00.000000+00:00',
            'nick' => null,
        ], 200),
        'bot.internal:3001/internal/actions' => Http::response([
            'ok' => true,
            'result' => ['outcome' => 'added'],
            'request_id' => '01JFIXATIONPROOF',
        ], 200),
    ]);
}

/**
 * The session id the response carries, decrypted. Presenting works through
 * withCookies (the client encrypts); reading back needs the decrypt half of
 * TestResponse::getCookie, which is what a browser would hold as the cookie.
 */
function fixationResponseSessionId($response): string
{
    $cookie = $response->getCookie(config('session.cookie'));

    expect($cookie)->not->toBeNull('no session cookie on the response');

    return (string) $cookie->getValue();
}

// ---------------------------------------------------------------------------
// Rotation on auth crossing
// ---------------------------------------------------------------------------

it('rotates the presented session id on Discord login', function () {
    stubFixationLoginProvider();
    stubFixationGuildMember();

    $login = $this->withCookies([config('session.cookie') => FIXATION_PRE_ID])
        ->get('/auth/discord/callback?code=good&state=x');

    $login->assertRedirect(route('profile'));
    $this->assertAuthenticated();

    // The attacker's known id must not survive the crossing. Without the
    // migrate inside Auth::login this echoes FIXATION_PRE_ID and fails.
    expect(fixationResponseSessionId($login))->not->toBe(FIXATION_PRE_ID);
});

it('rotates the presented session id on the join callback', function () {
    stubFixationJoinProvider();
    stubFixationGuildMember();

    $join = $this->withCookies([config('session.cookie') => FIXATION_PRE_ID])
        ->get('/join/callback?code=good&state=x');

    $join->assertRedirect(route('profile'));
    $this->assertAuthenticated();

    expect(fixationResponseSessionId($join))->not->toBe(FIXATION_PRE_ID);
});

// ---------------------------------------------------------------------------
// Logout kills the id, and the dead id stays dead
// ---------------------------------------------------------------------------

it('issues a fresh session id on logout and rejects the logged-out id on replay', function () {
    stubFixationLoginProvider();
    stubFixationGuildMember();

    $sessionCookie = config('session.cookie');

    $login = $this->withCookies([$sessionCookie => FIXATION_PRE_ID])
        ->get('/auth/discord/callback?code=good&state=x');

    $login->assertRedirect(route('profile'));
    $liveId = fixationResponseSessionId($login);

    // The encrypted blob as the browser carried it: the exact bytes an
    // attacker who stole the pre-logout cookie would replay.
    $liveBlob = $login->getCookie($sessionCookie, decrypt: false)->getValue();

    $logout = $this->withCookies([$sessionCookie => $liveId])
        ->post(route('logout'));

    $logout->assertRedirect(route('home'));
    $this->assertGuest();

    // Logout must turn the id over, not just empty the session: reusing the
    // live id afterwards has to be a stranger's session, not a resurrection.
    expect(fixationResponseSessionId($logout))->not->toBe($liveId);

    // Replay the stolen pre-logout cookie against a members-only page. The
    // row was destroyed by invalidate(), so this arrives as a guest.
    // withUnencryptedCookies sends the blob verbatim (re-encrypting it would
    // double-wrap it); it still decrypts because the app key never turned.
    $replay = $this->withUnencryptedCookies([$sessionCookie => $liveBlob])
        ->get(route('profile'));

    $replay->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::query()->count())->toBe(1);
});
