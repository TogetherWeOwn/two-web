<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Exceptions\EventAtCapacityException;
use App\Exceptions\EventNotOpenException;
use App\Exceptions\StaleAgentVersionException;
use App\Jobs\SyncEventToDiscord;
use App\Models\AgentEventGrant;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\EventInput;
use Illuminate\Support\Facades\DB;

/**
 * Everything that changes an event or an answer to one.
 *
 * It lives here rather than in the controllers because the capacity rule has to
 * hold for every caller — an HTTP route today, the reconcile command and whatever
 * TOG-52c needs tomorrow. A rule enforced in a controller is a rule enforced for
 * exactly one caller.
 *
 * Authorisation is *not* here. Policies answer "may this member do this"; this
 * class answers "is this possible" and "make it so". Mixing the two produces
 * methods that need a User to do arithmetic.
 */
class EventService
{
    public function create(User $host, EventInput $input): Event
    {
        return DB::transaction(function () use ($host, $input): Event {
            $event = new Event;
            $this->fill($event, $input);
            $event->created_by = $host->getKey();
            $event->status = EventStatus::Draft;
            $event->save();

            $this->syncAfterCommit($event);

            return $event;
        });
    }

    public function update(Event $event, EventInput $input): Event
    {
        return DB::transaction(function () use ($event, $input): Event {
            $this->fill($event, $input);
            $event->save();

            $this->syncAfterCommit($event);

            return $event;
        });
    }

    /**
     * Create the one Draft an agent grant may own (TOG-5510/web, Gate 2).
     *
     * The adapted attribution seam: `created_by` stays null — no human hosted
     * this — and ownership lives in `agent_grant_id` instead. The grant's
     * caller, scope and quota are checked by the agent ingress before this is
     * reached; what is enforced here is the shape of the row itself. The
     * database unique index on `agent_grant_id` is what makes the one-event
     * quota hold under concurrency rather than under good intentions.
     */
    public function createForGrant(AgentEventGrant $grant, EventInput $input, string $proofMarker): Event
    {
        return DB::transaction(function () use ($grant, $input, $proofMarker): Event {
            $event = new Event;
            $this->fill($event, $input);
            $event->created_by = null;
            $event->status = EventStatus::Draft;
            $event->agent_grant_id = $grant->getKey();
            $event->proof_marker = $proofMarker;
            $event->agent_version = 1;
            $event->save();

            // A draft is never mirrored, so this is a no-op by construction —
            // called for the same reason as in create(): the day this stops
            // being a draft-first flow it must not silently stop syncing.
            $this->syncAfterCommit($event);

            return $event;
        });
    }

