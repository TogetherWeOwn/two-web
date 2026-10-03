<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;

// The abuse half of the RSVP contract (TOG-7297). The happy path and the auth
// gate live in EventLifecycleTest and RsvpAuthGateTest; this file pins the four
// vectors an attacker actually reaches for: double-submit, cross-user DELETE,
// method tampering, and withdraw-without-a-row. The fifth vector the pass
// probed — RSVP on a clock-ended event still marked Published — FAILED and is
// tracked as TOG-7419; it is deliberately not asserted here, so this file stays
// green and the bug card owns the red.
//
// Helper names carry an `abuseSurface` prefix: Pest loads every Feature file
// into one process, and `eventPayload()` / `pastEvent()`-style globals already
// collide across files (see PastEventsArchiveTest).

beforeEach(function () {
    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->other = User::factory()->create(['is_moderator' => false]);
});

/** An upcoming published event, the only kind an RSVP should ever touch. */
function abuseSurfaceEvent(array $overrides = []): Event
{
    return Event::factory()->create(array_merge([
        'status' => EventStatus::Published,
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
    ], $overrides));
}

it('keeps one row when the submit is double-clicked', function () {
    $event = abuseSurfaceEvent();

    // 201 on the first answer, 200 on the re-answer: the unique
    // (event_id, user_id) index plus updateOrCreate makes the second click an
    // update of the same row, never a second seat.
    $this->actingAs($this->member)
        ->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
        ->assertStatus(201);

    $this->actingAs($this->member)
        ->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
        ->assertStatus(200);

    expect(Rsvp::query()->where('user_id', $this->member->id)->count())->toBe(1);
});

it('leaves the victim row alone when somebody else sends DELETE', function () {
    // The destroy route keys the delete on the caller, never on a request
    // parameter — there is no row id to tamper with, only the caller's own.
    $event = abuseSurfaceEvent();
    Rsvp::factory()->for($event)->for($this->member, 'user')->create(['status' => RsvpStatus::Going]);

    $this->actingAs($this->other)
        ->deleteJson(route('events.rsvp.destroy', $event))
        ->assertNoContent();

    expect(Rsvp::query()->count())->toBe(1)
        ->and(Rsvp::query()->first()->user_id)->toBe($this->member->id);
});

it('answers method tampering on the RSVP routes with 405 and writes nothing', function () {
    $event = abuseSurfaceEvent();

    $this->actingAs($this->member)->postJson(route('events.rsvp.update', $event), ['status' => 'going'])->assertStatus(405);
    $this->actingAs($this->member)->patchJson(route('events.rsvp.update', $event), ['status' => 'going'])->assertStatus(405);
    $this->actingAs($this->member)->getJson(route('events.rsvp.update', $event))->assertStatus(405);
    $this->actingAs($this->member)->postJson(route('events.rsvp.destroy', $event))->assertStatus(405);

    expect(Rsvp::query()->count())->toBe(0);
});

it('answers a withdraw with no row as a quiet 204', function () {
    // Idempotent by design: withdrawRsvp deletes zero rows and skips the
    // write-back, so a double-clicked "Can't make it" is silence, not an error.
    $event = abuseSurfaceEvent();

    $this->actingAs($this->member)
        ->deleteJson(route('events.rsvp.destroy', $event))
        ->assertNoContent();

    expect(Rsvp::query()->count())->toBe(0);
});

it('refuses an RSVP on an event reconcile already marked past', function () {
    // Status-Past is the closed half the write path *does* guard: the policy
    // demands Published, so this is a 403 with nothing written. The open half —
    // clock-ended but still Published — is TOG-7419.
    $event = abuseSurfaceEvent([
        'status' => EventStatus::Past,
        'starts_at' => now()->subWeek(),
        'ends_at' => now()->subWeek()->addHours(2),
    ]);

    $this->actingAs($this->member)
        ->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
        ->assertForbidden();

    expect(Rsvp::query()->count())->toBe(0);
});

it('still lets a member stand down from a cancelled event', function () {
    // Withdraw is not gated on status: trapping a member on a called-off event
    // because they cannot remove their own row would be the bug.
    $event = abuseSurfaceEvent(['status' => EventStatus::Cancelled]);
    Rsvp::factory()->for($event)->for($this->member, 'user')->create(['status' => RsvpStatus::Going]);

    $this->actingAs($this->member)
        ->deleteJson(route('events.rsvp.destroy', $event))
        ->assertNoContent();

    expect(Rsvp::query()->count())->toBe(0);
});
