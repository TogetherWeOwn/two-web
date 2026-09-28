<?php

namespace App\Livewire;

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Support\Events\DiscordEventsSource;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The events page: a list and a month grid over the same rows.
 *
 * Server-rendered in one pass. There is deliberately no loading state on this
 * component and no fetch after paint — the LCP budget is 2.0s and the page's
 * largest element is the first event card, so anything that arrives in a second
 * round trip has already lost. The only loading state on this screen belongs to
 * the RSVP control, which is a thing the member started.
 *
 * The two views are one query rendered twice, not two components. A month grid
 * that asks its own question would disagree with the list beside it on the day an
 * event is published between the two queries.
 */
#[Layout('components.layouts.app', ['deferLivewire' => true, 'title' => 'Events — Together We Own'])]
class EventsCalendar extends Component
{
    /** `list` or `calendar`. The list is first because it is what works at 360px. */
    public string $view = 'list';

    /** The month the grid is showing, as `Y-m`. */
    public string $month = '';

    /** Whether the member has asked to see events that have already happened. */
    public bool $showingPast = false;

    /**
     * The search query, from `?q=`. Bound to the URL so a search is a link a
     * member can share, and so back/forward works. `except: ''` keeps the URL
     * clean when there is no search — `?q=` with nothing in it is noise.
     *
     * This is user input twice over: it arrives in the URL and it becomes SQL.
     * It is never interpolated — it goes through bindings via `whereLike` — and
     * the LIKE wildcards in it are escaped so `%` searches for a percent sign,
     * not for everything. Blade escapes it again on the way out, so echoing it
     * back in the results heading cannot become markup.
     */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    private const VIEWS = ['list', 'calendar'];

    public function mount(): void
    {
        // Open on the month the next event is actually in. Defaulting to today
        // shows an empty grid whenever the next game night is three weeks out,
        // which reads as "nothing is planned" while an event sits one click away.
        $this->month = ($this->upcoming()->first()?->startsAtLocal() ?? CarbonImmutable::now())
            ->format('Y-m');
    }

    public function setView(string $view): void
    {
        // An unknown name leaves the view alone rather than rendering nothing. This
        // is reachable from the URL, so it is input, not just a button.
        if (in_array($view, self::VIEWS, true)) {
            $this->view = $view;
        }
    }

    public function showPast(): void
    {
        $this->showingPast = true;
    }

    /**
     * A new search starts from the list, not from wherever the member was.
     * Search results are a list; landing on a month grid that may not contain
     * them would read as "no results" while matches sit one click away.
     */
    public function updatedSearch(): void
    {
        $this->view = 'list';
    }

    public function clearSearch(): void
    {
        $this->search = '';
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonth()->format('Y-m');
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonth()->format('Y-m');
    }

    public function render(): View
    {
        $upcoming = $this->upcoming();
        $past = $this->past();
        // A blank search is no search: spaces alone must not narrow the page to
        // nothing, and must not swap the empty states for the search one.
        $searching = trim($this->search) !== '';
        // While searching, matching past events show without opening the drawer:
        // a match hidden behind a closed drawer reads as "no results".
        $showPast = $this->showingPast || $searching;

        return view('livewire.events-calendar', [
            'upcoming' => $upcoming,
            'past' => $past,
            'weeks' => $this->weeks($upcoming->concat($past)),
            'monthLabel' => $this->monthStart()->format('F Y'),
            // Which of the two empty states applies. They are different messages:
            // one is "we are new", the other is "there was a last one". Neither
            // applies while searching — a query with no matches gets its own
            // message below, not "nothing is planned".
            'emptyState' => $searching
                ? null
                : ($upcoming->isEmpty() ? ($past->isEmpty() ? 'never' : 'no-upcoming') : null),
            // Whether the member currently sees anything. The past list only
            // counts once asked for — an unopened drawer is not results.
            'hasVisibleResults' => $upcoming->isNotEmpty() || ($showPast && $past->isNotEmpty()),
            'showPast' => $showPast,
            'searching' => $searching,
            'lastEventAgo' => $past->first()?->endsAtLocal()->diffForHumans(),
            // Share tags (TOG-5624). `layoutData` merges into the `#[Layout]`
            // params above — the attribute params win on conflict, but these keys
            // are new, so there is no conflict. `route()` builds from APP_URL,
            // never a hardcoded hostname.
        ])->layoutData([
            'canonical' => route('events.index'),
            'shareDescription' => 'Game nights, tournaments and whatever else the community puts on.',
        ]);
    }

    /**
     * Everything still to come, in the order it will happen.
     *
     * `withCount` rather than a count per card: twelve cards must not be twelve
     * queries, and the going count is on every one of them.
     *
     * Plus the guild's Discord-native events (TOG-5168): the page read only
     * its own table while the recurring Sunday Squad lived in the bot's
     * database, so production showed the never-scheduled empty state with a
     * live event sitting in Discord. The Discord rows are display-only
     * transients merged here, in start order with the local rows — never
     * persisted, never published, never handed to the write-back.
     *
     * @return Collection<int, Event>
     */
    private function upcoming(): Collection
    {
        $local = $this->visible()
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->get();

        $discord = collect(app(DiscordEventsSource::class)->upcoming())
            // The view already filters to scheduled/active within 90 days, but
            // the boundary is the bot's clock, not ours — re-check the end
            // against now so a just-started event cannot linger here forever
            // if the collector goes dark.
            ->filter(fn (Event $event): bool => $event->ends_at >= now());

        return $local->concat($discord)->sortBy(fn (Event $event): int => $event->starts_at->getTimestamp())->values();
    }

