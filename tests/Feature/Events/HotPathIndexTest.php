<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// TOG-9278: hot-path index audit on rsvps/events. TOG-8731 covered
// join_attempts only; these are the shapes every event page hits. Each test
// pins one index and proves the planner reaches for it on the exact query
// shape via EXPLAIN, so a later migration that drops one fails loudly.
function hotPathIndexPlan(string $sql, array $bindings): string
{
    // Small fixture tables invite a seq scan no matter what indexes exist,
    // so take that plan off the table: the assertion is that the index is a
    // usable access path for the query shape, not that it wins a cost
    // contest on ten rows.
    DB::statement('SET LOCAL enable_seqscan = off');

    return collect(DB::select("EXPLAIN {$sql}", $bindings))
        ->pluck('QUERY PLAN')
        ->implode("\n");
}

/**
 * Production-shaped volume for the events listing shapes.
 *
 * The calendar/past/neighbour listings only reveal their access path at
 * volume: on a ten-row fixture the planner seq-scans no matter what exists,
 * which is correct behaviour, not a schema defect. 1500 finished rows plus
 * a live tail mirrors production (a years-long archive with a handful of
 * upcoming events), is deterministic so plans are stable run to run, and is
 * rolled back with the test transaction. Raw SQL, not factories: one
 * statement, and the planner needs ANALYZE'd stats, not model hooks.
 */
function seedEventArchiveVolume(): void
{
    DB::statement(
        "INSERT INTO events (event_key, title, starts_at, ends_at, timezone, status, game, created_at, updated_at)
         SELECT substr(md5('past' || g::text), 1, 26), 'Past ' || g,
                now() - (g || ' days')::interval, now() - (g || ' days')::interval + interval '2 hours',
                'Europe/London',
                CASE g % 6 WHEN 0 THEN 'cancelled' WHEN 1 THEN 'past' ELSE 'published' END,
                'Helldivers 2', now(), now()
         FROM generate_series(1, 1500) g"
    );
    DB::statement(
        "INSERT INTO events (event_key, title, starts_at, ends_at, timezone, status, game, created_at, updated_at)
         SELECT substr(md5('next' || g::text), 1, 26), 'Next ' || g,
                now() + (g || ' days')::interval, now() + (g || ' days')::interval + interval '2 hours',
                'Europe/London', 'published',
                CASE g % 2 WHEN 0 THEN 'Helldivers 2' ELSE 'Valorant' END, now(), now()
         FROM generate_series(1, 30) g"
    );

    DB::statement('ANALYZE events');
}

it('has the rsvps(event_id, status) hot-path index', function () {
    $index = DB::selectOne(
        "SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'rsvps' AND indexname = 'rsvps_event_id_status_index'"
    );

    expect($index)->not->toBeNull()
        ->and($index->indexdef)->toContain('event_id')
        ->and($index->indexdef)->toContain('status');
});

it('has the partial unsynced-rsvp index', function () {
    $index = DB::selectOne(
        "SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'rsvps' AND indexname = 'rsvps_unsynced_event_id_index'"
    );

    expect($index)->not->toBeNull()
        ->and($index->indexdef)->toContain('event_id')
        ->and($index->indexdef)->toContain('synced_to_discord_at IS NULL');
});

it('has the events(ends_at) and events(starts_at, id) hot-path indexes', function () {
    $endsAt = DB::selectOne(
        "SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'events' AND indexname = 'events_ends_at_index'"
    );
    $startsAt = DB::selectOne(
        "SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'events' AND indexname = 'events_starts_at_id_index'"
    );

    expect($endsAt)->not->toBeNull()
        ->and($endsAt->indexdef)->toContain('ends_at')
        ->and($startsAt)->not->toBeNull()
        ->and($startsAt->indexdef)->toContain('starts_at');
});

it('uses the composite index for the going-count shape', function () {
    $event = Event::factory()->create();
    $users = User::factory()->count(3)->create();
    foreach ($users as $user) {
        Rsvp::factory()->for($event)->for($user)->create(['status' => RsvpStatus::Going]);
    }

    // Same shape as Event::goingCount(): rsvps WHERE event_id = ? AND status = ?.
    $plan = hotPathIndexPlan(
        'SELECT count(*) FROM rsvps WHERE event_id = ? AND status = ?',
        [$event->getKey(), RsvpStatus::Going->value]
    );

    expect($plan)->toContain('rsvps_event_id_status_index');
});

