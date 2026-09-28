<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Exceptions\EventNotOpenException;
use App\Livewire\RsvpButton;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\EventService;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * The waitlist: a full event's over-capacity path.
 *
 * A full event refusing Going is correct — the refusal is typed and honest.
 * But a refusal with nowhere to go is a dead end, so the member gets a line to
 * stand in. The line is a `waitlisted` answer, not a separate table: one row
 * per member per event stays intact, no aggregate changes (every `going_count`
 * filters on Going), and leaving the line is the existing withdraw.
 *
 * Three things this file holds that would otherwise drift:
 *  - a waitlisted row holds no seat (the count is unchanged);
 *  - the place in line is first-come, first-served;
 *  - the line never auto-promotes — a freed seat is claimed, not granted.
 */
beforeEach(function () {
    Queue::fake();

    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->full = Event::factory()->create([
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
        'capacity' => 1,
    ]);
    $this->seatHolder = User::factory()->create(['is_moderator' => false]);
    app(EventService::class)->rsvp($this->full, $this->seatHolder, RsvpStatus::Going);
});

/* ---------------------------------------------------------------------------
   Joining the line
   --------------------------------------------------------------------------- */

it('offers the waitlist instead of only a refusal on a full event', function () {
    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->full])
        ->assertSee("This one's full.", false)
        ->assertSeeHtml('data-testid="waitlist-join"');
});

it('records a waitlisted answer when the member joins the line', function () {
    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->full])
        ->call('rsvp', RsvpStatus::Waitlisted->value)
        ->assertSeeHtml('data-testid="waitlist-position"')
        ->assertDontSee("This one's full.", false);

    expect(Rsvp::query()->where('user_id', $this->member->id)->first()?->status)
        ->toBe(RsvpStatus::Waitlisted);
});

it('accepts a waitlisted answer on a full event at the service level', function () {
    // The capacity check only gates answers that newly take a seat. If this
    // ever throws, the line has been fenced off by accident.
    $rsvp = app(EventService::class)->rsvp($this->full->fresh(), $this->member, RsvpStatus::Waitlisted);

    expect($rsvp->status)->toBe(RsvpStatus::Waitlisted);
});

it('accepts a waitlisted answer over HTTP on a full event', function () {
    // StoreRsvpRequest validates with Rule::enum, so the new case flows through
    // with no request change — this is the proof.
    $this->actingAs($this->member)
        ->putJson(route('events.rsvp.update', $this->full), [
            'status' => RsvpStatus::Waitlisted->value,
        ])
        ->assertSuccessful();

    expect(Rsvp::query()->where('user_id', $this->member->id)->first()?->status)
        ->toBe(RsvpStatus::Waitlisted);
});

it('still refuses Going on a full event', function () {
    // The line exists beside the refusal, not instead of it.
    $this->actingAs($this->member)
        ->putJson(route('events.rsvp.update', $this->full), [
            'status' => RsvpStatus::Going->value,
        ])
        ->assertConflict()
        ->assertJsonPath('reason', 'event_at_capacity');
});

it('refuses a waitlisted answer on an event that is not open', function () {
    // Over HTTP the policy answers first: cancelled is a 403, "not you / not
    // now at the gate" (RsvpEndedEventTest pins the same for ended events).
    $this->full->update(['status' => EventStatus::Cancelled]);

    $this->actingAs($this->member)
        ->putJson(route('events.rsvp.update', $this->full->fresh()), [
            'status' => RsvpStatus::Waitlisted->value,
        ])
        ->assertForbidden();

    // And the service answers 409 event_not_open for any caller that reaches
    // it past the gate — the line is no more open than the door.
    expect(fn () => app(EventService::class)->rsvp($this->full->fresh(), $this->member, RsvpStatus::Waitlisted))
        ->toThrow(EventNotOpenException::class);
});

/* ---------------------------------------------------------------------------
   The place in line
   --------------------------------------------------------------------------- */

