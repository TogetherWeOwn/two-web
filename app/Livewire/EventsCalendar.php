<?php

namespace App\Livewire;

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Exceptions\EventAtCapacityException;
use App\Exceptions\EventNotOpenException;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\EventService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The events calendar and the RSVP flow.
 *
 * Two views of one list — a list and a month grid — because the same question is
 * asked two ways: "what is on soon" and "what is on that weekend". They share a
 * query and a set of states rather than being two components, so a state that is
 * handled in one cannot be missing from the other.
 *
 * ## Authorisation is not re-implemented here
 *
 * Every rule this component appears to enforce is enforced somewhere that is not a
 * component: EventPolicy decides whether a draft is visible, RsvpPolicy decides
 * whether a member may answer, and EventService decides whether a seat is free.
 * What is here is which of those answers becomes which sentence. A component that
 * decided any of it would be a second copy of the rule, live for one caller.
 *
 * ## What "RSVP failed" actually means
 *
 * The card asked for a state called "RSVP failed because the bot is unreachable".
 * Measured against this codebase, that state cannot occur: an RSVP does not go
 * through the bot. EventService::rsvp() commits the row and dispatches
 * SyncEventToDiscord after the commit, and that job treats an unreachable bot as a
 * wait rather than a failure — it releases itself back onto the queue and never
 * fail()s (app/Jobs/SyncEventToDiscord.php:112). The member's answer is safe
 * whether or not Discord is reachable.
 *
 * So the honest states are not the ones the card named:
 *
 *  - **pending sync** — we have the answer, Discord does not know yet. This is what
 *    `rsvps.synced_to_discord_at` is for, and the README commits us to telling the
 *    truth about it. It is a bot-is-down message that does not lie about the RSVP.
 *  - **at capacity** — the seat went to somebody else, EventAtCapacityException.
 *  - **not open** — cancelled or unpublished between render and click,
 *    EventNotOpenException.
 *
 * Showing "your RSVP failed" when the bot is down would be false, and the member
 * would answer again — which is the one thing the pending state exists to prevent.
 */
class EventsCalendar extends Component
{
    /** `list` or `calendar`. In the URL so a shared link keeps the view it was shared in. */
    #[Url]
    public string $mode = 'list';

    /** The month the grid is showing, as `Y-m`. Ignored in list mode. */
    #[Url]
    public string $month = '';

    /**
     * Per-event failure messages, keyed by event_key.
     *
     * Per-event rather than one banner because the page shows many events and a
     * message at the top of it would not say which one it was about.
     *
     * @var array<string, string>
     */
    public array $rsvpErrors = [];

    public function mount(): void
    {
        if (! in_array($this->mode, ['list', 'calendar'], true)) {
            $this->mode = 'list';
        }

        if ($this->parseMonth($this->month) === null) {
            $this->month = CarbonImmutable::now()->format('Y-m');
        }
    }

    public function showList(): void
    {
        $this->mode = 'list';
    }

    public function showCalendar(): void
    {
        $this->mode = 'calendar';
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonth()->format('Y-m');
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonth()->format('Y-m');
    }

    /**
     * Record an answer.
     *
     * Takes the event_key and a raw status string because that is what a DOM event
     * can carry. Both are validated here: a member can send anything, and "the
     * template only ever sends valid values" is not a check.
     */
    public function rsvp(string $eventKey, string $status): void
    {
        unset($this->rsvpErrors[$eventKey]);

        $user = auth()->user();

        if (! $user instanceof User) {
            $this->rsvpErrors[$eventKey] = 'Sign in with Discord to answer.';

            return;
        }

        $answer = RsvpStatus::tryFrom($status);

        if (! $answer instanceof RsvpStatus) {
            // Not a message for the member: there is no way to reach this from the
            // page, so it is a broken client or somebody poking at it.
            throw ValidationException::withMessages(['status' => 'Not an answer.']);
        }

        $event = $this->findEvent($eventKey);

        if (! $event instanceof Event) {
            return;
        }

        if (! Gate::forUser($user)->allows('create', [Rsvp::class, $event, $user])) {
            $this->rsvpErrors[$eventKey] = 'This event is not open for answers.';

            return;
        }

        try {
            app(EventService::class)->rsvp($event, $user, $answer);
        } catch (EventAtCapacityException) {
            // Ordinary: two members answered at the same moment and there was one
            // seat. Not an error state of ours.
            $this->rsvpErrors[$eventKey] = 'This event is full — the last seat went to somebody else.';
        } catch (EventNotOpenException) {
            $this->rsvpErrors[$eventKey] = 'This event is no longer open for answers.';
        }
    }

    public function withdraw(string $eventKey): void
    {
        unset($this->rsvpErrors[$eventKey]);

        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $event = $this->findEvent($eventKey);

        if (! $event instanceof Event) {
            return;
        }

        app(EventService::class)->withdrawRsvp($event, $user);
    }

    public function render(): View
    {
        $events = $this->events();

        return view('livewire.events-calendar', [
            'events' => $events,
            'weeks' => $this->mode === 'calendar' ? $this->weeks($events) : collect(),
            'monthLabel' => $this->monthStart()->format('F Y'),
        ]);
    }

