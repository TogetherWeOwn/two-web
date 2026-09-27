<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Livewire\EventsCalendar;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Livewire\Livewire;

/**
 * The calendar page: which events a person is shown, and what the page says when
 * there are none.
 *
 * The empty states are tested first and hardest on purpose. They are the states a
 * new community site is in most of the time, the Designer singles them out
 * (`two-design docs/COMPONENTS.md` §8, "designed first"), and they are the ones a
 * happy-path test never reaches. The exact copy is asserted against
 * `docs/COPY.md` because "reads as early, not abandoned" is the requirement and
 * paraphrasing it is how that gets lost.
 */
beforeEach(function () {
    $this->moderator = User::factory()->create(['is_moderator' => true]);
    $this->member = User::factory()->create(['is_moderator' => false]);
});

/** An event that has not happened yet, published, so a guest can see it. */
function upcomingEvent(array $overrides = []): Event
{
    return Event::factory()->create(array_merge([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ], $overrides));
}

function pastEvent(array $overrides = []): Event
{
    return Event::factory()->create(array_merge([
        'title' => 'Last week s Valorant night',
        'starts_at' => now()->subWeek(),
        'ends_at' => now()->subWeek()->addHours(2),
        'status' => EventStatus::Published,
    ], $overrides));
}

it('is reachable by a guest, because the empty state is a pitch to join', function () {
    $this->get(route('events.index'))
        ->assertOk()
        ->assertSeeLivewire(EventsCalendar::class);
});

it('has a descriptive document title', function () {
    $this->get(route('events.index'))
        ->assertOk()
        ->assertSee('<title>Events — Together We Own</title>', escape: false);
});

it('lists an upcoming published event', function () {
    $event = upcomingEvent();

    Livewire::test(EventsCalendar::class)
        ->assertSee('Friday night Helldivers')
        ->assertSeeHtml('data-event-key="'.$event->event_key.'"');
});

it('puts a working RSVP control on every upcoming card', function () {
    // Regression: the card partial took a `$past` boolean, but `@include` merges
    // the parent's scope and the parent already had a `$past` *collection*. The
    // collection is truthy, so the RSVP button was suppressed on every card in the
    // upcoming list — a page of events nobody could answer.
    upcomingEvent();

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="rsvp-going"');
});

it('does not offer an RSVP on a card in the past list', function () {
    pastEvent();

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->call('showPast')
        ->assertSeeHtml('data-testid="events-past-list"')
        ->assertDontSeeHtml('data-testid="rsvp-going"');
});

/* ---------------------------------------------------------------------------
   Empty states. docs/COPY.md is the source of this wording, not this test.
   --------------------------------------------------------------------------- */

it('says the next one is being planned when nothing has ever been scheduled', function () {
    Livewire::test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="events-empty-never"')
        ->assertSee('Nothing on the calendar yet.')
        ->assertSee('Game nights get posted here first.')
        // Rule 2 of the empty-state pattern: always exactly one action.
        ->assertSee('Join the Discord');
});

it('never renders the never-scheduled empty state as a broken or errored page', function () {
    // The brief's actual requirement, stated as the thing that must NOT happen.
    $html = Livewire::test(EventsCalendar::class)->html();

    expect($html)
        ->not->toContain('role="alert"')
        ->not->toContain('Couldn\'t load the events.')
        // "Never the word empty, nothing, no results alone" — COMPONENTS.md §8.
        ->not->toContain('No results')
        ->not->toContain('No events found');
});

it('admits that past events exist rather than showing a bare empty upcoming list', function () {
    pastEvent();

    Livewire::test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="events-empty-no-upcoming"')
        ->assertSee('Nothing scheduled right now.')
        ->assertSee('See past events')
        // The wrong empty state here is the whole point of this test.
        ->assertDontSeeHtml('data-testid="events-empty-never"');
});

it('tells the member how long ago the last one was', function () {
    pastEvent(['ends_at' => now()->subDays(9), 'starts_at' => now()->subDays(9)->subHours(2)]);

    Livewire::test(EventsCalendar::class)
        ->assertSee('The last one was 1 week ago.');
});

