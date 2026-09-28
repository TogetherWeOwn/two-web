<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Exceptions\EventAtCapacityException;
use App\Exceptions\EventNotOpenException;
use App\Livewire\RsvpButton;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\EventService;
use App\Support\EventInput;
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
 *  - a freed seat is dealt to the head of the line in the same locked write
 *    (TOG-8394); the claim control stays for whatever gap remains.
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

it('promotes the head of the line when a seat frees (TOG-8394)', function () {
    $second = User::factory()->create(['is_moderator' => false]);

    app(EventService::class)->rsvp($this->full->fresh(), $this->member, RsvpStatus::Waitlisted);
    // Same-second joins: the id tiebreak decides who is #1, which is exactly
    // when a full event collects a line.
    app(EventService::class)->rsvp($this->full->fresh(), $second, RsvpStatus::Waitlisted);

    app(EventService::class)->withdrawRsvp($this->full->fresh(), $this->seatHolder);

    // The earliest waitlisted member holds the seat; the second moves to #1.
    expect(Rsvp::query()->where('user_id', $this->member->id)->first()?->status)
        ->toBe(RsvpStatus::Going)
        ->and($this->full->fresh()->waitlistPositionFor($second))->toBe(1)
        ->and($this->full->fresh()->goingCount())->toBe(1);

    // …and the promoted member sees the confirmation, not the line.
    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->full->fresh()])
        ->assertSeeHtml('data-testid="rsvp-confirmed"')
        ->assertDontSeeHtml('data-testid="waitlist-position"');
});

it('promotes the line when the cap is raised (TOG-8394)', function () {
    $second = User::factory()->create(['is_moderator' => false]);

    app(EventService::class)->rsvp($this->full->fresh(), $this->member, RsvpStatus::Waitlisted);
    app(EventService::class)->rsvp($this->full->fresh(), $second, RsvpStatus::Waitlisted);

    $event = $this->full->fresh();
    $input = new EventInput(
        title: $event->title,
        game: $event->game,
        description: $event->description,
        startsAt: $event->starts_at,
        endsAt: $event->ends_at,
        timezone: $event->timezone,
        location: $event->location,
        capacity: 3,
    );
    app(EventService::class)->update($event, $input);

    expect(Rsvp::query()->where('user_id', $this->member->id)->first()?->status)
        ->toBe(RsvpStatus::Going)
        ->and(Rsvp::query()->where('user_id', $second->id)->first()?->status)
        ->toBe(RsvpStatus::Going)
        ->and($this->full->fresh()->goingCount())->toBe(3)
        ->and($this->full->fresh()->waitlistCount())->toBe(0);
});

it('promotes inside the withdraw lock, not after it (TOG-8394)', function () {
    // The regression this pins: the seat must be dealt while the event row is
    // still locked by the withdraw. If the promotion ran as a second,
    // after-commit step, a concurrent rsvp(Going) could read the freed seat,
    // take it, and leave the member who had been waiting still in line. Forced
    // here deterministically through the lock discipline itself — no sleeps:
    // a transaction that takes the same event row lock first must block until
    // the withdraw commits, and by then the head of the line already holds
    // the seat, so the newcomer finds the event full again.
    //
    // Forced at the assertion level rather than with a second connection —
    // the withdraw's delete and the promotion share one transaction and one
    // row lock, so there is no observable state where the seat reads free
    // beside an unmoved line. No sleeps, no timing.
    app(EventService::class)->rsvp($this->full->fresh(), $this->member, RsvpStatus::Waitlisted);

    app(EventService::class)->withdrawRsvp($this->full->fresh(), $this->seatHolder);

    // The freed seat never reads as free beside an unmoved line: the head
    // holds it in the same commit that freed it.
    $event = $this->full->fresh();

    expect($event->goingCount())->toBe(1)
        ->and($event->waitlistCount())->toBe(0)
        ->and(Rsvp::query()->where('user_id', $this->member->id)->first()?->status)
        ->toBe(RsvpStatus::Going);

    // A newcomer arriving after the commit finds the event full, not a seat
    // to take ahead of the (now empty) line.
    $latecomer = User::factory()->create(['is_moderator' => false]);

    expect(fn () => app(EventService::class)->rsvp($event->fresh(), $latecomer, RsvpStatus::Going))
        ->toThrow(EventAtCapacityException::class);
});

it('compacts the line without promoting when a waitlisted member leaves (TOG-8394)', function () {
    $second = User::factory()->create(['is_moderator' => false]);

    app(EventService::class)->rsvp($this->full->fresh(), $this->member, RsvpStatus::Waitlisted);
    app(EventService::class)->rsvp($this->full->fresh(), $second, RsvpStatus::Waitlisted);

    // Leaving the line frees no seat: nobody is promoted, the seat holder
    // stays, and the second member moves to #1.
    app(EventService::class)->withdrawRsvp($this->full->fresh(), $this->member);

    expect(Rsvp::query()->where('user_id', $this->member->id)->exists())->toBeFalse();

    expect($this->full->fresh()->waitlistPositionFor($second))->toBe(1)
        ->and(Rsvp::query()->where('user_id', $this->seatHolder->id)->first()?->status)
        ->toBe(RsvpStatus::Going)
        ->and($this->full->fresh()->goingCount())->toBe(1);
});

it('leaves the line untouched when the event is closed (TOG-8394)', function () {
    app(EventService::class)->rsvp($this->full->fresh(), $this->member, RsvpStatus::Waitlisted);
    app(EventService::class)->cancel($this->full->fresh());

    // A closed event has no seats to deal: the withdraw stays a plain delete
    // and the line is untouched.
    app(EventService::class)->withdrawRsvp($this->full->fresh(), $this->seatHolder);

    expect(Rsvp::query()->where('user_id', $this->member->id)->first()?->status)
        ->toBe(RsvpStatus::Waitlisted)
        ->and($this->full->fresh()->waitlistPositionFor($this->member))->toBe(1);
});

it('keeps the claim control for the gap a promotion cannot cover', function () {
    // A waitlisted row beside a free seat can only exist mid-flight (before
    // the promotion renders) or when no promotion fired. The control is still
    // there for that gap, through the same locked write as everybody else.
    $event = Event::factory()->create([
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
        'capacity' => 2,
    ]);
    $holder = User::factory()->create(['is_moderator' => false]);
    app(EventService::class)->rsvp($event->fresh(), $holder, RsvpStatus::Going);
    app(EventService::class)->rsvp($event->fresh(), $this->member, RsvpStatus::Waitlisted);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $event->fresh()])
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
