<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Livewire\EventsCalendar;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\Events\DiscordEventsSource;
use App\Support\Events\EventSearchLogger;
use Carbon\CarbonImmutable;
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

    // The Discord source is stubbed clean by default (no events, no failure)
    // so no test touches the real bot connection: without this every render
    // would attempt it, and an unreachable bot database would flip the page
    // into the error empty state. Tests that need rows or a failure say so
    // through `mockDiscordEvents`.
    mockDiscordEvents([]);
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
    // The Discord source is stubbed clean (no events, no failure) so the real
    // bot connection is never touched and the error state cannot leak in.
    mockDiscordEvents([]);

    $html = Livewire::test(EventsCalendar::class)->html();

    expect($html)
        ->not->toContain('role="alert"')
        ->not->toContain('data-testid="events-empty-error"')
        ->not->toContain('Couldn\'t load the events.')
        // "Never the word empty, nothing, no results alone" — COMPONENTS.md §8.
        ->not->toContain('No results')
        ->not->toContain('No events found');
});

/* ---------------------------------------------------------------------------
   Gap state (TOG-5318). No upcoming events, but there were some: the page
   reads as "between game nights", with the recent history inline.
   --------------------------------------------------------------------------- */

it('admits that past events exist rather than showing a bare empty upcoming list', function () {
    pastEvent();

    Livewire::test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="events-empty-gap"')
        ->assertSee('No upcoming events — check back soon.')
        ->assertSee('Join the Discord')
        // The wrong empty state here is the whole point of this test.
        ->assertDontSeeHtml('data-testid="events-empty-never"');
});

it('names the most recent past event with its date in the gap state', function () {
    $event = pastEvent([
        'title' => 'Last week s Valorant night',
        'starts_at' => now()->subDays(9)->subHours(2),
        'ends_at' => now()->subDays(9),
    ]);

    Livewire::test(EventsCalendar::class)
        ->assertSee('Last time:', escape: false)
        ->assertSee('Last week s Valorant night')
        ->assertSee($event->startsAtLocal()->format('D j M, H:i'), escape: false);
});

it('lists recent past events name-and-date in the gap state, newest first, capped at five', function () {
    // Seven older rows plus the newest: 8 past rows, so an uncapped list
    // would render 8 items inside the gap list. Each gap-list row carries
    // `data-testid="events-empty-gap-item"`, counted directly.
    Event::factory()->count(7)->create([
        'title' => 'Older night',
        'starts_at' => now()->subDays(30),
        'ends_at' => now()->subDays(30)->addHours(2),
        'status' => EventStatus::Published,
    ]);
    $latest = pastEvent(['title' => 'Most recent night']);

    $html = Livewire::test(EventsCalendar::class)->html();

    expect($html)->toContain('data-testid="events-empty-gap-list"')
        ->and($html)->toContain('Most recent night')
        ->and($html)->toContain($latest->startsAtLocal()->format('D j M, H:i'))
        ->and(substr_count($html, 'data-testid="events-empty-gap-item"'))->toBe(5)
        // Newest first: the most recent night opens the list.
        ->and(strpos($html, 'Most recent night'))
        ->toBeLessThan(strpos($html, 'Older night'));
});

it('keeps the List/Calendar toggle visible in the gap state', function () {
    pastEvent();

    Livewire::test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="events-view-list"')
        ->assertSeeHtml('data-testid="events-view-calendar"')
        ->call('setView', 'calendar')
        ->assertSeeHtml('data-testid="events-empty-gap"')
        ->assertSeeHtml('data-testid="events-calendar-grid"');
});

/* ---------------------------------------------------------------------------
   Error state (TOG-5318). A failed read must render the error, never the
   never-scheduled state — an unreadable calendar is not an empty one.
   --------------------------------------------------------------------------- */

