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
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Livewire\Component;
use Throwable;

/**
 * The events calendar and the RSVP round trip.
 *
 * This is the member-facing half of TOG-52. It talks to EventService directly
 * rather than to the JSON routes, so the capacity rule and the row lock are the
 * same code the API and the reconcile command use — a second copy of "is this
 * event full" is a second answer waiting to disagree.
 *
 * The one contract worth stating plainly, because it is the easiest thing here to
 * get backwards: **an RSVP that Discord has not seen yet has not failed.** The row
 * commits, the write-back is dispatched afterwards, and SyncEventToDiscord
 * *releases* a transport failure for retry rather than failing it. So a null
 * `synced_to_discord_at` means "saved here, catching up there" and renders as a
 * quiet note next to a confirmed RSVP. The failure message below is reserved for
 * a write that genuinely did not happen; using it for a Discord outage would tell
 * a member their seat is gone when it is not.
 */
class EventsCalendar extends Component
{
    /**
     * The event the last action failed on, and how — keyed by `event_key` so a
     * failure stays attached to the card it came from rather than becoming a
     * page-level banner about the wrong event.
     *
     * @var array<string, string>
     */
    public array $failures = [];

    /**
     * Answer for the current member, or take a seat.
     *
     * Guests fall through: the button is not rendered for them, but a Livewire
     * method is a public HTTP endpoint and the hidden button is not the control.
     */
    public function rsvp(string $eventKey): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $event = $this->openEvent($eventKey);

        if (! $event instanceof Event) {
            return;
        }

        unset($this->failures[$eventKey]);

        try {
            app(EventService::class)->rsvp($event, $user, RsvpStatus::Going);
        } catch (EventAtCapacityException) {
            // Not an error: somebody else got the last seat. Offering a retry here
            // would be offering a seat that does not exist.
            $this->failures[$eventKey] = 'full';
        } catch (EventNotOpenException) {
            // "Not now" rather than "it broke". The re-render shows the event's
            // real state, which is the honest answer.
            $this->failures[$eventKey] = 'closed';
        } catch (Throwable $e) {
            // The only path that may tell a member their answer did not save.
            Log::warning('RSVP failed to save.', [
                'event_key' => $eventKey,
                'user_id' => $user->getKey(),
                'reason' => $e->getMessage(),
            ]);

            $this->failures[$eventKey] = 'unsaved';
        }
    }

    public function withdraw(string $eventKey): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $event = $this->openEvent($eventKey);

        if (! $event instanceof Event) {
            return;
        }

        unset($this->failures[$eventKey]);

        try {
            app(EventService::class)->withdrawRsvp($event, $user);
        } catch (Throwable $e) {
            Log::warning('RSVP withdrawal failed.', [
                'event_key' => $eventKey,
                'user_id' => $user->getKey(),
                'reason' => $e->getMessage(),
            ]);

            $this->failures[$eventKey] = 'unsaved';
        }
    }

    public function render(): View
    {
        $events = $this->visibleEvents();
        $now = now();

        // Split rather than filtered, because the empty state depends on knowing
        // whether the club has a past: "nothing yet" and "nothing right now" are
        // different messages and only one of them is true at a time.
        //
        // Two filters rather than partition(): partition returns a two-element
        // collection that static analysis can only type as "possibly null" once
        // destructured, and the honest fix is not to destructure it.
        $isUpcoming = fn (Event $event): bool => $event->status !== EventStatus::Past
            && $event->ends_at >= $now;

        $upcoming = $events->filter($isUpcoming);
        $past = $events->reject($isUpcoming);

        return view('livewire.events-calendar', [
            'upcoming' => $upcoming->values(),
            'past' => $past->sortByDesc('starts_at')->values(),
            'mine' => $this->myRsvps(),
        ]);
    }

    /**
     * Published events, plus drafts for the moderators who still have to publish
     * them. Same gate the JSON index uses.
     *
     * @return Collection<int, Event>
     */
    private function visibleEvents(): Collection
    {
        return Event::query()
            ->withCount([
                // Counted in the query rather than per card: goingCount() on a
                // rendered list is one SELECT per event.
                'rsvps as going_count' => fn (Builder $query): Builder => $query->where('status', RsvpStatus::Going->value),
            ])
            ->unless(
                Gate::forUser(auth()->user())->allows('viewDrafts', Event::class),
                fn (Builder $query): Builder => $query->where('status', '!=', EventStatus::Draft->value),
            )
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * The current member's own answers, keyed by event id.
     *
     * @return Collection<int, Rsvp>
     */
    private function myRsvps(): Collection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return collect();
        }

        return Rsvp::query()
            ->where('user_id', $user->getKey())
            ->get()
            ->keyBy('event_id');
    }

    /** The event behind a key, or null if it is not one this member can see. */
    private function openEvent(string $eventKey): ?Event
    {
        $event = Event::query()->where('event_key', $eventKey)->first();

        if (! $event instanceof Event) {
            return null;
        }

        return Gate::forUser(auth()->user())->allows('view', $event) ? $event : null;
    }
}
