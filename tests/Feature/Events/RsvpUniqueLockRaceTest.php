<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Jobs\SyncEventToDiscord;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\EventService;
use Illuminate\Support\Facades\Queue;

/*
 * TOG-6959: the second of two RSVP writes to the same event rolled back.
 *
 * `SyncEventToDiscord` is `ShouldBeUnique` on the event key, and the
 * `PendingDispatch` destructor acquires that lock eagerly — still inside the
 * transaction the RSVP write opened. While a write-back for the event is
 * already queued (the 10s debounce, or a worker running behind), the
 * `insert into cache_locks` hits the unique key, and on Postgres that one
 * failed statement aborts the whole enclosing transaction: the fallback
 * `update` dies with 25P02, the exception escapes, and the member's answer is
 * lost behind "That RSVP didn't save. Try once more."
 *
 * The suite runs with an array cache, which would hide this — the duplicate
 * insert only conflicts in a real table — so the test configures the
 * production `database` driver first (same trick as DiscordFunnelTest's
 * session-driver pin). The first dispatch then holds the unique lock the way
 * a still-queued write-back does; the RSVP under test is the "second write",
 * and it must commit regardless.
 */

beforeEach(function () {
    config()->set('cache.default', 'database');

    $this->event = Event::factory()->create(['status' => EventStatus::Published]);
    $this->member = User::factory()->create(['is_moderator' => false]);
});

it('commits the RSVP even when the event write-back is already queued', function () {
    Queue::fake();

    // The first write-back: still on the queue (10s debounce), still holding
    // the unique lock for this event key.
    SyncEventToDiscord::dispatch($this->event->event_key);

    app(EventService::class)->rsvp($this->event, $this->member, RsvpStatus::Going);

    // The member's answer is what must survive. Before the fix this row was
    // rolled back by the duplicate-lock 25P02 escaping the write transaction.
    expect(Rsvp::query()->where('event_id', $this->event->id)->where('user_id', $this->member->id)->count())
        ->toBe(1, 'the second write lost its RSVP to the unique-lock conflict');

    // …and the duplicate dispatch was absorbed, not doubled: the queued job
    // re-reads the row when it runs, so one write-back carries both changes.
    Queue::assertPushed(SyncEventToDiscord::class, 1);
});

it('commits a withdraw even when the event write-back is already queued', function () {
    Queue::fake();

    Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => RsvpStatus::Going,
    ]);

    SyncEventToDiscord::dispatch($this->event->event_key);

    app(EventService::class)->withdrawRsvp($this->event, $this->member);

    expect(Rsvp::query()->where('event_id', $this->event->id)->where('user_id', $this->member->id)->count())
        ->toBe(0, 'the withdraw was rolled back by the unique-lock conflict');

    Queue::assertPushed(SyncEventToDiscord::class, 1);
});
