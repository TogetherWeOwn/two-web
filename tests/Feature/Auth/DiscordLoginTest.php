<?php

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;

// The front door. Every test here is a rule a member would notice breaking, so if
// you delete one, delete the behaviour with it.

const GUILD = '900000000000000001';
const MOD_ROLE = '900000000000000042';
const MEMBER_ROLE = '900000000000000007';

beforeEach(function () {
    config([
        'services.discord.client_id' => 'test-client-id',
        'services.discord.client_secret' => 'test-client-secret',
        'services.discord.redirect' => 'http://localhost:8000/auth/discord/callback',
        'services.discord.guild_id' => GUILD,
        'services.discord.moderator_role_ids' => [MOD_ROLE],
    ]);
});

/** The object Socialite hands us back after a successful token exchange. */
function discordUser(array $overrides = []): SocialiteUser
{
    $raw = array_merge([
        'id' => '111222333444555666',
        'username' => 'wren',
        'global_name' => 'Wren',
        'discriminator' => '0',
        'avatar' => 'abc123',
    ], $overrides);

    $user = new SocialiteUser;
    $user->setRaw($raw)->map([
        'id' => $raw['id'],
        'nickname' => $raw['username'],
        'name' => $raw['username'],
        'avatar' => 'https://cdn.discordapp.com/avatars/'.$raw['id'].'/'.$raw['avatar'].'.jpg',
    ]);
    $user->token = 'stub-access-token';

    return $user;
}

/** Stub the OAuth leg so no test ever talks to Discord. */
function stubSocialite(?SocialiteUser $user = null, ?Throwable $throws = null): void
{
    // The concrete OAuth2 provider, not the generic contract: the controller
    // narrows to it on purpose, because only it promises setScopes() and a token.
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('scopes')->andReturnSelf();
    $provider->shouldReceive('stateless')->andReturnSelf();

    if ($throws !== null) {
        $provider->shouldReceive('user')->andThrow($throws);
    } else {
        $provider->shouldReceive('user')->andReturn($user ?? discordUser());
    }

    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);
}

/** Stub the one Discord REST call we make: this member's roles in the TWO server. */
function stubGuildMember(array $roles = [MEMBER_ROLE], string $joinedAt = '2024-03-01T12:00:00.000000+00:00'): void
{
    Http::fake([
        'discord.com/api/*/users/@me/guilds/*/member' => Http::response([
            'roles' => $roles,
            'joined_at' => $joinedAt,
            'nick' => null,
        ], 200),
    ]);
}

// ---------------------------------------------------------------------------
// The happy path
// ---------------------------------------------------------------------------

// This is an exact-set assertion on purpose. It is the guard that stops `email`,
// `guilds` or `guilds.join` drifting back onto the login consent screen: every
// extra line there is a reason to press Cancel, and email is data we refuse to hold.
it('sends a member to Discord with exactly two scopes and no more', function () {
    $response = $this->get(route('login'));

    $response->assertRedirect();
    $target = $response->headers->get('Location');

    expect($target)->toStartWith('https://discord.com/api/oauth2/authorize');

    parse_str((string) parse_url($target, PHP_URL_QUERY), $query);

    expect($query['client_id'])->toBe('test-client-id')
        ->and($query['redirect_uri'])->toBe('http://localhost:8000/auth/discord/callback')
        ->and($query['response_type'])->toBe('code')
        ->and(explode(' ', $query['scope']))
        ->toEqualCanonicalizing(['identify', 'guilds.members.read']);
});

it('creates the member, signs them in, and lands them on their profile', function () {
    stubSocialite();
    stubGuildMember();

    $response = $this->get('/auth/discord/callback?code=good&state=x');

    $response->assertRedirect(route('profile'));

    $user = User::query()->sole();

    expect($user->discord_id)->toBe('111222333444555666')
        ->and($user->username)->toBe('wren')
        ->and($user->display_name)->toBe('Wren')
        ->and($user->avatar)->toBe('https://cdn.discordapp.com/avatars/111222333444555666/abc123.jpg')
        ->and($user->discord_joined_at->toDateString())->toBe('2024-03-01')
        ->and($user->discord_synced_at)->not->toBeNull()
        ->and($user->is_moderator)->toBeFalse();

    $this->assertAuthenticatedAs($user);
});