it('numbers places first-come, first-served', function () {
    $first = User::factory()->create(['is_moderator' => false]);
    $second = User::factory()->create(['is_moderator' => false]);

    app(EventService::class)->rsvp($this->full->fresh(), $first, RsvpStatus::Waitlisted);
    // No clock travel between the joins: same-second answers are exactly when
    // a full event collects a line, so the id tiebreak is doing real work here
    // rather than the clock.
    app(EventService::class)->rsvp($this->full->fresh(), $second, RsvpStatus::Waitlisted);

    expect($this->full->fresh()->waitlistPositionFor($first))->toBe(1)
        ->and($this->full->fresh()->waitlistPositionFor($second))->toBe(2);
});

it('shows the member their place in line in words', function () {
    app(EventService::class)->rsvp($this->full->fresh(), $this->member, RsvpStatus::Waitlisted);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->full->fresh()])
        ->assertSee('You\'re on the waitlist', false)
        ->assertSee('#1 in line', false);
});

it('announces the place in line politely, not as an alert', function () {
    $html = Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->full])
        ->call('rsvp', RsvpStatus::Waitlisted->value)
        ->html();

    expect($html)->toContain('role="status"')
        ->toContain('data-testid="waitlist-position"')
        ->not->toContain('role="alert"');
});

it('returns no place for a member who is not in line', function () {
    expect($this->full->waitlistPositionFor($this->member))->toBeNull();
});

/* ---------------------------------------------------------------------------
   A waitlisted row holds no seat
   --------------------------------------------------------------------------- */

it('counts no seats for the line', function () {
    app(EventService::class)->rsvp($this->full->fresh(), $this->member, RsvpStatus::Waitlisted);

    $event = $this->full->fresh();

    expect($event->goingCount())->toBe(1)
        ->and($event->waitlistCount())->toBe(1);
});

it('leaves the seat holder alone when somebody joins the line', function () {
    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->full])
        ->call('rsvp', RsvpStatus::Waitlisted->value);

    expect(Rsvp::query()
        ->where('user_id', $this->seatHolder->id)
        ->first()?->status)->toBe(RsvpStatus::Going);
});

/* ---------------------------------------------------------------------------
   Leaving the line, and claiming a freed seat
   --------------------------------------------------------------------------- */

it('lets a member leave the line', function () {
    app(EventService::class)->rsvp($this->full->fresh(), $this->member, RsvpStatus::Waitlisted);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->full->fresh()])
        ->assertSee('Leave the waitlist', false)
        ->call('withdraw')
        ->assertSeeHtml('data-testid="waitlist-join"');

    expect(Rsvp::query()->where('user_id', $this->member->id)->exists())->toBeFalse();
});

it('never traps a waitlisted member at the refusal', function () {
    // The line member is not holding a seat, but they already have an answer —
    // showing them "full" with nowhere to go strands them on this state.
    app(EventService::class)->rsvp($this->full->fresh(), $this->member, RsvpStatus::Waitlisted);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->full->fresh()])
        ->assertSeeHtml('data-testid="waitlist-leave"')
        ->assertDontSeeHtml('data-testid="waitlist-join"');
});

it('offers the freed seat to the line instead of promoting silently', function () {
    app(EventService::class)->rsvp($this->full->fresh(), $this->member, RsvpStatus::Waitlisted);
    app(EventService::class)->withdrawRsvp($this->full->fresh(), $this->seatHolder);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->full->fresh()])
        ->assertSeeHtml('data-testid="waitlist-claim"')
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertSeeHtml('data-testid="rsvp-confirmed"');

    expect(Rsvp::query()->where('user_id', $this->member->id)->first()?->status)
        ->toBe(RsvpStatus::Going);
});

it('moves focus to the place in line after joining', function () {
    $html = Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->full])
        ->call('rsvp', RsvpStatus::Waitlisted->value)
        ->html();

    expect($html)->toContain('tabindex="-1"');
});