it('uses the composite index for the waitlist-head shape', function () {
    $event = Event::factory()->create();
    $users = User::factory()->count(3)->create();
    foreach ($users as $user) {
        Rsvp::factory()->for($event)->for($user)->create(['status' => RsvpStatus::Waitlisted]);
    }

    // Same shape as EventService::promoteWaitlist(): event + status with the
    // (created_at, id) line order and a small limit.
    $plan = hotPathIndexPlan(
        'SELECT * FROM rsvps WHERE event_id = ? AND status = ? ORDER BY created_at, id LIMIT 5',
        [$event->getKey(), RsvpStatus::Waitlisted->value]
    );

    expect($plan)->toContain('rsvps_event_id_status_index');
});

it('uses the composite index for the waitlist-position shape', function () {
    $event = Event::factory()->create();
    $mine = Rsvp::factory()->for($event)->for(User::factory()->create())->create(['status' => RsvpStatus::Waitlisted]);

    // Same shape as Event::waitlistPositionFor(): how many waitlisted answers
    // stand ahead of mine in (created_at, id) order.
    $plan = hotPathIndexPlan(
        'SELECT count(*) FROM rsvps WHERE event_id = ? AND status = ? AND (created_at < ? OR (created_at = ? AND id <= ?))',
        [$event->getKey(), RsvpStatus::Waitlisted->value, $mine->created_at, $mine->created_at, $mine->getKey()]
    );

    expect($plan)->toContain('rsvps_event_id_status_index');
});

it('uses the partial index for the unsynced-rsvp probe shape', function () {
    $event = Event::factory()->create();
    Rsvp::factory()->for($event)->for(User::factory()->create())->create(['synced_to_discord_at' => null]);

    // Same probe shape as the SyncEventToDiscord sweep and the reconcile
    // stale-EXISTS subquery: unsynced answers for one event.
    $plan = hotPathIndexPlan(
        'SELECT * FROM rsvps WHERE event_id = ? AND synced_to_discord_at IS NULL',
        [$event->getKey()]
    );

    expect($plan)->toContain('rsvps_unsynced_event_id_index');
});

it('needs no new index for the reconcile-close shape', function () {
    Event::factory()->create(['status' => EventStatus::Published, 'ends_at' => now()->subHour()]);

    // Verdict: none-needed. `status = published` is equality on the leading
    // column of the shipped (status, starts_at) index, which bounds the
    // candidate set; the ends_at filter then applies to tens of rows, not
    // thousands. A (status, ends_at) composite never won a plan at
    // production-shaped volume in any state (backlog or steady), so it would
    // be write overhead for no read gain. This pins the verdict: the close
    // shape must keep reaching the shipped index.
    $plan = hotPathIndexPlan(
        'SELECT * FROM events WHERE status = ? AND ends_at < now()',
        [EventStatus::Published->value]
    );

    expect($plan)->toContain('events_status_starts_at_index');
});

it('uses the ends_at index for the calendar-upcoming shape', function () {
    seedEventArchiveVolume();

    // Same shape as the calendar upcoming list and the feed/home listings:
    // not-yet-ended rows walked in starts_at order with a small limit. The
    // `status <> draft` predicate is not btree-indexable, so the ends_at
    // range does the bounding and status filters the handful of live rows.
    $plan = hotPathIndexPlan(
        'SELECT * FROM events WHERE ends_at >= now() AND status <> ? ORDER BY starts_at LIMIT 20',
        [EventStatus::Draft->value]
    );

    expect($plan)->toContain('events_ends_at_index');
});

it('uses the (starts_at, id) index for the past-archive shape', function () {
    seedEventArchiveVolume();

    // Same shape as PastEvents: already-ended rows, most recent first. A
    // backward ordered scan, no sort.
    $plan = hotPathIndexPlan(
        'SELECT * FROM events WHERE ends_at < now() AND status <> ? ORDER BY starts_at DESC, id DESC LIMIT 20',
        [EventStatus::Draft->value]
    );

    expect($plan)->toContain('events_starts_at_id_index');
});

it('uses the (starts_at, id) index for the neighbour shape', function () {
    seedEventArchiveVolume();
    $event = Event::factory()->create([
        'status' => EventStatus::Published,
        'starts_at' => now()->addDays(7),
        'ends_at' => now()->addDays(7)->addHours(2),
    ]);

    // Same shape as EventPageController::neighbor(): the adjacent event in
    // (starts_at, id) order with the double-header tiebreak.
    $plan = hotPathIndexPlan(
        'SELECT id FROM events WHERE status <> ? AND status <> ? AND (starts_at > ? OR (starts_at = ? AND id > ?)) ORDER BY starts_at, id LIMIT 1',
        [EventStatus::Draft->value, EventStatus::Cancelled->value, $event->starts_at, $event->starts_at, $event->getKey()]
    );

    expect($plan)->toContain('events_starts_at_id_index');
});