it('never stores an email, because we never ask for one', function () {
    stubSocialite(discordUser(['email' => 'member@example.com']));
    stubGuildMember();

    $this->get('/auth/discord/callback?code=good&state=x');

    expect(array_keys(User::query()->sole()->getAttributes()))->not->toContain('email');
});

it('signs an existing member back in without creating a second account', function () {
    $existing = User::factory()->create([
        'discord_id' => '111222333444555666',
        'username' => 'old_handle',
    ]);
    stubSocialite();
    stubGuildMember();

    $this->get('/auth/discord/callback?code=good&state=x');

    expect(User::query()->count())->toBe(1)
        ->and($existing->fresh()->username)->toBe('wren');

    $this->assertAuthenticatedAs($existing->fresh());
});

// There is deliberately no "the session id changes on login" test here. Laravel's
// session guard migrates it inside Auth::login, and every version of that test I
// could write against the HTTP test client passed even with the login hand-rolled
// to skip the migrate — the test client issues a fresh session per request either
// way. A test that cannot go red is worse than no test, so it is not here. The
// protection is real, it just belongs to the framework, not to us.

// ---------------------------------------------------------------------------
// Discord roles decide what you can see
// ---------------------------------------------------------------------------

it('makes a member with the moderator role a moderator', function () {
    stubSocialite();
    stubGuildMember(roles: [MEMBER_ROLE, MOD_ROLE]);

    $this->get('/auth/discord/callback?code=good&state=x');

    expect(User::query()->sole()->is_moderator)->toBeTrue();
});

it('takes moderator away at the next login when the role is taken away in Discord', function () {
    // Permissions are refreshed from Discord on every login. This is the test that
    // fails if someone ever caches them.
    User::factory()->create(['discord_id' => '111222333444555666', 'is_moderator' => true]);
    stubSocialite();
    stubGuildMember(roles: [MEMBER_ROLE]);

    $this->get('/auth/discord/callback?code=good&state=x');

    expect(User::query()->sole()->is_moderator)->toBeFalse();
});

it('makes nobody a moderator when no moderator role is configured', function () {
    // Blank config must fail closed, not open.
    config(['services.discord.moderator_role_ids' => []]);
    stubSocialite();
    stubGuildMember(roles: [MEMBER_ROLE, MOD_ROLE]);

    $this->get('/auth/discord/callback?code=good&state=x');

    expect(User::query()->sole()->is_moderator)->toBeFalse();
});

it('lets a moderator through the admin gate and keeps a member out', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);
    $member = User::factory()->create(['is_moderator' => false]);

    expect($moderator->can('access-admin'))->toBeTrue()
        ->and($member->can('access-admin'))->toBeFalse();
});

// The test above says permissions are refreshed "at the next login". That promise
// is only worth anything if a login is the *only* way back in. A "remember me"
// cookie is a second way back in, and it skips the callback where roles are read —
// so a member stripped of moderator in Discord would keep the admin panel until
// the cookie expired, which Laravel defaults to five years.
//
// This test guards the two facts that make that impossible: no recaller cookie is
// handed to the browser, and no remember token is written to the row one could be
// rebuilt from. Both go red if `remember: true` comes back.
//
// What it deliberately does NOT claim is an end-to-end "they were not resurrected".
// The recaller cannot be replayed through the HTTP test client at all: whatever you
// hand it — the raw Set-Cookie value, the decrypted value, withCookies(),
// withUnencryptedCookies() — EncryptCookies nulls that cookie before the guard
// reads it, while an ordinary cookie in the same request survives untouched. So an
// assertion that they came back a guest passes just as happily with the bug present
// and would be decoration. The resurrection journey needs a real cookie jar, which
// means Dusk — flagged to QA on TOG-47 rather than faked here.
it('does not hand admin back to a member remembered by cookie after their role was taken away', function () {
    stubSocialite();
    stubGuildMember(roles: [MEMBER_ROLE, MOD_ROLE]);

    $login = $this->get('/auth/discord/callback?code=good&state=x');

    // They really did sign in, and really are a moderator — otherwise the two
    // assertions below would be true of a login that simply failed.
    $this->assertAuthenticated();
    expect(User::query()->sole()->is_moderator)->toBeTrue();

    // Nothing goes to the browser, and nothing is left in the row, that could bring
    // them back without another trip through the callback.
    $recaller = $this->app['auth']->guard()->getRecallerName();

    expect($login->getCookie($recaller))->toBeNull()
        ->and(User::query()->sole()->remember_token)->toBeNull();
});