    /**
     * Everything the page may show, with the seat counts and the viewer's own
     * answer already attached.
     *
     * `withCount` and `with` rather than a method call per card: a `goingCount()`
     * inside the template is one query per event, which is the N+1 that turns a
     * busy month into a page that misses its LCP budget. There is a test for the
     * query count, because this is the kind of thing that regresses silently.
     *
     * @return Collection<int, Event>
     */
    protected function events(): Collection
    {
        $user = auth()->user();

        return Event::query()
            ->withCount([
                'rsvps as going_count' => fn (Builder $query): Builder => $query
                    ->where('status', RsvpStatus::Going->value),
            ])
            ->with([
                // The viewer's own answer, as a one-element relation rather than a
                // lookup per card. Absent for a guest, which is what `whereRaw(0=1)`
                // buys — an eager load that is guaranteed empty still costs one
                // query, and skipping it is not worth a second code path.
                'rsvps' => fn ($query) => $user instanceof User
                    ? $query->where('user_id', $user->getKey())
                    : $query->whereRaw('1 = 0'),
            ])
            ->unless(
                Gate::forUser($user)->allows('viewDrafts', Event::class),
                fn (Builder $query): Builder => $query->where('status', '!=', EventStatus::Draft->value),
            )
            // Cancelled events stay visible: somebody who RSVPed needs to find out it
            // is off, and a card that silently vanishes tells them nothing.
            //
            // Finished events do not. `ends_at` rather than `starts_at` so an event
            // that is happening right now is still on the page while it happens.
            ->where('ends_at', '>=', CarbonImmutable::now())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * The month grid: whole weeks, Monday first, each day carrying the events that
     * start on it.
     *
     * A day is decided in the *event's* own timezone, not UTC and not the viewer's.
     * 00:30 on 1 July in London is 23:30 on 30 June in UTC, so a grid built off the
     * instant files that event under the wrong day — and only for half the year,
     * which is the half nobody tests in.
     *
     * @param  Collection<int, Event>  $events
     * @return Collection<int, non-empty-list<array{date: CarbonImmutable, inMonth: bool, isToday: bool, events: Collection<int, Event>}>>
     */
    protected function weeks(Collection $events): Collection
    {
        $start = $this->monthStart();
        $gridStart = $start->startOfWeek(Carbon::MONDAY);
        $gridEnd = $start->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $byDay = $events->groupBy(fn (Event $event): string => $event->startsAtLocal()->toDateString());

        $today = CarbonImmutable::now()->toDateString();

        $days = [];

        for ($day = $gridStart; $day->lessThanOrEqualTo($gridEnd); $day = $day->addDay()) {
            $key = $day->toDateString();

            $days[] = [
                'date' => $day,
                'inMonth' => $day->format('Y-m') === $start->format('Y-m'),
                'isToday' => $key === $today,
                'events' => $byDay->get($key, collect()),
            ];
        }

        // array_chunk rather than Collection::chunk: the weeks are seven positional
        // slots that the grid reads in order, and array_chunk renumbers them, which
        // is what the return type promises.
        return collect(array_chunk($days, 7));
    }

    /** The viewer's own answer to an event, or null — see the eager load in events(). */
    public function answerFor(Event $event): ?Rsvp
    {
        return $event->relationLoaded('rsvps') ? $event->rsvps->first() : null;
    }

    /**
     * Whether to tell *this* viewer the event is full.
     *
     * Somebody who already holds a seat is not affected by the event being at
     * capacity, and "FULL" stamped next to their own confirmed place reads as a
     * mistake in the page.
     */
    public function isFullFor(Event $event): bool
    {
        if ($event->capacity === null) {
            return false;
        }

        if ($this->answerFor($event)?->status === RsvpStatus::Going) {
            return false;
        }

        return $this->goingCount($event) >= $event->capacity;
    }

    public function goingCount(Event $event): int
    {
        // withCount() puts it on the model as an attribute; the fallback is for a
        // model that reached the view some other way.
        return (int) ($event->going_count ?? $event->goingCount());
    }

    public function spotsRemaining(Event $event): ?int
    {
        return $event->capacity === null
            ? null
            : max(0, $event->capacity - $this->goingCount($event));
    }

    private function findEvent(string $eventKey): ?Event
    {
        $event = Event::query()->where('event_key', $eventKey)->first();

        if (! $event instanceof Event) {
            return null;
        }

        // A draft is not visible, so it is also not answerable, and a member who
        // guessed a key should not learn from the message that it exists.
        return Gate::forUser(auth()->user())->allows('view', $event) ? $event : null;
    }

    private function monthStart(): CarbonImmutable
    {
        return $this->parseMonth($this->month) ?? CarbonImmutable::now()->startOfMonth();
    }

    private function parseMonth(string $month): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01');
        } catch (\Throwable) {
            return null;
        }

        // createFromFormat returns false (typed as null here) on a date that matches
        // the shape but is not a date. "2026-13" gets past the regex.
        return $parsed?->startOfDay();
    }
}
