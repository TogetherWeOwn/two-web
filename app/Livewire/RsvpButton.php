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
use App\Support\RsvpRateLimit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * One member's answer to one event.
 *
 * The honesty rules, all three of which have a plausible wrong version:
 *
 *  - **A pending Discord sync is not a failure.** `synced_to_discord_at` being
 *    null means the row is committed here and the bot has not caught up yet. The
 *    member is told exactly that. Rendering it as an error would tell somebody
 *    their RSVP failed while it is sitting in the database, and they would stop
 *    coming.
 *
 *  - **Full is not a retryable failure.** The capacity refusal is typed
 *    (EventAtCapacityException) precisely so this can say "this filled up"
 *    instead of offering a retry that cannot succeed.
 *
 *  - **A real failure keeps the button.** COMPONENTS.md §1.1: the error goes
 *    beside the control, the control returns to default and stays enabled. A
 *    disabled button after an error is a dead end.
 */
class RsvpButton extends Component
{
    /**
     * Locked: this is the identity of the thing being answered. Without this a
     * crafted browser payload could point the component at a different event
     * between render and call.
     */
    #[Locked]
    public Event $event;

    /** Set when the write genuinely did not happen and retrying is worth it. */
    public bool $failed = false;

    /** Set when the last seat went to somebody else. Retrying will not help. */
    public bool $full = false;

    public function mount(Event $event): void
    {
        $this->event = $event;
    }

    public function rsvp(string $status, EventService $events): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $answer = RsvpStatus::tryFrom($status);

        if (! $answer instanceof RsvpStatus) {
            return;
        }

        // Cleared before the attempt, not after: a stale error banner sitting above
        // a successful RSVP is its own bug.
        $this->failed = false;
        $this->full = false;

        try {
            RsvpRateLimit::hit($user);
            $events->rsvp($this->event, $user, $answer);
            // TOG-6956: a successful write swaps the focused button for the
            // confirmation, which drops keyboard focus to <body>. The
            // self-dispatch fires after Livewire has morphed the new state in,
            // and the view's listener moves focus to the confirmation. Only on
            // success: on failure the button stays put, so focus is already
            // where it belongs.
            $this->dispatch('rsvp-state-changed')->self();
        } catch (EventAtCapacityException) {
            $this->full = true;
        } catch (EventNotOpenException) {
            // Cancelled or already over while they were looking at it. Re-rendering
            // against the fresh row is the honest answer; the reason shows there.
            $this->event = $this->event->fresh() ?? $this->event;
        } catch (ThrottleRequestsException $exception) {
            // Unlike an internal write failure, this is an intentional HTTP refusal.
            // Let Livewire return the 429 and its Retry-After rather than rendering a
            // generic "try once more" message that invites an immediately doomed retry.
            throw $exception;
        } catch (Throwable) {
            // Deliberately not surfaced. Whatever the reason is — the queue, the
            // database, the bot's client — it is ours, and the member's next action
            // is the same in every case: try once more.
            $this->failed = true;
        }
    }

    public function withdraw(EventService $events): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $this->failed = false;

        try {
            RsvpRateLimit::hit($user);
            $events->withdrawRsvp($this->event, $user);
            // TOG-6956: same swap in reverse — the withdraw control is replaced
            // by the "I'm in" button. Refocus after the successful round trip.
            $this->dispatch('rsvp-state-changed')->self();
        } catch (ThrottleRequestsException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->failed = true;
        }
    }

    public function render(): View
    {
        $rsvp = $this->currentRsvp();
        $going = $rsvp?->status === RsvpStatus::Going;
        $waitlisted = $rsvp?->status === RsvpStatus::Waitlisted;

        return view('livewire.rsvp-button', [
            'rsvp' => $rsvp,
            'going' => $going,
            'waitlisted' => $waitlisted,
            // The clock counts, not just the status: a recently finished event is
            // still Published until the reconcile pass flips it to Past, and
            // offering a button for it would be a lie the write path refuses.
            'open' => $this->event->status === EventStatus::Published && ! $this->event->hasEnded(),
            // Somebody already holding a seat — or a place in line — is never
            // shown a full event: they are the reason it is full (or waiting
            // for one), and they must still be able to stand down or
            // leave the line. Trapping them at the refusal is the bug.
            'atCapacity' => ! $going && ! $waitlisted && $this->isAtCapacity(),
            // True only in the gap the auto-promote cannot cover: the row this
            // render read says a seat is free while the member is still in
            // line — a state that can only exist mid-flight (their promotion
            // has not rendered yet) or when promotion was never reached. The
            // write takes the seat through the same locked path as everybody
            // else, first-come first-served against the line.
            'seatOpenForWaitlist' => $waitlisted && $this->event->status === EventStatus::Published && ! $this->event->hasEnded() && ! $this->isAtCapacity(),
            // One-based place in line, only when it will be shown.
            'waitlistPosition' => $waitlisted ? $this->waitlistPosition() : null,
            // Committed here, not yet in Discord. A true state, not an error.
            'syncing' => $rsvp !== null && $rsvp->synced_to_discord_at === null,
        ]);
    }

    private function waitlistPosition(): ?int
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return $this->event->waitlistPositionFor($user);
    }

    private function currentRsvp(): ?Rsvp
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        // On the events page the parent eager-loads this for every card at once, so
        // a page of twelve cards is one query rather than twelve — including the
        // cards nobody has answered, which is why an empty relation is an answer
        // ("no RSVP") and not a reason to go asking again.
        //
        // Nothing needs to invalidate this after a write. Livewire rehydrates the
        // model from the database on every round trip, so `rsvp()` and `withdraw()`
        // run against a fresh instance with no relations loaded, and the branch below
        // is only ever taken on the first render. Measured, not assumed: an explicit
        // unsetRelation() here could not be mutation-tested, which is what gave it
        // away as dead code.
        //
        // The rows are still filtered by user_id rather than trusted: `viewerRsvps`
        // constrains on whoever was authenticated when the query was *built*, and
        // showing one member another member's answer is the worst thing this
        // component could do.
        if ($this->event->relationLoaded('viewerRsvps')) {
            return $this->event->getRelation('viewerRsvps')
                ->firstWhere('user_id', $user->getKey());
        }

        return Rsvp::query()
            ->where('event_id', $this->event->getKey())
            ->where('user_id', $user->getKey())
            ->first();
    }

    private function isAtCapacity(): bool
    {
        if ($this->event->capacity === null) {
            return false;
        }

        // `going_count` is the page's own aggregate, already selected for the card
        // beside this control. Falling back to a count keeps the component usable on
        // its own, which is how it is tested and how a single-event page would use it.
        $going = $this->event->going_count ?? $this->event->goingCount();

        return $going >= $this->event->capacity;
    }
}
