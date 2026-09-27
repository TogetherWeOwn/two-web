<?php

namespace App\Livewire;

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

        return view('livewire.events-calendar', [
            'upcoming' => $upcoming,
            'past' => $past,
            'weeks' => $this->weeks($upcoming->concat($past)),
            'monthLabel' => $this->monthStart()->format('F Y'),
            // Which of the two empty states applies. They are different messages:
            // one is "we are new", the other is "there was a last one".
            'emptyState' => $upcoming->isEmpty() ? ($past->isEmpty() ? 'never' : 'no-upcoming') : null,
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
     * @return Collection<int, Event>
     */
    private function upcoming(): Collection
    {
        return $this->visible()
            ->where('ends_at', '>=', now())
            ->orderBy('starts_at')
            ->get();
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
