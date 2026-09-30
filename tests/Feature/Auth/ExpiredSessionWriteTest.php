<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User as SocialiteUser;

// TOG-8560: an expired session mid-write. The `auth` middleware throws before
// any controller runs, so a member who hit submit past SESSION_LIFETIME watched
// their answer vanish into the dead POST body: a bare 302 to the Discord
// handoff, which carries no message and survives nothing to the other side of
// OAuth. These pin the fix: the unsafe browser submit keeps the login redirect
// but stores a durable notice (session `put`, not flash — a flash dies in the
// callback before the landing page), the login callback reflashes
// `auth_error=expired` for the landing, and the landing renders the banner
// with a login link. Input repopulation is deliberately not claimed: the
// profile form is Livewire `wire:model` and no view reads `old('bio')`.
//
// The session shape here is the expired one, not the never-signed-in one: a
// `_previous.url` from the page they were writing on, the way a real browser
// journey leaves it. What differs from a bare guest is only url.intended,
// which the framework derives from that history.
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
    ]);
});

function expiredSessionSocialiteUser(): SocialiteUser
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

function stubExpiredSessionLogin(): void
{
    $provider = Mockery::mock(AbstractProvider::class)->makePartial();
    $provider->shouldReceive('setScopes')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn(expiredSessionSocialiteUser());
    Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);

    Http::fake([
        'discord.com/api/*/users/@me/guilds/*/member' => Http::response([
            'roles' => ['900000000000000007'],
            'joined_at' => '2024-03-01T12:00:00.000000+00:00',
            'nick' => null,
        ], 200),
    ]);
}

it('keeps the login redirect on a dead-session RSVP PUT but stores a durable notice and the return trip', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $this->withSession(['_previous' => ['url' => route('events.page', $event)]])
        ->put(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
        ->assertRedirect(route('login'))
        ->assertSessionHas('expired_session_notice', true);

    expect(session('url.intended'))->toBe(route('events.page', $event))
        ->and(Rsvp::query()->count())->toBe(0);
});

it('shows the expired-session banner with a login link on the landing page after re-login', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    stubExpiredSessionLogin();

    // The notice the dead PUT stored, plus the return trip it left behind.
    $landing = $this->withSession([
        'url.intended' => route('events.page', $event),
        'expired_session_notice' => true,
    ])->followingRedirects()
        ->get('/auth/discord/callback?code=good&state=x')
        ->assertOk()
        ->assertSee(__('auth-discord.expired'), escape: false)
        ->assertSee('data-testid="auth-error"', escape: false)
        ->assertSee('Try signing in again');

    $this->assertAuthenticated();

    // Single-shot: the notice is consumed by the callback, so the next login
    // does not repeat a stale sentence.
    expect(session('expired_session_notice'))->toBeNull()
        ->and($landing->getContent())->toContain(route('events.page', $event, false));
});

it('keeps the 401 for JSON, and the silent handoff for guest GETs and /admin', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $this->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
        ->assertUnauthorized()
        ->assertSessionMissing('expired_session_notice');

    $this->get(route('profile'))
        ->assertRedirect(route('login'))
        ->assertSessionMissing('expired_session_notice');

    // Filament has its own auth flow (/admin, no login form, 403 for
    // non-moderators). The envelope must not touch its handoff.
    $this->get('/admin')
        ->assertRedirect(route('login'))
        ->assertSessionMissing('expired_session_notice');
});
