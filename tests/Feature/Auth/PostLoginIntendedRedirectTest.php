<?php

use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;

// TOG-8718: the post-login bounce must never leave this site.
//
// redirect()->intended() replays the session's `url.intended` slot verbatim —
// UrlGenerator::to() passes any absolute URL through untouched — so whatever is
// sitting in that slot when the Discord callback finishes decides where a
// freshly-authenticated member lands. These tests plant hostile values in the
// slot, drive the real callback seam, and pin the two outcomes that matter:
// off-site values fall back to the profile, same-origin values are honoured.
//
// Helpers are file-local (intended* prefix): Pest loads every Feature file into
// one process, so reusing DiscordLoginTest.php's helper names would fatal.

const INTENDED_GUILD = '900000000000000001';
const INTENDED_MEMBER_ROLE = '900000000000000007';

beforeEach(function () {
    config([
        'services.discord.client_id' => 'test-client-id',
        'services.discord.client_secret' => 'test-client-secret',
        'services.discord.redirect' => 'http://localhost:8000/auth/discord/callback',
        'services.discord.guild_id' => INTENDED_GUILD,
        'services.discord.moderator_role_ids' => ['900000000000000042'],
    ]);
});

function intendedDiscordUser(): SocialiteUser
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

function stubIntendedLogin(): void
{
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('scopes')->andReturnSelf();
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn(intendedDiscordUser());
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);

    Http::fake([
        'discord.com/api/*/users/@me/guilds/*/member' => Http::response([
            'roles' => [INTENDED_MEMBER_ROLE],
            'joined_at' => '2024-03-01T12:00:00.000000+00:00',
            'nick' => null,
        ], 200),
    ]);
}

/** Drive the real callback seam with a planted `url.intended` slot. */
function intendedCallbackResponse($test, ?string $intended)
{
    $request = $test->withSession($intended === null ? [] : ['url.intended' => $intended]);

    return $request->get('/auth/discord/callback?code=good&state=x');
}

// ---------------------------------------------------------------------------
// The attack: a planted intended slot must not bounce a member off-site
// ---------------------------------------------------------------------------

it('falls back to the profile for hostile intended values', function (string $hostile) {
    stubIntendedLogin();

    intendedCallbackResponse($this, $hostile)->assertRedirect(route('profile'));

    // The login itself still worked — only the destination was neutered.
    $this->assertAuthenticated();
})->with([
    // A planted absolute URL is what redirect()->intended() replays verbatim.
    'absolute off-site' => 'https://evil.example/phish',
    'http off-site' => 'http://evil.example/',
    // No scheme at all, and the browser still leaves the site.
    'protocol-relative' => '//evil.example/phish',
    // An exact host match, not a suffix one: this is not our host.
    'suffix lookalike' => 'https://localhost.evil.example/',
    // parse_url puts evil.example in `host` here; the `localhost` is userinfo.
    'userinfo smuggle' => 'https://localhost@evil.example/',
    // Never a navigation target after login, whatever the framework would do.
    'script scheme' => 'javascript:alert(1)',
]);

// ---------------------------------------------------------------------------
// No regression: the legitimate bounce still works
// ---------------------------------------------------------------------------

it('still honours a same-origin relative intended URL', function () {
    stubIntendedLogin();

    $response = intendedCallbackResponse($this, '/events.json');
    $target = (string) $response->headers->get('Location');

    expect(parse_url($target, PHP_URL_HOST))->toBe('localhost')
        ->and(parse_url($target, PHP_URL_PATH))->toBe('/events.json');

    $this->assertAuthenticated();
});

it('still honours a same-origin absolute intended URL', function () {
    stubIntendedLogin();

    // Built from the live generator so the host matches by construction.
    $absolute = url('/events.json');

    intendedCallbackResponse($this, $absolute)->assertRedirect($absolute);

    $this->assertAuthenticated();
});

it('lands on the profile when nothing was intended', function () {
    stubIntendedLogin();

    intendedCallbackResponse($this, null)->assertRedirect(route('profile'));

    $this->assertAuthenticated();
});