// ---------------------------------------------------------------------------
// The four ways this goes wrong. Each one is a designed page, never a stack trace.
// ---------------------------------------------------------------------------

it('shows the declined message when a member says no on the Discord consent screen', function () {
    $response = $this->get('/auth/discord/callback?error=access_denied&error_description=The+user+denied+access');

    $response->assertRedirect(route('home'));
    $response->assertSessionHas('auth_error', 'denied');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('shows the try-again message when the login attempt expired', function () {
    stubSocialite(throws: new InvalidStateException);
    Log::spy();

    $response = $this->get('/auth/discord/callback?code=stale&state=wrong');

    $response->assertRedirect(route('home'));
    $response->assertSessionHas('auth_error', 'expired');

    $this->assertGuest();

    // Class only, never the message: the exchange talks to Discord with a
    // code that becomes a token, and client messages can quote the request
    // that carried them (TOG-5614). Same rule as JoinController.
    Log::shouldHaveReceived('warning')->with('Discord token exchange failed.', [
        'exception' => InvalidStateException::class,
    ])->once();
});

it('logs the member-lookup transport failure by class, never the message', function () {
    // The lookup carries the member's own bearer token in the Authorization
    // header, and HTTP client messages can quote the request (TOG-5614).
    stubSocialite();
    Http::fake(fn () => throw new ConnectionException('timed out carrying secrets'));
    Log::spy();

    $response = $this->get('/auth/discord/callback?code=good&state=x');

    $response->assertRedirect(route('home'));
    $response->assertSessionHas('auth_error', 'unavailable');

    Log::shouldHaveReceived('warning')->with('Discord guild member lookup did not answer.', [
        'exception' => ConnectionException::class,
    ])->once();
});

it('tells a member who left the server that they need to be in it', function () {
    stubSocialite();
    Http::fake([
        'discord.com/api/*/users/@me/guilds/*/member' => Http::response(['message' => 'Unknown Guild'], 404),
    ]);

    $response = $this->get('/auth/discord/callback?code=good&state=x');

    $response->assertRedirect(route('home'));
    $response->assertSessionHas('auth_error', 'not_a_member');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('does not white-screen when Discord is down', function () {
    stubSocialite();
    Http::fake([
        'discord.com/api/*/users/@me/guilds/*/member' => Http::response('upstream is sad', 503),
    ]);

    $response = $this->get('/auth/discord/callback?code=good&state=x');

    $response->assertRedirect(route('home'));
    $response->assertSessionHas('auth_error', 'unavailable');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('refuses to sign anybody in if the server we read roles from is blank', function () {
    // Fail closed, never open. A blank guild must not mean "skip the role check
    // and let them in as a member" — it means we cannot tell who they are here.
    // config/services.php now defaults this so it cannot happen by omission
    // (see DiscordGuildIdTest), but the controller's guard stays either way.
    config(['services.discord.guild_id' => '']);
    stubSocialite();

    $response = $this->get('/auth/discord/callback?code=good&state=x');

    $response->assertRedirect(route('home'));
    $response->assertSessionHas('auth_error', 'unavailable');

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

it('does not white-screen when Discord never answers', function () {
    stubSocialite();
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $response = $this->get('/auth/discord/callback?code=good&state=x');

    $response->assertRedirect(route('home'));
    $response->assertSessionHas('auth_error', 'unavailable');

    $this->assertGuest();
});

it('renders a human sentence for every error code we can emit', function (string $code) {
    // If a new failure path is added without copy, this fails rather than shipping
    // a blank banner.
    $this->withSession(['auth_error' => $code])
        ->get(route('home'))
        ->assertOk()
        ->assertSee(__('auth-discord.'.$code));
})->with(['denied', 'expired', 'not_a_member', 'unavailable']);

// ---------------------------------------------------------------------------
// Sessions
// ---------------------------------------------------------------------------

it('signs a member out and forgets the session', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withSession(['cart_of_secrets' => 'still here'])
        ->post(route('logout'));

    $response->assertRedirect(route('home'));
    $this->assertGuest();

    // Not just "logged out" — the session contents are gone too. Without the
    // invalidate() call this assertion is the one that goes red.
    $response->assertSessionMissing('cart_of_secrets');
});

it('will not sign anyone out over GET', function () {
    // A logout link that works over GET can be triggered by an <img> tag.
    $this->get('/logout')->assertMethodNotAllowed();
});

it('sends a signed-out visitor to Discord when they ask for a members-only page', function () {
    $this->get(route('profile'))->assertRedirect(route('login'));
});

it('lets a signed-in member see their own profile', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile'))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// The real SySOp snowflake, end to end
// ---------------------------------------------------------------------------
//
// Everything above proves the *mechanism* with synthetic ids (MOD_ROLE is
// 900000000000000042, a number no Discord role has). That is the right way to test
// behaviour, and it stays. But it means the value we actually intend to run with —
// `508654771276873729`, SySOp in the TWO guild, decided on TOG-106 — has never once
// been through this flow. The parsing is pinned in DiscordModeratorRoleIdsTest; the
// behaviour is pinned here with a stand-in; nothing joins the two.
//
// TOG-427's done-when is "a moderator sees the admin link and a member does not",
// checked on staging. There is no staging — staging.togetherweown.com serves
// WordPress.com's 403, and deploy.yml still no-ops on an unset deploy hook. These
// three tests are the strongest form of that check available without a box: the
// real snowflake, entered as a `.env` line rather than injected as an array, read
// through config/services.php exactly as a boot would read it, then driven through
// the real callback to the real rendered page.
//
// What they cannot tell you is whether the line is present on a server. Nothing in
// a test suite can. That check stays open on TOG-427 until a box exists.

const TWO_SYSOP_ROLE_ID = '508654771276873729';

/**
 * Put $value in the environment and re-resolve *only* moderator_role_ids from
 * config/services.php, the way a fresh boot resolves it. Pass null for "the line is
 * missing from the file entirely".
 *
 * Only that one key is taken from the real config file — the rest of the discord
 * config stays as beforeEach set it, so this exercises the variable under test and
 * nothing else. Both $_SERVER and $_ENV are written because phpdotenv populates
 * both and Laravel's Env repository reads $_SERVER first; setting only $_ENV leaves
 * the old value winning and the test passes against nothing.
 */
function bootWithSysOpEnv(?string $value): void
{
    $key = 'DISCORD_MODERATOR_ROLE_IDS';

    if ($value === null) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    } else {
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }

    try {
        $resolved = (require config_path('services.php'))['discord']['moderator_role_ids'];
    } finally {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    config(['services.discord.moderator_role_ids' => $resolved]);
}

it('shows the admin link to a SySOp holder when the real snowflake is the configured value', function () {
    // The whole chain: one .env line -> services.php -> isModerator's intersect ->
    // the access-admin gate -> the rendered anchor. This is the half of TOG-427's
    // done-when that says a moderator sees the link.
    bootWithSysOpEnv(TWO_SYSOP_ROLE_ID);
    stubSocialite();
    stubGuildMember(roles: [MEMBER_ROLE, TWO_SYSOP_ROLE_ID]);

    $this->get('/auth/discord/callback?code=good&state=x');

    expect(User::query()->sole()->is_moderator)->toBeTrue();

    $this->get(route('profile'))
        ->assertOk()
        ->assertSee('data-testid="admin-link"', false);
});

it('hides the admin link from a member who does not hold SySOp', function () {
    // The other half, and the one that matters more: an ordinary member signs in
    // perfectly well and is simply never offered the link.
    bootWithSysOpEnv(TWO_SYSOP_ROLE_ID);
    stubSocialite();
    stubGuildMember(roles: [MEMBER_ROLE]);

    $this->get('/auth/discord/callback?code=good&state=x');

    expect(User::query()->sole()->is_moderator)->toBeFalse();

    $this->get(route('profile'))
        ->assertOk()
        ->assertDontSee('data-testid="admin-link"', false);
});

it('still fails closed for a SySOp holder once the variable is blanked', function () {
    // The documented revocation path: blank the variable, everyone is un-granted,
    // no deploy. Worth pinning with the real id because this is the exact sequence
    // someone will run in an incident — and it has to hold for the *administrator*
    // role, not just for a stand-in.
    bootWithSysOpEnv('');
    stubSocialite();
    stubGuildMember(roles: [MEMBER_ROLE, TWO_SYSOP_ROLE_ID]);

    $this->get('/auth/discord/callback?code=good&state=x');

    expect(User::query()->sole()->is_moderator)->toBeFalse();

    $this->get(route('profile'))
        ->assertOk()
        ->assertDontSee('data-testid="admin-link"', false);
});
