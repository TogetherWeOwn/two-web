<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;

// The auth gate for RSVP writes is the `auth` middleware group around the routes,
// not anything in RsvpController — a guest must never reach the controller. These
// tests pin both faces of that gate: JSON callers get a 401, browser form posts
// get the Discord login redirect, and in neither case does a row appear, change
// or disappear. (TOG-6944)

it('refuses a guest RSVP answer over JSON with a 401 and writes nothing', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $this->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
        ->assertUnauthorized();

    expect(Rsvp::query()->count())->toBe(0);
});

it('refuses a guest RSVP withdrawal over JSON with a 401 and keeps the row', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    $rsvp = Rsvp::factory()->for($event)->create(['status' => RsvpStatus::Going]);

    $this->deleteJson(route('events.rsvp.destroy', $event))
        ->assertUnauthorized();

    // Still there, still the same answer: the guest touched nothing.
    expect($rsvp->fresh()?->status)->toBe(RsvpStatus::Going)
        ->and(Rsvp::query()->count())->toBe(1);
});

it('redirects a guest RSVP answer to login instead of writing', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $this->put(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
        ->assertRedirect(route('login'));

    expect(Rsvp::query()->count())->toBe(0);
});

it('redirects a guest RSVP withdrawal to login instead of deleting', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    Rsvp::factory()->for($event)->create(['status' => RsvpStatus::Going]);

    $this->delete(route('events.rsvp.destroy', $event))
        ->assertRedirect(route('login'));

    expect(Rsvp::query()->count())->toBe(1);
});