it('shows the past events once they are asked for', function () {
    pastEvent();

    Livewire::test(EventsCalendar::class)
        ->assertDontSee('Last week s Valorant night')
        ->call('showPast')
        ->assertSee('Last week s Valorant night');
});

/* ---------------------------------------------------------------------------
   Visibility
   --------------------------------------------------------------------------- */

it('hides a draft from a member and from a guest', function () {
    upcomingEvent(['title' => 'Unannounced raid', 'status' => EventStatus::Draft]);

    Livewire::test(EventsCalendar::class)->assertDontSee('Unannounced raid');

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertDontSee('Unannounced raid');
});

it('shows a draft to a moderator, marked as one', function () {
    upcomingEvent(['title' => 'Unannounced raid', 'status' => EventStatus::Draft]);

    Livewire::actingAs($this->moderator)
        ->test(EventsCalendar::class)
        ->assertSee('Unannounced raid')
        ->assertSee('Draft');
});

it('keeps a cancelled event visible and says so, rather than making it vanish', function () {
    // Vanishing is worse than cancelled: a member who RSVP'd needs to learn it is off.
    upcomingEvent(['title' => 'Called off night', 'status' => EventStatus::Cancelled]);

    Livewire::test(EventsCalendar::class)
        ->assertSee('Called off night')
        ->assertSee('Cancelled');
});

/* ---------------------------------------------------------------------------
   Capacity
   --------------------------------------------------------------------------- */

