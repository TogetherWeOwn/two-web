<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;

// TOG-8560: an expired session mid-write. The `auth` middleware throws before
// any controller runs, so a member who composed a profile edit past
// SESSION_LIFETIME watched their words vanish into the dead POST body: a bare
// 302 to the Discord handoff, which carries no message and survives nothing
// to the other side of OAuth. These pin the fix: the unsafe browser submit
// keeps the login redirect but flashes `auth_error=expired` (the home
// banner's key) and the input, and the return trip survives.
//
// The session shape here is the expired one, not the never-signed-in one: a
// `_previous.url` from the page they were writing on, the way a real browser
// journey leaves it. A bare guest with no prior page gets the same flash;
// what differs is only url.intended, which the framework derives from that
// history.

it('flashes expired plus the words on a dead-session profile PATCH, and keeps the return trip', function () {
    $member = User::factory()->create();

    $this->withSession(['_previous' => ['url' => route('profiles.show', $member)]])
        ->patch(route('profiles.update', $member), ['bio' => 'mid-write hello'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('auth_error', 'expired');

    expect(old('bio'))->toBe('mid-write hello')
        ->and(session('url.intended'))->toBe(route('profiles.show', $member))
        ->and($member->profile()->exists())->toBeFalse();
});

it('flashes expired plus the answer on a dead-session RSVP PUT, and writes nothing', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $this->withSession(['_previous' => ['url' => route('events.page', $event)]])
        ->put(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
        ->assertRedirect(route('login'))
        ->assertSessionHas('auth_error', 'expired');

    expect(old('status'))->toBe(RsvpStatus::Going->value)
        ->and(Rsvp::query()->count())->toBe(0);
});

it('keeps the 401 for JSON and the silent handoff for guest GETs', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $this->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
        ->assertUnauthorized();

    $this->get(route('profile'))
        ->assertRedirect(route('login'))
        ->assertSessionMissing('auth_error');
});
