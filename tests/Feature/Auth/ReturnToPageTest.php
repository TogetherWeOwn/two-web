<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;

// TOG-9254: `?next=` returns the member to the page they came from after the
// Discord round trip, on both the join and the login journeys. The guard
// itself is pinned in tests/Unit/SafeRedirectTest.php; these pin the wiring:
// store on the way out, consume on the way back, hostile values ignored at
// both ends, and the guest links that start the journey.
//
// Helper names are file-local on purpose: Pest loads every test file's
// functions into one namespace, and discordUser/stubSocialite already belong
// to DiscordLoginTest.

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

function returnToPageUser(): SocialiteUser
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

function stubReturnToPageLogin(): void
{
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn(returnToPageUser());
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);
}

function stubReturnToPageGuildMember(): void
{
    Http::fake([
        'discord.com/api/*/users/@me/guilds/*/member' => Http::response([
            'roles' => ['900000000000000007'],
            'joined_at' => '2024-03-01T12:00:00.000000+00:00',
            'nick' => null,
        ], 200),
    ]);
}

function stubReturnToPageJoin(): void
{
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn(returnToPageUser());
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);
}

function stubReturnToPageBot(): void
{
    Http::fake(['http://bot.internal:3001/internal/actions' => Http::response([
        'ok' => true,
        'result' => ['outcome' => 'added'],
        'request_id' => '01JRETURNTO',
    ], 200)]);
}

function returnToPageEvent(): Event
{
    return Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'description' => 'Bring a friend, bring spare ammo.',
        'location' => 'Voice: General',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);
}

// ---------------------------------------------------------------------------
// Login: store on the way out
// ---------------------------------------------------------------------------

it('stores a safe next page in the session on the login redirect', function () {
    $this->get(route('login', ['next' => '/e/sunday-squad-01']))->assertRedirect();

    expect(session('login_next'))->toBe('/e/sunday-squad-01');
});

it('stores nothing on the login redirect for a hostile next page', function () {
    // The acceptance case: an absolute URL must leave no trace in the
    // session, or the callback would have something to refuse.
    $this->get(route('login', ['next' => 'https://evil.test']))->assertRedirect();

    expect(session('login_next'))->toBeNull();
});

// ---------------------------------------------------------------------------
// Login: consume on the way back
// ---------------------------------------------------------------------------

it('returns a member to their page after login instead of the profile', function () {
    stubReturnToPageLogin();
    stubReturnToPageGuildMember();

    $this->withSession(['login_next' => '/e/sunday-squad-01'])
        ->get('/auth/discord/callback?code=good&state=x')
        ->assertRedirect('/e/sunday-squad-01')
        ->assertSessionMissing('login_next');

    $this->assertAuthenticated();
});

it('keeps the profile landing when a hostile next page reaches the login callback', function () {
    // Belt and braces with the redirect-side test above: even if a hostile
    // value ever lands in the session, the callback refuses it.
    stubReturnToPageLogin();
    stubReturnToPageGuildMember();

    $this->withSession(['login_next' => 'https://evil.test'])
        ->get('/auth/discord/callback?code=good&state=x')
        ->assertRedirect(route('profile'));

    $this->assertAuthenticated();
});

it('prefers an explicit next page over the stored intended URL on login', function () {
    // A guest bounced by the `auth` middleware leaves `url.intended` behind.
    // A link they then click carries an explicit `?next=` — the explicit
    // choice wins, and the stale intended URL is forgotten rather than
    // surprising them on their *next* login.
    stubReturnToPageLogin();
    stubReturnToPageGuildMember();

    $this->withSession([
        'url.intended' => route('profile'),
        'login_next' => '/e/sunday-squad-01',
    ])
        ->get('/auth/discord/callback?code=good&state=x')
        ->assertRedirect('/e/sunday-squad-01');

    expect(session('url.intended'))->toBeNull();
});

// ---------------------------------------------------------------------------
// Join: store on the way out, consume on the way back
// ---------------------------------------------------------------------------

it('carries a safe next page through the join OAuth state to the event', function () {
    $this->get(route('join.redirect', ['next' => '/e/sunday-squad-01']))->assertRedirect();

    expect(session('join_next'))->toBe('/e/sunday-squad-01');

    stubReturnToPageJoin();
    stubReturnToPageBot();

    $this->get('/join/callback?code=good&state=x')
        ->assertRedirect('/e/sunday-squad-01')
        ->assertSessionHas('join_result', 'added')
        ->assertSessionMissing('join_next');

    $this->assertAuthenticatedAs(User::query()->sole());
});

it('stores nothing on the join redirect for a hostile next page', function () {
    $this->get(route('join.redirect', ['next' => 'https://evil.test']))->assertRedirect();

    expect(session('join_next'))->toBeNull();
});

it('keeps the profile landing when a hostile next page reaches the join callback', function () {
    stubReturnToPageJoin();
    stubReturnToPageBot();

    $this->withSession(['join_next' => 'https://evil.test'])
        ->get('/join/callback?code=good&state=x')
        ->assertRedirect(route('profile'))
        ->assertSessionHas('join_result', 'added');
});

// ---------------------------------------------------------------------------
// The guest links that start the journey
// ---------------------------------------------------------------------------

it('forwards the return page from the join page onto the one-click link', function () {
    $this->get(route('join', ['next' => '/e/sunday-squad-01']))
        ->assertOk()
        ->assertSee(route('join.redirect', ['next' => '/e/sunday-squad-01']), escape: false);
});

it('leaves the one-click link bare for a hostile next page on the join page', function () {
    $html = (string) $this->get(route('join', ['next' => 'https://evil.test']))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('evil.test')
        ->toContain('data-testid="one-click-join"');
});

it('pitches joining to a guest on the event page with the way back attached', function () {
    $event = returnToPageEvent();

    $this->get(route('events.page', $event))
        ->assertOk()
        ->assertSee(route('join', ['next' => route('events.page', $event, false)]), escape: false);
});

it('asks a guest to log in on the calendar with the way back attached', function () {
    returnToPageEvent();

    $this->get(route('events.index'))
        ->assertOk()
        ->assertSee('Log in with Discord')
        ->assertSee(route('login', ['next' => '/events']), escape: false);
});
