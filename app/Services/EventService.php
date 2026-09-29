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
use App\Support\RecurrenceInput;
use App\Support\RecurrenceSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    /**
     * Create a recurring series: the parent row holding the rule, plus one row
     * per occurrence the rule names — all drafts, like every other create.
     *
     * One transaction, so a failure leaves no half-series behind. The parent
     * is index 1; children copy its field values, except status: a child
     * starts as Draft even when created under an already-published parent, so
     * extending a live series never announces meetings a moderator has not
     * seen. Publishing the parent announces whatever exists then (see
     * transitionTo); later children are published from their own rows.
     */
    public function createSeries(User $host, EventInput $input, RecurrenceInput $recurrence): Event
    {
        return DB::transaction(function () use ($host, $input, $recurrence): Event {
            $parent = new Event;
            $this->fill($parent, $input);
            $parent->created_by = $host->getKey();
            $parent->status = EventStatus::Draft;
            $parent->recurrence_frequency = $recurrence->frequency;
            $parent->recurrence_count = $recurrence->count;
            $parent->recurrence_ends_on = $recurrence->endsOn;
            $parent->recurrence_index = 1;
            $parent->save();

            $this->materializeMissingInstances($parent);

            return $parent->fresh() ?? $parent;
        });
    }

    public function update(Event $event, EventInput $input): Event
    {
        return DB::transaction(function () use ($event, $input): Event {
            // Locked like every other seat-changing write: a capacity increase
            // frees seats, and the freed seats are dealt to the waitlist below.
            // A concurrent rsvp(Going) slipping between the save and the deal
            // would take the new seat ahead of the line — the same race the
            // capacity check in rsvp() locks against.
            $locked = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            $oldStartsAt = $locked->starts_at;
            $oldEndsAt = $locked->ends_at;

            $this->fill($locked, $input);
            $locked->save();

            // A parent whose times moved reprograms the future: children not
            // yet started shift by the same delta, so the series stays weekly
            // around the edit. Started or finished instances keep their times —
            // members already planned around them.
            if ($locked->isSeriesParent()) {
                $this->shiftFutureChildren($locked, $oldStartsAt, $oldEndsAt);
            }

            $this->promoteWaitlist($locked);

            $this->syncAfterCommit($locked);

            $event->setRawAttributes($locked->getAttributes(), true);

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

            // A raised cap frees seats whoever raised it. Same lock, same
            // transaction, same first-come-first-served deal as the human path.
            $this->promoteWaitlist($locked);

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
     * Pause or reopen answers (TOG-8725). A flag flip, not a status
     * transition: pausing keeps a published event visible while stopping new
     * answers, and unlike cancelling it is reversible. Row-locked like every
     * other event write.
     *
     * Reopening settles the backlog in the same locked write: seats freed
     * while paused were never dealt (promotion freezes on a pause, below), and
     * without this a newcomer answering after the reopen would take a freed
     * seat ahead of the line that waited for it. Pausing dispatches no
     * write-back — the mirror carries no RSVP-open state, so a pause changes
     * nothing on the bot's side — but a reopen may have promoted rows, which
     * is mirrored state, so it syncs.
     */
    public function setRsvpOpen(Event $event, bool $open): Event
    {
        return DB::transaction(function () use ($event, $open): Event {
            $locked = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isRsvpOpen() !== $open) {
                $locked->rsvp_open = $open;
                $locked->save();

                if ($open) {
                    $this->promoteWaitlist($locked);
                    $this->syncAfterCommit($locked);
                }
            }

            $event->setRawAttributes($locked->getAttributes(), true);

            return $event;
        });
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

            // A moderator pause (TOG-8725): the event stays published and
            // visible, but takes no new answers while closed — unpublishing to
            // the same end would hide the event itself. Read on the locked row
            // like the check above, so a concurrent reopen cannot slip through.
            // Withdrawals are deliberately not gated: leaving is always allowed.
            if (! $locked->isRsvpOpen()) {
                throw EventNotOpenException::forRsvpClosed($locked);
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

    /**
     * Record that a member no longer holds (or lines up for) a seat, and deal
     * the freed seat to the line.
     *
     * The delete and the promotion share the event row lock and the
     * transaction: a concurrent rsvp(Going) that reads "full" must block until
     * this commits, by which time the waitlist head already holds the seat and
     * the newcomer's count is right. A promotion outside this frame would let
     * that newcomer take the seat ahead of the member who has been waiting.
     */
    public function withdrawRsvp(Event $event, User $user): void
    {
        DB::transaction(function () use ($event, $user): void {
            $locked = Event::query()->whereKey($event->getKey())->lockForUpdate()->firstOrFail();

            $deleted = Rsvp::query()
                ->where('event_id', $locked->getKey())
                ->where('user_id', $user->getKey())
                ->delete();

            if ($deleted > 0) {
                $this->promoteWaitlist($locked);
                $this->syncAfterCommit($locked);
            }
        });
    }

    /**
     * Deal freed seats to the head of the waitlist, earliest answer first.
     *
     * Runs inside the caller's transaction behind the caller's event row lock —
     * it takes no lock of its own. Each promotion flips one waitlisted row to
     * Going and resets its Discord mirror stamp, exactly as if the member had
     * claimed the seat themselves: the seat is now spoken for on both sides,
     * so the mirror is stale. Only open events promote: an ended or cancelled
     * event has no seats to deal, and leaving the line untouched there keeps
     * the withdraw a plain delete. The line order is the same (created_at, id)
     * pair `waitlistPositionFor()` numbers places by, so the member shown #1
     * is the one who gets the seat.
     */
    private function promoteWaitlist(Event $locked): void
    {
        if ($locked->status !== EventStatus::Published || $locked->hasEnded()) {
            return;
        }

        // A moderator pause freezes the line (TOG-8725): seats freed while
        // paused stay free until the reopen settles them. Dealing them now
        // would move seats while the event claims to take no answers, and
        // the reopen path above promotes in the same locked write — so the
        // head of the line never loses a freed seat to a newcomer.
        if (! $locked->isRsvpOpen()) {
            return;
        }

        if ($locked->capacity === null) {
            $free = PHP_INT_MAX;
        } else {
            $free = $locked->capacity - $locked->goingCount();

            if ($free <= 0) {
                return;
            }
        }

        $heads = Rsvp::query()
            ->where('event_id', $locked->getKey())
            ->where('status', RsvpStatus::Waitlisted)
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($free)
            ->get();

        foreach ($heads as $rsvp) {
            $rsvp->status = RsvpStatus::Going;
            $rsvp->synced_to_discord_at = null;
            $rsvp->save();
        }
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

                // A series moves together: publishing the parent announces every
                // instance that exists, cancelling it calls them all off. Each
                // child is transitioned through the same path, so the terminal
                // rule holds for every row and every row gets its write-back —
                // a child that already reached the target state (a week skipped
                // by cancelling early) is skipped, never forced.
                if ($locked->isSeriesParent()) {
                    foreach ($locked->childEvents()->lockForUpdate()->get() as $child) {
                        // Only the states this action applies to: a past instance
                        // keeps its history ("it ran" is not "called off"), and a
                        // week skipped by cancelling early stays as it is.
                        if (! in_array($child->status, [EventStatus::Draft, EventStatus::Published], true)) {
                            continue;
                        }

                        $this->transitionRow($child, $to);
                    }
                }

                $this->syncAfterCommit($locked);
            }

            $event->setRawAttributes($locked->getAttributes(), true);

            return $event;
        });
    }

    /**
     * Transition one row without touching its children. A series child is a
     * leaf: cancelling one instance to skip a week must not cascade anywhere.
     */
    private function transitionRow(Event $row, EventStatus $to): void
    {
        if ($row->status === EventStatus::Cancelled) {
            throw EventNotOpenException::forTransition($row, $to);
        }

        if ($row->status !== $to) {
            $row->status = $to;
            $row->save();

            $this->syncAfterCommit($row);
        }
    }

    /**
     * Shift every not-yet-started child of a series parent by the same
     * absolute delta the parent's times moved.
     *
     * The delta is seconds, not wall arithmetic: the parent moved from one
     * instant to another and the children follow by the same amount, which is
     * DST-proof in both directions. A child whose start moved between the read
     * and the save is guarded by the row lock, which serialises this against
     * every other event write.
     */
    private function shiftFutureChildren(Event $parent, CarbonImmutable $oldStartsAt, CarbonImmutable $oldEndsAt): void
    {
        $startDelta = $parent->starts_at->getTimestamp() - $oldStartsAt->getTimestamp();
        $endDelta = $parent->ends_at->getTimestamp() - $oldEndsAt->getTimestamp();

        if ($startDelta === 0 && $endDelta === 0) {
            return;
        }

        $children = $parent->childEvents()->lockForUpdate()
            ->where('starts_at', '>', now())
            ->get();

        foreach ($children as $child) {
            $child->starts_at = $child->starts_at->addSeconds($startDelta);
            $child->ends_at = $child->ends_at->addSeconds($endDelta);
            $child->save();

            $this->syncAfterCommit($child);
        }
    }

    /**
     * Create every occurrence the parent's rule names that does not exist yet.
     * Index 1 is the parent itself, so it starts at 2.
     *
     * Idempotent by the index pairs already in the table: re-running creates
     * only what is missing and never touches an existing row — including one
     * a moderator cancelled to skip a week. That is the whole contract with
     * `events:reconcile`, which calls this on every pass.
     *
     * @return int how many rows were created
     */
    public function materializeMissingInstances(Event $parent): int
    {
        // Read once and null-check the value, not isSeriesParent(): the check
        // below narrows the type for the call that follows, and a helper that
        // answers a question cannot do that narrowing for us.
        $frequency = $parent->recurrence_frequency;

        if ($frequency === null) {
            return 0;
        }

        $occurrences = RecurrenceSchedule::occurrences(
            $parent->starts_at,
            $parent->ends_at,
            $parent->timezone,
            $frequency,
            $parent->recurrence_count,
            $parent->recurrence_ends_on,
        );

        $existing = Event::query()
            ->where('parent_event_id', $parent->getKey())
            ->pluck('recurrence_index')
            ->all();

        $created = 0;

        foreach ($occurrences as $index => [$startsAt, $endsAt]) {
            if ($index === 1 || in_array($index, $existing, true)) {
                continue;
            }

            $child = new Event;
            $child->title = $parent->title;
            $child->game = $parent->game;
            $child->description = $parent->description;
            $child->starts_at = $startsAt;
            $child->ends_at = $endsAt;
            $child->timezone = $parent->timezone;
            $child->location = $parent->location;
            $child->capacity = $parent->capacity;
            $child->created_by = $parent->created_by;
            $child->status = EventStatus::Draft;
            $child->parent_event_id = $parent->getKey();
            $child->recurrence_index = $index;
            $child->save();
            $created++;
        }

        return $created;
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
     *
     * The dispatch runs inside a savepoint, not in the write's own transaction
     * frame. `dispatch()` returns a `PendingDispatch` whose destructor eagerly
     * acquires the job's `ShouldBeUnique` lock — still inside whatever transaction
     * is open here. When a write-back for this event is already queued (the 10s
     * debounce, or a worker running behind), that `insert into cache_locks` hits
     * the unique key, and on Postgres the one failed statement aborts the whole
     * enclosing transaction: the fallback `update` then dies with 25P02, the
     * exception escapes, and the member's RSVP rolls back with it (TOG-6959). The
     * savepoint confines the lock check to its own subtransaction — a duplicate
     * lock rolls back to the savepoint and the outer write commits untouched.
     *
     * The only queries inside the savepoint are the unique-lock's own
     * insert/update (payload creation and the queue push touch no tables; the push
     * itself is deferred by `afterCommit`). So any `QueryException` escaping it
     * means "no fresh lock for this event", and skipping is correct either way:
     * the already-queued job re-reads the row when it runs and carries this
     * change with it. The log line keeps the skip observable; `events:reconcile`
     * is the backstop if the lock store itself is ever down.
     */
    private function syncAfterCommit(Event $event): void
    {
        if (! $event->isMirroredInDiscord()) {
            return;
        }

        // A genuinely new member or moderator change re-arms a terminally-refused
        // row (TOG-6990): the stamp answered an older operation, and the dispatch
        // below carries a new one the bot has not ruled on. Cleared in the outer
        // write, never inside the unique-lock savepoint below — a failed clear
        // there would read as "no fresh lock" and skip silently. If the write
        // rolls back, the stamp stays with it, which is correct: the change
        // never happened. A lock-skip keeps the clear: the already-queued job
        // re-reads the row and either lands (clearing the stamp itself) or is
        // refused again (re-stamping it), so the verdict is always re-confirmed.
        if ($event->discord_sync_failed_at !== null) {
            $event->forceFill([
                'discord_sync_failed_at' => null,
                'discord_sync_failure_code' => null,
            ])->save();
        }

        try {
            DB::transaction(function () use ($event): void {
                SyncEventToDiscord::dispatch($event->event_key)->afterCommit();
            }, 1);
        } catch (QueryException $e) {
            // Name the exception: the expected case is a duplicate unique lock
            // (the already-queued job carries this change), but a QueryException
            // from a lock store that is actually down must not read identically.
            Log::info('Event write-back already queued; skipping duplicate dispatch.', [
                'event_key' => $event->event_key,
                'exception' => $e::class,
                'sqlstate' => $e->getCode(),
            ]);
        }
    }
}