it('renders the error state, never the never-scheduled one, when the Discord read fails', function () {
    mockDiscordEvents([], failed: true);

    Livewire::test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="events-empty-error"')
        // `assertSee` escapes the needle (`'` → `&#039;`) while the rendered
        // page carries the raw apostrophe, so assert the copy unescaped.
        ->assertSee("We couldn't load the calendar.", escape: false)
        ->assertSee('The Discord always has the latest — come ask there.')
        ->assertSee('Retry')
        ->assertSee('Join the Discord')
        ->assertDontSeeHtml('data-testid="events-empty-never"')
        ->assertDontSeeHtml('data-testid="events-empty-gap"');
});

it('keeps the List/Calendar toggle visible in the error state', function () {
    mockDiscordEvents([], failed: true);

    Livewire::test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="events-view-list"')
        ->assertSeeHtml('data-testid="events-view-calendar"')
        ->call('setView', 'calendar')
        ->assertSeeHtml('data-testid="events-empty-error"');
});

it('retries the read when asked, showing the calendar if the bot is back', function () {
    $source = Mockery::mock(DiscordEventsSource::class);
    $source->shouldReceive('upcoming')->andReturn([], [sundaySquadEvent()]);
    $source->shouldReceive('lastReadFailed')->andReturn(true, false);
    app()->instance(DiscordEventsSource::class, $source);

    Livewire::test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="events-empty-error"')
        ->call('retryLoad')
        ->assertSee('Sunday Squad')
        ->assertDontSeeHtml('data-testid="events-empty-error"');
});

it('keeps the calendar selection as the read moves through all three empty states', function () {
    $component = Livewire::test(EventsCalendar::class)
        ->call('setView', 'calendar')
        ->assertSet('view', 'calendar')
        ->assertSeeHtml('data-testid="events-empty-never"');

    pastEvent();
    $component->call('retryLoad')
        ->assertSet('view', 'calendar')
        ->assertSeeHtml('data-testid="events-empty-gap"');

    mockDiscordEvents([], failed: true);
    $component->call('retryLoad')
        ->assertSet('view', 'calendar')
        ->assertSeeHtml('data-testid="events-empty-error"')
        ->assertDontSeeHtml('data-testid="events-empty-gap"');

    mockDiscordEvents([]);
    $component->call('retryLoad')
        ->assertSet('view', 'calendar')
        ->assertSeeHtml('data-testid="events-empty-gap"')
        ->assertDontSeeHtml('data-testid="events-empty-error"');
});

it('does not describe a failed read as a search with no matches', function () {
    mockDiscordEvents([], failed: true);

    Livewire::test(EventsCalendar::class)
        ->set('search', 'squad')
        ->assertSeeHtml('data-testid="events-empty-error"')
        ->assertDontSeeHtml('data-testid="events-empty-search"')
        ->assertDontSeeHtml('data-testid="events-search-status"');
});

it('opens on a Discord-only event month without reading the source twice', function () {
    $event = sundaySquadEvent();
    $event->starts_at = now()->addMonths(2);
    $event->ends_at = now()->addMonths(2)->addHour();
    $source = Mockery::mock(DiscordEventsSource::class);
    $source->shouldReceive('upcoming')->once()->andReturn([$event]);
    $source->shouldReceive('lastReadFailed')->once()->andReturn(false);
    app()->instance(DiscordEventsSource::class, $source);

    Livewire::test(EventsCalendar::class)
        ->assertSet('month', $event->startsAtLocal()->format('Y-m'))
        ->assertSee('Sunday Squad');
});

it('hides a failed Discord read behind visible events rather than erroring the page', function () {
    upcomingEvent();
    mockDiscordEvents([], failed: true);

    Livewire::test(EventsCalendar::class)
        ->assertSee('Friday night Helldivers')
        ->assertDontSeeHtml('data-testid="events-empty-error"');
});

