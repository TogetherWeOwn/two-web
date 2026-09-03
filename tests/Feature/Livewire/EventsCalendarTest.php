<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Livewire\EventsCalendar;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

// The calendar and the RSVP flow (TOG-53). These drive the Livewire component
// directly rather than the page, because the component is what the Dusk journey,
// the page and any future embed all go through — a test that only exercised the
// route would prove one caller wired it up.
//
// The write-back to Discord is faked throughout: what the queue does with the job
// is TOG-52's business and is tested there. What this file cares about is what the
// member is *told* while it has not happened yet.

beforeEach(function () {
    Queue::fake();

    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->moderator = User::factory()->create(['is_moderator' => true]);
});

it('shows a published event to a visitor who is not signed in', function () {
    $event = Event::factory()->create([
        'title' => 'Helldivers Friday',
        'status' => EventStatus::Published,
    ]);

    Livewire::test(EventsCalendar::class)
        ->assertOk()
        ->assertSee('Helldivers Friday')
        ->assertSee($event->event_key, escape: false);
});

it('hides a draft from a member', function () {
    Event::factory()->draft()->create(['title' => 'Unannounced Raid']);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertDontSee('Unannounced Raid');
});

it('shows a draft to a moderator, marked as unannounced', function () {
    Event::factory()->draft()->create(['title' => 'Unannounced Raid']);

    Livewire::actingAs($this->moderator)
        ->test(EventsCalendar::class)
        ->assertSee('Unannounced Raid');
});

it('reads as the next one being planned when nothing is scheduled', function () {
    // The card is explicit that this must not read as a broken page. The empty
    // state is a promise about the future, not the absence of a list.
    Livewire::test(EventsCalendar::class)
        ->assertOk()
        ->assertSee('being planned')
        ->assertSeeHtml('data-testid="events-empty"');
});

it('does not show the empty state when an event is scheduled', function () {
    Event::factory()->create(['status' => EventStatus::Published]);

    Livewire::test(EventsCalendar::class)
        ->assertDontSeeHtml('data-testid="events-empty"');
});

it('leaves out an event that has already finished', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');

    Event::factory()->create([
        'title' => 'Last Month Night',
        'starts_at' => Carbon::parse('2026-05-01 19:00:00'),
        'ends_at' => Carbon::parse('2026-05-01 21:00:00'),
    ]);

    Livewire::test(EventsCalendar::class)->assertDontSee('Last Month Night');
});

it('records a going answer for the signed-in member', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => null]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->call('rsvp', $event->event_key, RsvpStatus::Going->value)
        ->assertHasNoErrors();

    expect(Rsvp::query()->where('event_id', $event->id)->where('user_id', $this->member->id)->value('status'))
        ->toBe(RsvpStatus::Going->value);
});

it('shows the member the answer they already gave', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    Rsvp::factory()->for($event)->for($this->member)->create(['status' => RsvpStatus::Going]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="rsvp-state-'.$event->event_key.'" data-state="going"');
});

it('lets a member change their answer', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    Rsvp::factory()->for($event)->for($this->member)->create(['status' => RsvpStatus::Going]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->call('rsvp', $event->event_key, RsvpStatus::Maybe->value);

    expect(Rsvp::query()->where('event_id', $event->id)->where('user_id', $this->member->id)->value('status'))
        ->toBe(RsvpStatus::Maybe->value);
});

it('lets a member withdraw their answer entirely', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    Rsvp::factory()->for($event)->for($this->member)->create(['status' => RsvpStatus::Going]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->call('withdraw', $event->event_key);

    expect(Rsvp::query()->where('event_id', $event->id)->where('user_id', $this->member->id)->exists())
        ->toBeFalse();
});

it('marks an event as full once every seat is taken', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => 1]);
    Rsvp::factory()->for($event)->for(User::factory())->create(['status' => RsvpStatus::Going]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="event-full-'.$event->event_key.'"');
});

it('does not call an event full for a member who already holds a seat', function () {
    // They are going. The event being at capacity is not news they can act on, and
    // showing them "full" next to their own confirmed seat reads as a mistake.
    $event = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => 1]);
    Rsvp::factory()->for($event)->for($this->member)->create(['status' => RsvpStatus::Going]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertDontSeeHtml('data-testid="event-full-'.$event->event_key.'"');
});

it('tells the member the last seat went to somebody else', function () {
    // The loser of the capacity race. EventService throws; the component has to
    // turn that into a sentence rather than a 500.
    $event = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => 1]);
    Rsvp::factory()->for($event)->for(User::factory())->create(['status' => RsvpStatus::Going]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->call('rsvp', $event->event_key, RsvpStatus::Going->value)
        ->assertSee('full')
        ->assertSeeHtml('data-testid="rsvp-error-'.$event->event_key.'"');

    expect(Rsvp::query()->where('user_id', $this->member->id)->exists())->toBeFalse();
});

