<?php

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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

    $response = $this->get('/auth/discord/callback?code=stale&state=wrong');

    $response->assertRedirect(route('home'));
    $response->assertSessionHas('auth_error', 'expired');

    $this->assertGuest();
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
