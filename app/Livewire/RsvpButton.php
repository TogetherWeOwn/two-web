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
use App\Support\SafeRedirect;
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
 *
 *  - **An expired session is not a failure.** The page was rendered signed in
 *    and the session died underneath it. Saying "try once more" would be a lie
 *    — no retry can succeed without logging in first — so the click names the
 *    expired session and points at the way back in instead.
 *
 *  - **A terminal Discord refusal is a mirror failure, never an RSVP failure.**
 *    The bot answered no (`discord_sync_failed_at` is stamped), so "Syncing…"
 *    would be a lie — nothing is on its way — but so would the error banner:
 *    the answer is committed and counts. The member is told exactly that.
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

    /**
     * Set when the shared RSVP write budget ran out. The member waits, then
     * retries — the button stays enabled and the wait is announced politely.
     */
    public bool $rateLimited = false;

    /**
     * Seconds from the limiter's Retry-After, for the announced wait. Null when
     * the header was missing or unparseable, which selects the fallback copy.
     */
    public ?int $retryAfterSeconds = null;

    /**
     * Set when the click arrived with no signed-in member behind it — the page
     * was rendered authenticated and the session died underneath it
     * (SESSION_LIFETIME). Distinct from $failed on purpose: the next action is
     * to log in again, not to try once more, so the message must say that.
     */
    public bool $sessionExpired = false;

    /**
     * Where the guest login links send the member back to after Discord.
     *
     * Captured once in mount, when the real page request is in hand. A
     * Livewire re-render answers a `/livewire/update` request, so reading the
     * path in the blade would point `?next=` at the update endpoint after the
     * first morph — a persisted prop keeps the page path across updates, and
     * keeps the expired-session re-render pointing at the page too. Null when
     * the path fails the open-redirect guard, and the links stay bare.
     */
    public ?string $returnTo = null;

    public function mount(Event $event): void
    {
        $this->event = $event;
        $this->returnTo = SafeRedirect::safe(request()->getPathInfo());
    }

    public function rsvp(string $status, EventService $events): void
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            // The member was signed in when this rendered and is not now. A
            // silent return is the bug (TOG-8135): name it and point at login.
            $this->sessionExpired = true;

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
        $this->rateLimited = false;
        $this->retryAfterSeconds = null;
        $this->sessionExpired = false;

        try {
            RsvpRateLimit::hit($user);
            $events->rsvp($this->event, $user, $answer);
            // TOG-6990: a re-arming write clears the terminal stamp on the
            // service's own row instance. Re-read so this render sees the
            // cleared stamp — otherwise the banner shows "failed" for an
            // attempt that is already back to "syncing".
            $this->event = $this->event->fresh() ?? $this->event;
            // TOG-6956: a successful write swaps the focused button for the
            // confirmation, which drops keyboard focus to <body>. The
            // self-dispatch fires after Livewire has morphed the new state in,
            // and the view's listener moves focus to the confirmation. Only on
            // success: on failure the button stays put, so focus is already
            // where it belongs.
            $this->dispatch('rsvp-state-changed')->self();
            // TOG-7966: the going-count badge lives outside this component, so
            // it never re-renders with it. Broadcast globally (not self) so the
            // sibling GoingCount for this event re-reads the aggregate. The
            // viewer state tells the badge what to announce politely.
            $this->dispatch(
                'going-count-updated',
                eventKey: $this->event->event_key,
                viewerState: match ($answer) {
                    RsvpStatus::Going => 'going',
                    RsvpStatus::Waitlisted => 'waitlisted',
                    default => 'other',
                },
            );
        } catch (EventAtCapacityException) {
            $this->full = true;
        } catch (EventNotOpenException) {
            // Cancelled, already over, or paused while they were looking at
            // it. Re-rendering against the fresh row is the honest answer;
            // the reason shows there.
            $this->event = $this->event->fresh() ?? $this->event;
        } catch (ThrottleRequestsException $exception) {
            // TOG-7976: a thrown 429 never re-renders — Livewire's JS only morphs
            // the DOM on response.ok and shows its failure modal otherwise, so the
            // member hears nothing. Catch the throttle and render the announced
            // wait in the normal 200 morph instead, with the button enabled.
            $this->flagRateLimited($exception);
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
            $this->sessionExpired = true;

            return;
        }

        $this->failed = false;
        $this->rateLimited = false;
        $this->retryAfterSeconds = null;
        $this->sessionExpired = false;

        try {
            RsvpRateLimit::hit($user);
            $events->withdrawRsvp($this->event, $user);
            // TOG-6956: same swap in reverse — the withdraw control is replaced
            // by the "I'm in" button. Refocus after the successful round trip.
            $this->dispatch('rsvp-state-changed')->self();
            // TOG-7966: the badge outside this component must follow the write
            // down as well as up — withdraw re-reads the aggregate too.
            $this->dispatch(
                'going-count-updated',
                eventKey: $this->event->event_key,
                viewerState: 'none',
            );
        } catch (ThrottleRequestsException $exception) {
            // TOG-7976: same announced wait as the join path — copy is neutral
            // ("Nothing changed") so one node covers both.
            $this->flagRateLimited($exception);
        } catch (Throwable) {
            $this->failed = true;
        }
    }

    /**
     * The announced throttle copy (TOG-7976, CM-frozen in TOG-7928 `copy` doc —
     * do not reword without CM sign-off). Null unless the last attempt hit the
     * shared write budget. {N} is the ceiling of Retry-After, min 1, so the
     * member is never told to wait 0 seconds; an unusable header selects the
     * "in a moment" fallback instead of a number.
     */
    public function rateLimitedMessage(): ?string
    {
        if (! $this->rateLimited) {
            return null;
        }

        if ($this->retryAfterSeconds === null) {
            return 'Slow down — try again in a moment. Nothing changed, just wait a bit.';
        }

        $seconds = $this->retryAfterSeconds === 1 ? '1 second' : "{$this->retryAfterSeconds} seconds";

        return "Slow down — try again in {$seconds}. Nothing changed, just wait a moment.";
    }

    /**
     * Record a throttled attempt as renderable state. The Retry-After header is
     * the limiter's own value (RsvpRateLimit::hit throws with it set); anything
     * missing, non-numeric or non-positive falls back to the headerless copy.
     */
    private function flagRateLimited(ThrottleRequestsException $exception): void
    {
        $raw = $exception->getHeaders()['Retry-After'] ?? null;

        $seconds = is_numeric($raw) ? (int) ceil((float) $raw) : null;

        $this->rateLimited = true;
        $this->retryAfterSeconds = $seconds !== null && $seconds >= 1 ? $seconds : null;
    }

    public function render(): View
    {
        $rsvp = $this->currentRsvp();
        $going = $rsvp?->status === RsvpStatus::Going;
        $waitlisted = $rsvp?->status === RsvpStatus::Waitlisted;

        // The clock counts, not just the status: a recently finished event is
        // still Published until the reconcile pass flips it to Past, and
        // offering a button for it would be a lie the write path refuses.
        $live = $this->event->status === EventStatus::Published && ! $this->event->hasEnded();

        // A moderator pause (TOG-8725): still Published, still visible, taking
        // no new answers. Kept separate from `open`: a paused event must keep
        // withdraw controls for members who already answered — folding pause
        // into `open` would trap them behind the closed banner with no way to
        // stand down. The blade shows the paused copy only to members with no
        // stake; holders keep their confirmation and withdraw, and the line
        // keeps its places and the way out of them.
        $paused = $live && ! $this->event->isRsvpOpen();

        return view('livewire.rsvp-button', [
            'rsvp' => $rsvp,
            'going' => $going,
            'waitlisted' => $waitlisted,
            'open' => $live,
            'paused' => $paused,
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
            // else, first-come first-served against the line. Never while
            // paused (TOG-8725): claiming is a new answer, and the write path
            // refuses it — offering the button would be the lie `open` avoids.
            'seatOpenForWaitlist' => $waitlisted && $this->event->status === EventStatus::Published && ! $this->event->hasEnded() && $this->event->isRsvpOpen() && ! $this->isAtCapacity(),
            // One-based place in line, only when it will be shown.
            'waitlistPosition' => $waitlisted ? $this->waitlistPosition() : null,
            // Committed here, not yet in Discord. A true state, not an error.
            // A terminally-refused row is never "syncing": the bot answered no
            // (TOG-6990), so that copy would be a lie. It reads as failed below.
            'syncing' => $rsvp !== null && $rsvp->synced_to_discord_at === null
                && $this->event->discord_sync_failed_at === null,
            // The third state (TOG-6990): saved here, refused over there. The
            // answer counts — this is never the error banner — but no retry is
            // coming until somebody changes something, so it must not read as
            // pending either.
            'syncFailed' => $rsvp !== null && $rsvp->synced_to_discord_at === null
                && $this->event->discord_sync_failed_at !== null,
            // TOG-7976: the announced throttle wait, or null when the last
            // attempt was not throttled. The blade node stays beside the
            // control with the button enabled, like rsvp-failed.
            'rateLimitedMessage' => $this->rateLimitedMessage(),
            // TOG-9254: the guest links' return-to page, or null for bare
            // links. Read from the persisted prop, never from the request —
            // see $returnTo.
            'returnTo' => $this->returnTo,
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