it('marks an event as full with the cap in words, not by colour alone', function () {
    $event = upcomingEvent(['capacity' => 2]);
    Rsvp::factory()->count(2)->create(['event_id' => $event->id, 'status' => RsvpStatus::Going]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="event-full"')
        ->assertSee("This one's full.", false)
        ->assertSee('Cap is 2.');
});

it('does not count a maybe as a seat', function () {
    $event = upcomingEvent(['capacity' => 1]);
    Rsvp::factory()->create(['event_id' => $event->id, 'status' => RsvpStatus::Maybe]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertDontSeeHtml('data-testid="event-full"');
});

it('says how many are going against the cap', function () {
    $event = upcomingEvent(['capacity' => 5]);
    Rsvp::factory()->count(2)->create(['event_id' => $event->id, 'status' => RsvpStatus::Going]);

    Livewire::test(EventsCalendar::class)->assertSee('2 of 5 going');
});

it('counts the going without a cap without inventing one', function () {
    $event = upcomingEvent(['capacity' => null]);
    Rsvp::factory()->create(['event_id' => $event->id, 'status' => RsvpStatus::Going]);

    Livewire::test(EventsCalendar::class)
        ->assertSee('1 going')
        ->assertDontSee('1 of');
});

/* ---------------------------------------------------------------------------
   Views
   --------------------------------------------------------------------------- */

it('offers both views and starts on the list, which is the one that works at 360px', function () {
    Livewire::test(EventsCalendar::class)
        ->assertSet('view', 'list')
        ->assertSeeHtml('data-testid="events-view-list"')
        ->assertSeeHtml('data-testid="events-view-calendar"');
});

it('switches to the calendar view and renders a real month grid', function () {
    upcomingEvent();

    Livewire::test(EventsCalendar::class)
        ->call('setView', 'calendar')
        ->assertSet('view', 'calendar')
        ->assertSeeHtml('data-testid="events-calendar-grid"')
        // A grid with no days is not a calendar.
        ->assertSeeHtml('data-testid="calendar-day"');
});

it('makes the horizontally scrolling calendar keyboard accessible', function () {
    Livewire::test(EventsCalendar::class)
        ->call('setView', 'calendar')
        ->assertSeeHtml('data-testid="events-calendar-scroll"')
        ->assertSeeHtml('tabindex="0"')
        ->assertSeeHtml('aria-label="Events calendar; scroll horizontally to see all days"');
});

it('uses AA contrast text for dates outside the current month', function () {
    $html = Livewire::test(EventsCalendar::class)
        ->call('setView', 'calendar')
        ->html();

    expect($html)
        ->toContain('text-ink-muted')
        ->not->toContain('text-ink-disabled');
});

it('still renders a month when the month property is nonsense', function () {
    // `month` is a public Livewire property and therefore client input. A wrong
    // month is a page somebody can navigate away from; a fatal is a blank screen.
    Livewire::test(EventsCalendar::class)
        ->set('month', 'not-a-month')
        ->call('setView', 'calendar')
        ->assertOk()
        ->assertSeeHtml('data-testid="events-calendar-grid"');
});

it('refuses a view name it does not have rather than rendering nothing', function () {
    Livewire::test(EventsCalendar::class)
        ->call('setView', 'gantt')
        ->assertSet('view', 'list');
});

it('opens the calendar on the month the next event is in, not always today', function () {
    $event = upcomingEvent(['starts_at' => now()->addMonths(2), 'ends_at' => now()->addMonths(2)->addHour()]);

    Livewire::test(EventsCalendar::class)
        ->assertSet('month', $event->startsAtLocal()->format('Y-m'));
});

it('moves between months', function () {
    $start = now()->startOfMonth();

    Livewire::test(EventsCalendar::class)
        ->assertSet('month', $start->format('Y-m'))
        ->call('nextMonth')
        ->assertSet('month', $start->copy()->addMonth()->format('Y-m'))
        ->call('previousMonth')
        ->call('previousMonth')
        ->assertSet('month', $start->copy()->subMonth()->format('Y-m'));
});

/* ---------------------------------------------------------------------------
   Search. `?q=` narrows the same rows the list renders, over title and
   description. Descriptions are pinned explicitly here: the factory fills
   them with faker paragraphs, and a random paragraph containing the search
   word would turn these assertions into flakes.
   --------------------------------------------------------------------------- */

it('finds an event by a title fragment', function () {
    upcomingEvent(['title' => 'Friday night Helldivers', 'description' => 'Weekly co-op chaos.']);
    upcomingEvent(['title' => 'Sunday Valorant scrims', 'description' => 'Tactical practice.']);

    Livewire::test(EventsCalendar::class)
        ->set('search', 'helldiv')
        ->assertSee('Friday night Helldivers')
        ->assertDontSee('Sunday Valorant scrims');
});

it('matches case-insensitively', function () {
    upcomingEvent(['title' => 'Friday night Helldivers', 'description' => 'Weekly co-op chaos.']);

    Livewire::test(EventsCalendar::class)
        ->set('search', 'HELLDIVERS')
        ->assertSee('Friday night Helldivers');
});

it('finds an event by a description word', function () {
    upcomingEvent(['title' => 'Game night', 'description' => 'Bring spare ammo for the tournament.']);
    upcomingEvent(['title' => 'Other night', 'description' => 'Just chatting over voice.']);

    Livewire::test(EventsCalendar::class)
        ->set('search', 'ammo')
        ->assertSee('Game night')
        ->assertDontSee('Other night');
});

it('reads the initial query from ?q= so a search is a shareable link', function () {
    upcomingEvent(['title' => 'Friday night Helldivers', 'description' => 'Weekly co-op chaos.']);
    upcomingEvent(['title' => 'Sunday Valorant scrims', 'description' => 'Tactical practice.']);

    Livewire::withQueryParams(['q' => 'helldiv'])
        ->test(EventsCalendar::class)
        ->assertSee('Friday night Helldivers')
        ->assertDontSee('Sunday Valorant scrims');
});

it('shows its own empty state when nothing matches, not the never-scheduled one', function () {
    upcomingEvent(['title' => 'Friday night Helldivers', 'description' => 'Weekly co-op chaos.']);

    Livewire::test(EventsCalendar::class)
        ->set('search', 'zzz-no-such-event-zzz')
        ->assertSeeHtml('data-testid="events-empty-search"')
        ->assertSee('Nothing matches that search.')
        ->assertSeeHtml('data-testid="events-search-status"')
        ->assertDontSee('Friday night Helldivers')
        // Neither of the no-search empty states applies to a query with no
        // matches — "nothing is planned" would be a lie with an event aboard.
        ->assertDontSeeHtml('data-testid="events-empty-never"')
        ->assertDontSeeHtml('data-testid="events-empty-no-upcoming"');
});

it('echoes the query back escaped, not as markup', function () {
    upcomingEvent(['title' => 'Friday night Helldivers', 'description' => 'Weekly co-op chaos.']);

    $html = Livewire::test(EventsCalendar::class)
        ->set('search', '<script>alert("xss")</script>')
        ->html();

    expect($html)
        ->not->toContain('<script>alert')
        ->toContain('&lt;script&gt;');
});

it('treats a blank search as no search', function () {
    upcomingEvent(['title' => 'Friday night Helldivers', 'description' => 'Weekly co-op chaos.']);

    Livewire::test(EventsCalendar::class)
        ->set('search', '   ')
        ->assertSee('Friday night Helldivers')
        ->assertDontSeeHtml('data-testid="events-empty-search"')
        ->assertDontSeeHtml('data-testid="events-search-status"');
});

it('searches for a percent sign literally rather than matching everything', function () {
    upcomingEvent(['title' => 'Friday night Helldivers', 'description' => 'Weekly co-op chaos.']);
    upcomingEvent(['title' => 'Sunday Valorant scrims', 'description' => 'Tactical practice.']);

    Livewire::test(EventsCalendar::class)
        ->set('search', '%')
        ->assertSeeHtml('data-testid="events-empty-search"');
});

it('does not leak a draft title through search', function () {
    upcomingEvent(['title' => 'Unannounced raid night', 'description' => 'Secret plans.', 'status' => EventStatus::Draft]);
    upcomingEvent(['title' => 'Friday night Helldivers', 'description' => 'Weekly co-op chaos.']);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->set('search', 'raid')
        ->assertDontSee('Unannounced raid night')
        ->assertSeeHtml('data-testid="events-empty-search"');
});

it('shows matching past events without asking the member to open the drawer', function () {
    pastEvent(['title' => 'Old Helldivers night', 'description' => 'Last season co-op.']);

    Livewire::test(EventsCalendar::class)
        ->set('search', 'helldiv')
        ->assertSee('Old Helldivers night');
});

it('returns to the list view when a search starts', function () {
    upcomingEvent(['title' => 'Friday night Helldivers', 'description' => 'Weekly co-op chaos.']);

    Livewire::test(EventsCalendar::class)
        ->call('setView', 'calendar')
        ->assertSet('view', 'calendar')
        ->set('search', 'helldiv')
        ->assertSet('view', 'list');
});

it('keeps the open month when a search starts', function () {
    $event = upcomingEvent([
        'title' => 'Friday night Helldivers',
        'description' => 'Weekly co-op chaos.',
        'starts_at' => now()->addMonths(2),
        'ends_at' => now()->addMonths(2)->addHour(),
    ]);

    Livewire::test(EventsCalendar::class)
        ->set('search', 'helldiv')
        ->assertSet('month', $event->startsAtLocal()->format('Y-m'));
});

it('clears the search and brings the full list back', function () {
    upcomingEvent(['title' => 'Friday night Helldivers', 'description' => 'Weekly co-op chaos.']);
    upcomingEvent(['title' => 'Sunday Valorant scrims', 'description' => 'Tactical practice.']);

    Livewire::test(EventsCalendar::class)
        ->set('search', 'helldiv')
        ->assertDontSee('Sunday Valorant scrims')
        ->call('clearSearch')
        ->assertSet('search', '')
        ->assertSee('Sunday Valorant scrims');
});

/* ---------------------------------------------------------------------------
   Performance. The page is server-rendered and the counts must not be a query
   per card — this is the LCP budget, asserted rather than hoped for.
   --------------------------------------------------------------------------- */

it('does not run a query per event card', function () {
    // Capacity is set on purpose: an event with no cap never asks whether it is
    // full, so a capped fixture is the only one that exercises the seat count on
    // every card. Without it a per-card `select count(*)` slips through this test.
    Event::factory()->count(12)->create([
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
        'capacity' => 6,
    ]);

    DB::enableQueryLog();
    Livewire::actingAs($this->member)->test(EventsCalendar::class)->assertOk();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Twelve cards. A per-card count would put this well past twenty.
    expect($queries)->toBeLessThan(12);
});