it('tells the member an event that is no longer open cannot be answered', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    // Cancelled after the page was rendered, which is exactly the window this
    // message exists for.
    $event->forceFill(['status' => EventStatus::Cancelled])->save();

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->call('rsvp', $event->event_key, RsvpStatus::Going->value)
        ->assertSeeHtml('data-testid="rsvp-error-'.$event->event_key.'"');

    expect(Rsvp::query()->where('user_id', $this->member->id)->exists())->toBeFalse();
});

it('says Discord has not been told yet while the answer is unsynced', function () {
    // Measured on this codebase: an unreachable bot does NOT fail the RSVP. The row
    // commits and SyncEventToDiscord releases itself back onto the queue. So the
    // honest thing to show a member is not "your RSVP failed" — it is "we have it,
    // Discord does not know yet", which is what `synced_to_discord_at` records.
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    Rsvp::factory()->for($event)->for($this->member)->create([
        'status' => RsvpStatus::Going,
        'synced_to_discord_at' => null,
    ]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="rsvp-pending-'.$event->event_key.'"');
});

it('stops saying so once Discord has been told', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    Rsvp::factory()->for($event)->for($this->member)->create([
        'status' => RsvpStatus::Going,
        'synced_to_discord_at' => now(),
    ]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertDontSeeHtml('data-testid="rsvp-pending-'.$event->event_key.'"');
});

it('refuses an answer from a visitor who is not signed in', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    Livewire::test(EventsCalendar::class)
        ->call('rsvp', $event->event_key, RsvpStatus::Going->value)
        ->assertSeeHtml('data-testid="rsvp-error-'.$event->event_key.'"');

    expect(Rsvp::query()->count())->toBe(0);
});

it('offers a guest the way to sign in rather than an RSVP button', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    Livewire::test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="rsvp-signin-'.$event->event_key.'"')
        ->assertDontSeeHtml('data-testid="rsvp-going-'.$event->event_key.'"');
});

it('refuses an answer for an event key that does not exist', function () {
    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->call('rsvp', 'NOTAREALEVENTKEY0000000000', RsvpStatus::Going->value)
        ->assertOk();

    expect(Rsvp::query()->count())->toBe(0);
});

it('refuses a status that is not one of the three answers', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->call('rsvp', $event->event_key, 'definitely-maybe')
        ->assertOk();

    expect(Rsvp::query()->count())->toBe(0);
});

it('switches between the list and the calendar', function () {
    Livewire::test(EventsCalendar::class)
        ->assertSet('mode', 'list')
        ->assertSeeHtml('data-testid="events-list"')
        ->call('showCalendar')
        ->assertSet('mode', 'calendar')
        ->assertSeeHtml('data-testid="events-calendar"');
});

it('puts an event on its day in the calendar grid, in the events own timezone', function () {
    // 00:30 on 1 July in London is 23:30 on 30 June in UTC. A grid built off the
    // UTC instant files this under the wrong day, and only for half the year.
    Carbon::setTestNow('2026-06-15 12:00:00');

    Event::factory()->create([
        'title' => 'Half Past Midnight',
        'timezone' => 'Europe/London',
        'starts_at' => Carbon::parse('2026-06-30 23:30:00', 'UTC'),
        'ends_at' => Carbon::parse('2026-07-01 01:30:00', 'UTC'),
    ]);

    $days = Livewire::test(EventsCalendar::class)
        ->call('showCalendar')
        ->set('month', '2026-07')
        ->viewData('weeks');

    $withEvents = collect($days)->flatten(1)->filter(fn (array $day): bool => $day['events']->isNotEmpty());

    expect($withEvents)->toHaveCount(1)
        ->and($withEvents->first()['date']->toDateString())->toBe('2026-07-01');
});

it('moves the calendar a month at a time', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');

    Livewire::test(EventsCalendar::class)
        ->assertSet('month', '2026-06')
        ->call('nextMonth')
        ->assertSet('month', '2026-07')
        ->call('previousMonth')
        ->call('previousMonth')
        ->assertSet('month', '2026-05');
});

it('counts only the members who said they are going', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => 10]);
    Rsvp::factory()->for($event)->for(User::factory())->create(['status' => RsvpStatus::Going]);
    Rsvp::factory()->for($event)->for(User::factory())->create(['status' => RsvpStatus::Maybe]);
    Rsvp::factory()->for($event)->for(User::factory())->create(['status' => RsvpStatus::NotGoing]);

    $events = Livewire::test(EventsCalendar::class)->viewData('events');

    expect($events->firstWhere('event_key', $event->event_key)->going_count)->toBe(1);
});

it('asks the database once for the seat counts however many events there are', function () {
    // A count per card is the N+1 that turns a calendar with a month of events into
    // a page that misses its LCP budget.
    Event::factory()->count(5)->create(['status' => EventStatus::Published]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    Livewire::actingAs($this->member)->test(EventsCalendar::class);

    expect($queries)->toBeLessThan(5);
});