    /**
     * Update an agent-owned event under optimistic concurrency (Gate 2).
     *
     * The row lock serialises concurrent writers; the version check turns the
     * loser into a 409 rather than a silent overwrite. Human writes never
     * touch `agent_version`, so a moderator correcting a typo cannot
     * invalidate an agent's expected version and vice versa.
     *
     * @throws StaleAgentVersionException when the row moved since the caller read it
     */
    public function updateForGrant(Event $event, EventInput $input, int $expectedVersion): Event
    {
        return DB::transaction(function () use ($event, $input, $expectedVersion): Event {
            $locked = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->agent_version !== $expectedVersion) {
                throw new StaleAgentVersionException($locked, $expectedVersion);
            }

            $this->fill($locked, $input);
            $locked->agent_version = $expectedVersion + 1;
            $locked->save();

            $this->syncAfterCommit($locked);

            $event->setRawAttributes($locked->getAttributes(), true);

            return $event;
        });
    }

    public function publish(Event $event): Event
    {
        return $this->transitionTo($event, EventStatus::Published);
    }

    public function cancel(Event $event): Event
    {
        return $this->transitionTo($event, EventStatus::Cancelled);
    }

    /**
     * Record a member's answer, and hand out the last free slot to exactly one of
     * however many people are asking for it at this instant.
     *
     * The `unique(event_id, user_id)` index stops one member answering twice and
     * does nothing about capacity: two *different* members both reading
     * `count < capacity` and both inserting is a legal pair of rows, and it is the
     * shape this fails as under load. So the read and the write are serialised
     * behind a row lock on the event: the second transaction blocks at
     * `lockForUpdate()` until the first commits, and only then counts — by which
     * time the winner's row is visible to it and the count is right.
     *
     * The alternative, an atomic conditional insert, needs a denormalised counter
     * column kept in step with the rows. This costs one lock on a row that is
     * contended for a few milliseconds a day and keeps the rsvps table the only
     * source of truth.
     */
    public function rsvp(Event $event, User $user, RsvpStatus $status): Rsvp
    {
        return DB::transaction(function () use ($event, $user, $status): Rsvp {
            $locked = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            // The clock counts, not just the status: reconcile flips finished rows
            // to Past every ~10 min, so a recently finished event is still
            // Published. The page already hides its RSVP button (TOG-7273); the
            // write path refuses with 409 event_not_open instead. Checked on the
            // locked row so a concurrent reconcile cannot reopen the window.
            if ($locked->status !== EventStatus::Published || $locked->hasEnded()) {
                throw EventNotOpenException::forRsvp($locked);
            }

            $existing = Rsvp::query()
                ->where('event_id', $locked->getKey())
                ->where('user_id', $user->getKey())
                ->first();

            // Only an answer that newly takes a seat has to fit. Someone already
            // going who says so again, or who downgrades to maybe, cannot make the
            // event more full than it is — and a waitlisted answer takes no seat
            // at all, which is why joining the line on a full event gets past
            // this check by design rather than by accident.
            $takesASeat = $status === RsvpStatus::Going
                && $existing?->status !== RsvpStatus::Going;

            if ($takesASeat && $locked->capacity !== null && $locked->goingCount() >= $locked->capacity) {
                throw new EventAtCapacityException($locked);
            }

            $rsvp = Rsvp::query()->updateOrCreate(
                ['event_id' => $locked->getKey(), 'user_id' => $user->getKey()],
                // Any change makes the Discord mirror stale, so the member is back to
                // "saved here, syncing to Discord" until the job says otherwise.
                ['status' => $status, 'synced_to_discord_at' => null],
            );

            $this->syncAfterCommit($locked);

            return $rsvp;
        });
    }

    public function withdrawRsvp(Event $event, User $user): void
    {
        DB::transaction(function () use ($event, $user): void {
            $locked = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            $deleted = Rsvp::query()
                ->where('event_id', $locked->getKey())
                ->where('user_id', $user->getKey())
                ->delete();

            if ($deleted > 0) {
                $this->syncAfterCommit($locked);
            }
        });
    }

    private function transitionTo(Event $event, EventStatus $to): Event
    {
        return DB::transaction(function () use ($event, $to): Event {
            $locked = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            // Cancelled is terminal. Discord has already told everyone it is off;
            // un-cancelling would mean a second announcement nobody asked for, and
            // the members who dropped their RSVP are not coming back for it.
            if ($locked->status === EventStatus::Cancelled) {
                throw EventNotOpenException::forTransition($locked, $to);
            }

            if ($locked->status !== $to) {
                $locked->status = $to;
                $locked->save();

                $this->syncAfterCommit($locked);
            }

            $event->setRawAttributes($locked->getAttributes(), true);

            return $event;
        });
    }

    private function fill(Event $event, EventInput $input): void
    {
        $event->title = $input->title;
        $event->game = $input->game;
        $event->description = $input->description;
        $event->starts_at = $input->startsAt;
        $event->ends_at = $input->endsAt;
        $event->timezone = $input->timezone;
        $event->location = $input->location;
        $event->capacity = $input->capacity;
    }

    /**
     * Hand the write-back to the queue and get out of the way.
     *
     * `afterCommit` is the whole point: the HTTP response must never wait on the
     * bot, and a job that starts before the transaction commits can read a row that
     * is not there yet — or, worse, one that is about to be rolled back. A draft is
     * skipped because Discord has never been shown it, so there is nothing to
     * update and the job would be a no-op that still cost a queue slot.
     */
    private function syncAfterCommit(Event $event): void
    {
        if (! $event->isMirroredInDiscord()) {
            return;
        }

        SyncEventToDiscord::dispatch($event->event_key)->afterCommit();
    }
}