    /** @return Collection<int, Event> Most recent first — "the last one was…". */
    private function past(): Collection
    {
        return $this->visible()
            ->where('ends_at', '<', now())
            ->orderByDesc('starts_at')
            ->limit(20)
            ->get();
    }

    /** @return Builder<Event> */
    private function visible(): Builder
    {
        return Event::query()
            ->withCount(['rsvps as going_count' => fn ($query) => $query->where('status', RsvpStatus::Going)])
            // The viewer's own answer, once for the page. Each RSVP control would
            // otherwise fetch its own row, which is a query per card.
            ->with('viewerRsvps')
            // A draft has not been announced to anybody. Moderators see them so they
            // can check a card before publishing it; nobody else knows it exists.
            // This applies inside a search too: a member searching for a draft's
            // title learns nothing, not even that it exists.
            ->unless(
                Gate::allows('viewDrafts', Event::class),
                fn (Builder $query): Builder => $query->where('status', '!=', EventStatus::Draft->value),
            )
            ->when(
                trim($this->search) !== '',
                function (Builder $query): void {
                    // `whereLike` binds the value (never interpolated) and is
                    // `ilike` on Postgres, so casing is the database's problem,
                    // not the member's. The escape is ours, though: `%` and `_`
                    // in the query must match themselves, not act as wildcards.
                    $term = '%'.addcslashes(trim($this->search), '%_\\').'%';

                    $query->where(fn (Builder $nested): Builder => $nested
                        ->whereLike('title', $term)
                        ->orWhereLike('description', $term));
                },
            );
    }

    /**
     * The month grid: whole weeks, Monday first, with each day's events attached.
     *
     * Leading and trailing days from the neighbouring months are included and
     * flagged. A grid that starts mid-row is harder to read than one that shows
     * the 29th of last month greyed out.
     *
     * @param  Collection<int, Event>  $events
     * @return list<list<array{date: CarbonImmutable, inMonth: bool, isToday: bool, events: list<Event>}>>
     */
    private function weeks(Collection $events): array
    {
        $start = $this->monthStart();

        /** @var array<string, list<Event>> $byDay */
        $byDay = [];

        foreach ($events as $event) {
            $byDay[$event->startsAtLocal()->format('Y-m-d')][] = $event;
        }

        $cursor = $start->startOfWeek(CarbonImmutable::MONDAY);
        $end = $start->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);
        // The buckets above are keyed by each event's host-zone date, so today
        // has to be a host-zone date too. A server-zone Y-m-d lights the wrong
        // cell whenever the two zones disagree about what day it is — for a
        // London community on UTC servers, the small hours of every summer
        // morning. See calendarZone() for why this is the hosts' zone and not
        // the viewer's.
        $today = CarbonImmutable::now($this->calendarZone($events))->format('Y-m-d');

        $weeks = [];
        $week = [];

        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');

            $week[] = [
                'date' => $cursor,
                'inMonth' => $cursor->format('Y-m') === $start->format('Y-m'),
                'isToday' => $key === $today,
                'events' => $byDay[$key] ?? [],
            ];

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }

            $cursor = $cursor->addDay();
        }

        return $weeks;
    }

    /**
     * The zone the grid's "today" is evaluated in: the hosts' zone, not the
     * server's and not the viewer's.
     *
     * Times on this page are the wall clock in the zone the host chose — the
     * card partial, the share page, the JSON `starts_at_local` and the ICS all
     * contract that, and the `<time datetime>` instant beside each one lets any
     * viewer reinterpret it. A guest has no profile timezone, so per-viewer
     * rendering would fork this public page into two display modes anyway; and
     * `profiles.timezone` is collected so people know when to find each other
     * ("Add yours so people know when you are around"), not for rendering.
     *
     * Each event carries its own zone, but the highlight is one cell: the most
     * common zone among the events shown, which is the community's zone in
     * practice. No events, no hosts — the app zone, which is what an empty
     * grid has always used. An unknown identifier there falls back the same
     * way rather than fataling on a row written before validation existed.
     *
     * @param  Collection<int, Event>  $events
     */
    private function calendarZone(Collection $events): string
    {
        $counts = [];

        foreach ($events as $event) {
            // The column is a non-nullable string, so the only unusable value
            // is an empty one; anything else is validated against the IANA
            // list below rather than trusted.
            if ($event->timezone !== '') {
                $counts[$event->timezone] = ($counts[$event->timezone] ?? 0) + 1;
            }
        }

        if ($counts !== []) {
            arsort($counts);
            $zone = (string) array_key_first($counts);

            if (in_array($zone, DateTimeZone::listIdentifiers(), true)) {
                return $zone;
            }
        }

        return config('app.timezone', 'UTC');
    }

    /**
     * The first day of the month being shown.
     *
     * `$month` is a public Livewire property, so it is client input and can arrive
     * as anything. A string that will not parse falls back to this month rather
     * than throwing: a wrong month is a page, a fatal is not.
     */
    private function monthStart(): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01');
        } catch (InvalidFormatException) {
            // Carbon throws on an unparseable string rather than returning false, so
            // the guard has to be a catch. Without it a hand-edited `month` in the
            // Livewire payload is a 500 on a page that is meant to be public.
            $parsed = null;
        }

        return ($parsed instanceof CarbonImmutable ? $parsed : CarbonImmutable::now())
            ->startOfDay();
    }
}
