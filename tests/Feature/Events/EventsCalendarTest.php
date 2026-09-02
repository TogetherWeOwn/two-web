<?php

use App\Enums\EventStatus;
use App\Livewire\EventsCalendar;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Livewire\Livewire;

/**
 * The calendar itself: what a member sees before they touch anything.
 *
 * The states that matter here are the ones with nothing in them. An events page
 * with no events is the state this site spends its first months in, and the brief
 * is explicit that it has to read as *early* rather than *broken* — so "the empty
 * state is not a broken page" is a test, not a design note.
 */
beforeEach(function (): void {
    $this->member = User::factory()->create();
});

it('lists a published event with its local wall time, not UTC', function (): void {
    // 20:00 in London is 19:00 UTC while BST is in force. A component that renders
    // the raw instant shows 19:00 and is wrong by an hour for every member reading
    // it. The clock is frozen because "upcoming" is relative to now, and a fixed
    // date silently becomes a past event once the calendar rolls past it — which
    // is a test that stops testing what it says without ever going red for a
    // reason anyone would recognise.
    $this->travelTo(new DateTimeImmutable('2026-08-01 12:00:00', new DateTimeZone('UTC')));

    Event::factory()->create([
        'title' => 'Helldivers night',
        'starts_at' => new DateTimeImmutable('2026-08-20 19:00:00', new DateTimeZone('UTC')),
        'ends_at' => new DateTimeImmutable('2026-08-20 21:00:00', new DateTimeZone('UTC')),
        'timezone' => 'Europe/London',
        'status' => EventStatus::Published,
    ]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertSee('Helldivers night')
        ->assertSee('20:00');
});

it('never shows a draft to an ordinary member', function (): void {
    Event::factory()->draft()->create(['title' => 'Not announced yet']);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertDontSee('Not announced yet');
});

it('shows a moderator the drafts they still have to publish', function (): void {
    Event::factory()->draft()->create(['title' => 'Not announced yet']);

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test(EventsCalendar::class)
        ->assertSee('Not announced yet');
});

it('reads the no-events state as the next one being planned, not as a failure', function (): void {
    // The whole point of the card. This asserts the designed copy, because the
    // failure this guards against is someone "simplifying" it to "No events",
    // which is the exact reading the brief rules out.
    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertSee('Nothing on the calendar yet')
        ->assertSee('Game nights get posted here first')
        ->assertDontSee('No events')
        ->assertDontSee('error');
});

it('offers the Discord as the one way out of an empty calendar', function (): void {
    // Rule 2 of the empty-state spec: exactly one action, never a dead end.
    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="events-empty-action"');
});

it('says so when nothing is upcoming but the club has a past', function (): void {
    // An empty upcoming list with past events hidden reads as "nobody plays here".
    // Saying "the last one was..." reads as a gap, which is the truth.
    Event::factory()->create([
        'title' => 'Last months raid',
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->subMonth()->addHours(2),
        'status' => EventStatus::Past,
    ]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertSee('Nothing scheduled right now')
        ->assertDontSee('Nothing on the calendar yet');
});

it('marks a full event as full rather than offering a seat that does not exist', function (): void {
    $event = Event::factory()->create(['capacity' => 2]);
    Rsvp::factory()->count(2)->for($event)->create();

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertSee("This one's full", escape: false)
        ->assertSee('2');
});

it('shows a member who is going that they are in', function (): void {
    $event = Event::factory()->create();
    Rsvp::factory()->for($event)->for($this->member)->create();

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertSee("You're in", escape: false);
});

it('still offers a seat on a full event to the member already holding one', function (): void {
    // Capacity is only a wall for an answer that newly takes a seat — the service
    // is explicit about this. A member who is already going must not be told the
    // event is full and left with no way to stand down.
    $event = Event::factory()->create(['capacity' => 1]);
    Rsvp::factory()->for($event)->for($this->member)->create();

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertSee("You're in", escape: false)
        ->assertSeeHtml('data-testid="rsvp-withdraw"');
});