it('shows the past events once they are asked for', function () {
    pastEvent();

    // The gap state's inline history names the past event before `showPast`
    // is ever called, so the "hidden until asked" assertion targets the
    // full past list, not the title text.
    Livewire::test(EventsCalendar::class)
        ->assertDontSeeHtml('data-testid="events-past-list"')
        ->call('showPast')
        ->assertSeeHtml('data-testid="events-past-list"')
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

it('presents the view switcher as toggle buttons, not a radiogroup', function () {
    // TOG-6958: the switcher claimed role="radiogroup"/"radio", which promises
    // arrow-key handling and roving tabindex it never implemented. Plain
    // buttons with aria-pressed make no such promise.
    $html = Livewire::test(EventsCalendar::class)->html();

    expect($html)
        ->not->toContain('radiogroup')
        ->not->toContain('role="radio"')
        ->not->toContain('aria-checked')
        ->toContain('role="group"')
        ->toContain('aria-pressed="true"')
        ->toContain('aria-pressed="false"');
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
        ->assertDontSeeHtml('data-testid="events-empty-gap"');
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
   Loading state (TOG-5416). Member-started re-renders — view toggle, month
   steps, the past drawer, clearing a search — show a skeleton; debounced
   typing does not. Livewire toggles `wire:loading` elements only during a
   request and never at init, so the markup is asserted as markup: present
   but hidden up front, targeted at the actions, and typed as a polite
   status rather than an alert.
   --------------------------------------------------------------------------- */

it('renders the loading skeleton hidden, announced politely', function () {
    $html = Livewire::test(EventsCalendar::class)->html();

    expect($html)
        ->toContain('data-testid="events-loading"')
        ->toContain('role="status"')
        ->toContain('Loading events')
        ->not->toContain('role="alert"');
});

it('targets the member-started actions, not debounced typing', function () {
    $html = Livewire::test(EventsCalendar::class)->html();

    // One skeleton block, and its targets: the view toggle, the month
    // steps, the past drawer, clearing a search, and the error state's retry.
    expect(substr_count($html, 'data-testid="events-loading"'))->toBe(1);

    expect($html)
        ->toContain('wire:target="setView, previousMonth, nextMonth, showPast, clearSearch, retryLoad"')
        // Typing sets `search`, which is not a target: a skeleton flash on
        // every debounced keystroke is worse than the wait.
        ->not->toContain('wire:target="search"');
});

it('hides the skeleton and shows the content up front, never both', function () {
    $html = Livewire::test(EventsCalendar::class)->html();

    // Hidden up front by inline style (TOG-6351): Livewire never hides at
    // init, so without this every page load flashes the skeleton.
    expect($html)->toContain('data-testid="events-loading"')
        ->toContain('style="display: none"')
        // The live content carries the same targets on `wire:loading.remove`,
        // so the two can never co-render.
        ->toContain('data-testid="events-content"')
        ->toContain('wire:loading.remove.block');
});

it('disables the action controls while their answer is in flight', function () {
    // Each control is asserted in the state that renders it: the view toggle
    // always, the month steps in the calendar, the retry in the error state,
    // and the clear-search buttons only while searching. There is no past
    // button to assert — the drawer opens through the archive link and the
    // gap state's inline history — so `showPast` stays a skeleton target only.
    expect(Livewire::test(EventsCalendar::class)->html())
        ->toMatch('/wire:click="setView\(\'list\'\)"\s+wire:loading\.attr="disabled"\s+wire:target="setView"/')
        ->toMatch('/wire:click="setView\(\'calendar\'\)"\s+wire:loading\.attr="disabled"\s+wire:target="setView"/');

    expect(Livewire::test(EventsCalendar::class)->call('setView', 'calendar')->html())
        ->toMatch('/wire:click="previousMonth"\s+wire:loading\.attr="disabled"\s+wire:target="previousMonth"/')
        ->toMatch('/wire:click="nextMonth"\s+wire:loading\.attr="disabled"\s+wire:target="nextMonth"/');

    // Search first, while the read is clean: once the mock below flips the
    // read to failed, the error state takes precedence and the search empty
    // state (with its second clear button) no longer renders.
    expect(Livewire::test(EventsCalendar::class)->set('search', 'no such event')->html())
        ->toContain('data-testid="events-search-clear"')
        ->toContain('data-testid="events-search-clear-empty"')
        ->toMatch('/wire:click="clearSearch"\s+wire:loading\.attr="disabled"\s+wire:target="clearSearch"/');

    mockDiscordEvents([], failed: true);

    expect(Livewire::test(EventsCalendar::class)->html())
        ->toMatch('/wire:click="retryLoad"\s+wire:loading\.attr="disabled"\s+wire:target="retryLoad"/');
});

it('still renders the page after each loading-targeted action', function () {
    upcomingEvent();

    Livewire::test(EventsCalendar::class)
        ->call('setView', 'calendar')
        ->assertOk()
        ->assertSeeHtml('data-testid="events-loading"')
        ->assertSeeHtml('data-testid="events-content"')
        ->call('nextMonth')
        ->assertOk()
        ->call('previousMonth')
        ->assertOk()
        ->call('setView', 'list')
        ->assertOk()
        ->call('showPast')
        ->assertOk()
        ->call('clearSearch')
        ->assertOk();
});

/* ---------------------------------------------------------------------------
   Live-region announcements (TOG-7332). The list <-> calendar swap, the month
   steps and the past drawer all re-render without reloading, so each change
   has to be named for screen readers — politely (role="status"), never as an
   alert.
   --------------------------------------------------------------------------- */

it('names the current view in a polite live region', function () {
    Livewire::test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="events-view-status"')
        ->assertSee('Showing events as a list.')
        ->call('setView', 'calendar')
        ->assertSee('Showing events as a calendar.');
});

it('announces the past-events reveal, and stays silent until asked', function () {
    pastEvent();

    // Empty until asked, so the initial load announces nothing.
    $html = Livewire::test(EventsCalendar::class)->html();

    expect($html)->toContain('data-testid="events-past-status"')
        ->not->toContain('Showing past events.');

    Livewire::test(EventsCalendar::class)
        ->call('showPast')
        ->assertSee('Showing past events.');
});

it('announces month steps through a polite month status', function () {
    // Attribute- and whitespace-tolerant on purpose: the sibling strpos test
    // proves the element renders, while a byte-exact `">…</p>"` regex missed
    // Livewire's serialized markup (red on 112b81a). Livewire also wraps the
    // `@if` output in `<!--[if BLOCK]-->` markers, which are stripped before
    // comparing — what matters here is the announced text, not the tag shape.
    $status = fn ($component) => preg_match(
        '/data-testid="calendar-month-status"[^>]*>\s*(.*?)\s*<\/p>/s', $component->html(), $m
    ) ? trim(preg_replace('/<!--.*?-->/s', '', $m[1])) : null;
    $label = fn ($component) => CarbonImmutable::createFromFormat('Y-m-d', $component->get('month').'-01')
        ->format('F Y');

    $component = Livewire::test(EventsCalendar::class)->call('setView', 'calendar');
    expect($status($component))->toBe($label($component));

    $component->call('nextMonth');
    expect($status($component))->toBe($label($component));

    // The list view has no month to announce. The empty `@if` still
    // serializes Livewire's block-comment markers, so those are allowed
    // between the tags — same brittleness as the extractor above.
    expect(Livewire::test(EventsCalendar::class)->html())
        ->toMatch('/data-testid="calendar-month-status"[^>]*>(?:\s|<!--.*?-->)*<\/p>/s');
});

it('keeps the live regions outside the wrapper hidden mid-request', function () {
    // TOG-5416: `events-content` goes display:none while a request is in
    // flight. A live region inside it is hidden, or swapped in hidden, and is
    // not reliably announced, so every region sits before the wrapper opens.
    $html = Livewire::test(EventsCalendar::class)->call('setView', 'calendar')->html();
    $wrapper = strpos($html, 'data-testid="events-content"');

    foreach (['events-view-status', 'events-past-status', 'calendar-month-status'] as $region) {
        $at = strpos($html, 'data-testid="'.$region.'"');
        expect($at)->not->toBeFalse()->toBeLessThan($wrapper);
    }

    // The visible month label inside the wrapper is no longer the live one.
    expect($html)->not->toMatch('/aria-live="polite"\s+data-testid="calendar-month"/');
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

/* ---------------------------------------------------------------------------
   Discord-native rows (TOG-5168). The guild's recurring event lives in the
   bot's database, not in ours — these pin that the page shows it anyway.
   --------------------------------------------------------------------------- */

function sundaySquadEvent(): Event
{
    $event = new Event([
        'title' => 'Sunday Squad',
        'description' => 'Fall Guys for about an hour.',
        'starts_at' => now()->addDays(4),
        'ends_at' => now()->addDays(4)->addHour(),
        'timezone' => 'UTC',
        'location' => 'Discord',
        'capacity' => null,
        'status' => EventStatus::Published,
        'discord_event_id' => '1545955994972987422',
    ]);
    $event->setAttribute('event_key', 'discord:1545955994972987422');
    $event->setAttribute('going_count', null);
    $event->exists = false;

    return $event;
}

function mockDiscordEvents(array $events, bool $failed = false): void
{
    $source = Mockery::mock(DiscordEventsSource::class);
    $source->shouldReceive('upcoming')->andReturn($events);
    $source->shouldReceive('lastReadFailed')->andReturn($failed);
    app()->instance(DiscordEventsSource::class, $source);
}

it('lists the guild Sunday Squad event even when our own table is empty', function () {
    mockDiscordEvents([sundaySquadEvent()]);

    Livewire::test(EventsCalendar::class)
        ->assertSee('Sunday Squad')
        ->assertSeeHtml('data-testid="event-card"')
        ->assertSeeHtml('data-event-key="discord:1545955994972987422"')
        // The empty state must not render alongside a live event.
        ->assertDontSeeHtml('data-testid="events-empty-never"');
});

it('applies the same literal search to local and Discord titles and descriptions', function (string $field, string $copy, string $term) {
    $local = upcomingEvent(['title' => 'Local match', 'description' => null, $field => $copy]);
    $discord = sundaySquadEvent();
    $discord->fill(['title' => 'Discord match', 'description' => null, $field => $copy]);
    $unrelated = sundaySquadEvent();
    $unrelated->setAttribute('event_key', 'discord:1545955994972987423');
    upcomingEvent(['title' => 'Unrelated local event', 'description' => null]);
    mockDiscordEvents([$discord, $unrelated]);

    Livewire::test(EventsCalendar::class)
        ->set('search', $term)
        ->assertViewHas('upcoming', fn ($events): bool => $events->count() === 2 && $events->first()->is($local) && $events->last() === $discord)
        ->assertDontSee('Unrelated local event')
        ->assertDontSeeHtml('data-event-key="discord:1545955994972987423"')
        ->assertDontSeeHtml('data-testid="events-empty-search"');

    $this->assertDatabaseHas('event_search_logs', [
        'normalized_query' => app(EventSearchLogger::class)->normalize($term),
        'result_count' => 2,
    ]);
})->with([
    'title fragment and casing' => ['title', 'Friday HELLDIVERS night', 'helldiv'],
    'description fragment and trimming' => ['description', 'Bring extra AMMO tonight.', '  ammo  '],
    'multibyte title casing' => ['title', 'Équipe gaming night', 'éQUIPE'],
    'literal percent' => ['title', '100% co-op night', '%'],
    'literal underscore' => ['description', 'Bring your squad_name.', '_'],
    'literal backslash' => ['description', 'Use squad\\name.', '\\'],
]);

it('preserves PostgreSQL ordinary and final sigma matching across both sources', function (string $field, string $copy, string $term, bool $matches) {
    $local = upcomingEvent(['title' => 'Local sigma', 'description' => null, $field => $copy]);
    $discord = sundaySquadEvent();
    $discord->fill(['title' => 'Discord sigma', 'description' => null, $field => $copy]);
    mockDiscordEvents([$discord]);

    Livewire::test(EventsCalendar::class)
        ->set('search', $term)
        ->assertViewHas('upcoming', fn ($events): bool => $matches
            ? $events->count() === 2 && $events->first()->is($local) && $events->last() === $discord
            : $events->isEmpty())
        ->assertViewHas('hasVisibleResults', $matches);

    $this->assertDatabaseHas('event_search_logs', [
        'normalized_query' => app(EventSearchLogger::class)->normalize($term),
        'result_count' => $matches ? 2 : 0,
    ]);
})->with([
    'uppercase title versus ordinary sigma' => ['title', 'ΟΣ', 'οσ', true],
    'uppercase title versus final sigma' => ['title', 'ΟΣ', 'ος', false],
    'uppercase description versus ordinary sigma' => ['description', 'ΟΣ', 'οσ', true],
    'uppercase description versus final sigma' => ['description', 'ΟΣ', 'ος', false],
    'final sigma title stays distinct from ordinary sigma' => ['title', 'ος', 'οσ', false],
    'final sigma description matches literally' => ['description', 'ος', 'ος', true],
]);

it('matches Discord search copy in one read without persisting transient rows', function () {
    $events = [];

    for ($index = 0; $index < 12; $index++) {
        $event = sundaySquadEvent();
        $event->setAttribute('event_key', 'discord:'.(1545955994972987422 + $index));
        $events[] = $event;
    }

    // Filtering retains the source keys; the SQL matches must use those keys,
    // not offsets in the remaining list after an expired row is removed.
    $events[0]->ends_at = now()->subMinute();
    mockDiscordEvents($events);
    $component = Livewire::test(EventsCalendar::class);

    DB::enableQueryLog();
    $component->set('search', 'squad')
        ->assertViewHas('upcoming', fn ($rows): bool => $rows->count() === 11 && $rows->first() === $events[1] && $rows->last() === $events[11]);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries->filter(fn (array $query): bool => str_contains($query['query'], '"discord_search"')))->toHaveCount(1);
    $this->assertDatabaseCount('events', 0);
});

it('skips the Discord search query for empty rows or a blank search', function (bool $hasRows, string $term) {
    mockDiscordEvents($hasRows ? [sundaySquadEvent()] : []);
    $component = Livewire::test(EventsCalendar::class);

    DB::enableQueryLog();
    $component->set('search', $term)
        ->assertViewHas('upcoming', fn ($rows): bool => $rows->count() === ($hasRows ? 1 : 0));
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries->filter(fn (array $query): bool => str_contains($query['query'], '"discord_search"')))->toHaveCount(0);
    $this->assertDatabaseCount('events', 0);
})->with([
    'no source rows' => [false, 'squad'],
    'empty search' => [true, ''],
    'whitespace search' => [true, '   '],
]);

it('filters both sources when opening a shareable search URL', function () {
    upcomingEvent(['description' => null]);
    mockDiscordEvents([sundaySquadEvent()]);

    $this->get(route('events.index', ['q' => 'FALL GUYS']))
        ->assertOk()
        ->assertSee('Sunday Squad')
        ->assertDontSee('Friday night Helldivers')
        ->assertDontSeeHtml('data-testid="events-empty-search"');

    $this->assertDatabaseHas('event_search_logs', [
        'normalized_query' => 'fall guys',
        'result_count' => 1,
    ]);
});

it('shows a healthy mixed-source search empty state and logs zero matches', function () {
    upcomingEvent(['description' => null]);
    $discord = sundaySquadEvent();
    $discord->description = null;
    mockDiscordEvents([$discord]);

    Livewire::test(EventsCalendar::class)
        ->set('search', 'zzz-no-such-event-zzz')
        ->assertViewHas('upcoming', fn ($events): bool => $events->isEmpty())
        ->assertSeeHtml('data-testid="events-empty-search"')
        ->assertSee('Nothing matches that search.')
        ->assertDontSee('Friday night Helldivers')
        ->assertDontSee('Sunday Squad')
        ->assertDontSeeHtml('data-testid="events-empty-never"')
        ->assertDontSeeHtml('data-testid="events-empty-gap"')
        ->assertDontSeeHtml('data-testid="events-empty-error"');

    $this->assertDatabaseHas('event_search_logs', [
        'normalized_query' => 'zzz-no-such-event-zzz',
        'result_count' => 0,
    ]);
});

it('clears a mixed-source search and restores both sources in start order', function () {
    upcomingEvent(['description' => null]);
    mockDiscordEvents([sundaySquadEvent()]);

    Livewire::test(EventsCalendar::class)
        ->set('search', 'helldiv')
        ->assertSee('Friday night Helldivers')
        ->assertDontSee('Sunday Squad')
        ->call('clearSearch')
        ->assertSet('search', '')
        ->assertViewHas('upcoming', fn ($events): bool => $events->pluck('title')->all() === ['Friday night Helldivers', 'Sunday Squad'])
        ->assertSee('Friday night Helldivers')
        ->assertSee('Sunday Squad')
        ->assertDontSeeHtml('data-testid="events-search-status"');
});

it('keeps both sources for a whitespace-only search', function () {
    upcomingEvent(['description' => null]);
    mockDiscordEvents([sundaySquadEvent()]);

    Livewire::test(EventsCalendar::class)
        ->set('search', '   ')
        ->assertSee('Friday night Helldivers')
        ->assertSee('Sunday Squad')
        ->assertDontSeeHtml('data-testid="events-search-status"');
});

it('does not revive an ended Discord event when its title matches the search', function () {
    $discord = sundaySquadEvent();
    $discord->ends_at = now()->subMinute();
    mockDiscordEvents([$discord]);

    Livewire::test(EventsCalendar::class)
        ->set('search', 'squad')
        ->assertSeeHtml('data-testid="events-empty-search"')
        ->assertDontSeeHtml('data-event-key="discord:1545955994972987422"');
});

it('renders the Sunday Squad start in the same visitor format as local cards', function () {
    $event = sundaySquadEvent();
    mockDiscordEvents([$event]);

    Livewire::test(EventsCalendar::class)
        ->assertSee($event->startsAtLocal()->format('D j M, H:i'), escape: false)
        ->assertSeeHtml('datetime="'.$event->starts_at->toIso8601String().'"');
});

it('points the Sunday Squad card at Discord instead of a broken RSVP', function () {
    mockDiscordEvents([sundaySquadEvent()]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="event-discord-rsvp"')
        ->assertSee('RSVP in Discord')
        // The going count is unknown, not zero — no badge, not "0 going".
        ->assertDontSeeHtml('data-testid="event-going-count"');
});

it('orders Discord rows with local rows by start time', function () {
    upcomingEvent(['title' => 'Friday night Helldivers', 'starts_at' => now()->addDays(1), 'ends_at' => now()->addDays(1)->addHours(2)]);
    mockDiscordEvents([sundaySquadEvent()]);

    $html = Livewire::test(EventsCalendar::class)->html();

    expect($html)->toContain('Friday night Helldivers')
        ->and(strpos($html, 'Friday night Helldivers'))->toBeLessThan(strpos($html, 'Sunday Squad'));
});

it('renders the error state, not the never-scheduled one, when the bot database is unreachable', function () {
    // TOG-5318 supersedes the old degrade-to-empty contract: a failed read is
    // the error empty state, never E1.
    $source = Mockery::mock(DiscordEventsSource::class);
    $source->shouldReceive('upcoming')->andReturn([]);
    $source->shouldReceive('lastReadFailed')->andReturn(true);
    app()->instance(DiscordEventsSource::class, $source);

    Livewire::test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="events-empty-error"')
        ->assertDontSeeHtml('data-testid="events-empty-never"')
        ->assertOk();
});

it('still offers RSVP on local cards when a Discord row is present', function () {
    upcomingEvent();
    mockDiscordEvents([sundaySquadEvent()]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="rsvp-going"');
});
