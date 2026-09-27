<?php

namespace App\Livewire;

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Support\Events\DiscordEventsSource;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
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

    private const VIEWS = ['list', 'calendar'];

    public function mount(): void
    {
        // Open on the month the next event is actually in. Defaulting to today
        // shows an empty grid whenever the next game night is three weeks out,
        // which reads as "nothing is planned" while an event sits one click away.
        //
        // Only local rows are read here, deliberately. A Discord fetch in
        // `mount()` would consume the mock's scripted read before `render()`
        // ever runs — the error state would never render in tests, and a
        // retry could never show the recovered event. The month may lag the
        // Discord rows by one render on first paint; correctness of the
        // empty-vs-failure branch matters more than the grid's opening month.
        $this->month = ($this->upcoming(collect())->first()?->startsAtLocal() ?? CarbonImmutable::now())
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
     * The error empty state's Retry (TOG-5318): re-fires the read rather than
     * re-rendering the failure. The outcome is decided fresh on the next
     * render — a recovered bot database shows events, a still-dark one shows
     * the error again — so there is nothing here to set beyond letting the
     * component render again.
     */
    public function retryLoad(): void
    {
        // Intentionally empty: the Livewire round trip re-runs `render()`,
        // which re-reads both sources. A method with a body would imply the
        // retry needs local state; it does not.
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
        // One resolve per render: the source is bound transient, so each
        // `app()` call is a fresh reader with a fresh failure flag. The
        // rows and the flag MUST come from the same instance — asking a
        // second resolve whether the first one's read failed is always "no",
        // which would silently turn every error state into the
        // never-scheduled one.
        $discord = app(DiscordEventsSource::class);
        $discordRows = collect($discord->upcoming())
            // The view already filters to scheduled/active within 90 days, but
            // the boundary is the bot's clock, not ours — re-check the end
            // against now so a just-started event cannot linger here forever
            // if the collector goes dark.
            ->filter(fn (Event $event): bool => $event->ends_at >= now());
        $discordFailed = $discord->lastReadFailed();

        $upcoming = $this->upcoming($discordRows);
        $past = $this->past();

        return view('livewire.events-calendar', [
            'upcoming' => $upcoming,
            'past' => $past,
            'weeks' => $this->weeks($upcoming->concat($past)),
            'monthLabel' => $this->monthStart()->format('F Y'),
            // Which of the three empty states applies (TOG-5318). A failed
            // Discord read must render the error state, never the
            // never-scheduled one — an unreadable calendar is not an empty
            // one. The flag only matters when nothing upcoming is shown: a
            // failure beside visible events is invisible by design.
            'emptyState' => $upcoming->isEmpty()
                ? ($discordFailed ? 'error' : ($past->isEmpty() ? 'never' : 'gap'))
                : null,
            // The gap state's "Last time:" line: the most recent past event.
            // Past rows are newest first, so this is the head of the same
            // collection the list below renders.
            'lastPastEvent' => $past->first(),
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
     * @param  SupportCollection<int, Event>  $discordRows  Already fetched from the
     *                                                      render's single source resolve.
     * @return SupportCollection<int, Event>
     */
    private function upcoming(SupportCollection $discordRows): SupportCollection
    {
        $local = $this->visible()
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->get();

        return $local->concat($discordRows)->sortBy(fn (Event $event): int => $event->starts_at->getTimestamp())->values();
    }

    /** @return EloquentCollection<int, Event> Most recent first — "the last one was…". */
    private function past(): EloquentCollection
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
            ->unless(
                Gate::allows('viewDrafts', Event::class),
                fn (Builder $query): Builder => $query->where('status', '!=', EventStatus::Draft->value),
            );
    }

    /**
     * The month grid: whole weeks, Monday first, with each day's events attached.
     *
     * Leading and trailing days from the neighbouring months are included and
     * flagged. A grid that starts mid-row is harder to read than one that shows
     * the 29th of last month greyed out.
     *
     * @param  SupportCollection<int, Event>  $events
     * @return list<list<array{date: CarbonImmutable, inMonth: bool, isToday: bool, events: list<Event>}>>
     */
    private function weeks(SupportCollection $events): array
    {
        $start = $this->monthStart();

        /** @var array<string, list<Event>> $byDay */
        $byDay = [];

        foreach ($events as $event) {
            $byDay[$event->startsAtLocal()->format('Y-m-d')][] = $event;
        }

        $cursor = $start->startOfWeek(CarbonImmutable::MONDAY);
        $end = $start->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);
        $today = CarbonImmutable::now()->format('Y-m-d');

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
