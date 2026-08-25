<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Exceptions\EventAtCapacityException;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\EventService;
use Illuminate\Support\Facades\DB;

/*
 * The last free slot.
 *
 * `unique(event_id, user_id)` stops one member answering twice and does nothing at
 * all about capacity: two different members both reading `count < capacity` and
 * both inserting is a perfectly legal pair of rows. The only thing that stops it is
 * serialising the read and the write behind a row lock on the event.
 *
 * This suite runs on committed data (DatabaseTruncation, not RefreshDatabase),
 * because the competitors are separate OS processes on their own connections and
 * cannot see rows sitting inside the test's own uncommitted transaction.
 */

/**
 * Launch a worker process and return its handle.
 *
 * @return array{process: resource, stdout: resource, stderr: resource}
 */
function startRaceWorker(string $eventKey, int $userId, int $startAtMs): array
{
    $config = config('database.connections.pgsql');

    $env = [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'APP_ENV' => 'testing',
        'APP_KEY' => config('app.key'),
        'APP_TIMEZONE' => 'UTC',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        // The stub write-back job must not run here; this test is about the row lock.
        'QUEUE_CONNECTION' => 'null',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) $config['host'],
        'DB_PORT' => (string) $config['port'],
        'DB_DATABASE' => (string) $config['database'],
        'DB_USERNAME' => (string) $config['username'],
        'DB_PASSWORD' => (string) $config['password'],
    ];

    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

    $process = proc_open(
        [PHP_BINARY, base_path('tests/Support/rsvp_race_worker.php'), $eventKey, (string) $userId, (string) $startAtMs],
        $descriptors,
        $pipes,
        base_path(),
        $env,
    );

    expect($process)->toBeResource('process');

    return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
}

/**
 * @param  array{process: resource, stdout: resource, stderr: resource}  $worker
 * @return array<string, mixed>
 */
function finishRaceWorker(array $worker): array
{
    $stdout = stream_get_contents($worker['stdout']);
    $stderr = stream_get_contents($worker['stderr']);
    fclose($worker['stdout']);
    fclose($worker['stderr']);
    proc_close($worker['process']);

    $decoded = json_decode((string) $stdout, true);

    if (! is_array($decoded)) {
        throw new RuntimeException("Race worker produced no result.\nstdout: {$stdout}\nstderr: {$stderr}");
    }

    return $decoded;
}

/** How many other backends on this database are blocked waiting for a lock. */
function backendsWaitingOnLock(): int
{
    // Postgres caches the statistics snapshot for the life of a transaction: the
    // first read of pg_stat_activity inside a transaction is the only one that
    // touches reality, and every later read in the same transaction hands back that
    // same snapshot. This poll runs inside the transaction that holds the row lock,
    // so without the clear it faithfully reports the world as it was before the
    // competitors connected — "waiting=0" for the full timeout while two backends
    // queue up in plain sight.
    DB::select('select pg_stat_clear_snapshot()');

    $row = DB::selectOne(
        'select count(*) as waiting from pg_stat_activity
          where datname = current_database()
            and wait_event_type = ?
            and pid <> pg_backend_pid()',
        ['Lock'],
    );

    return (int) ($row->waiting ?? 0);
}

it('gives the last free slot to exactly one of two concurrent transactions', function () {
    $event = Event::factory()->create([
        'status' => EventStatus::Published,
        'capacity' => 1,
    ]);
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    // Hold the event row from the test's own connection. Both workers will then
    // pile up behind *this* lock, which is how we know for certain that their two
    // transactions are open and overlapping before either is allowed to proceed.
    //
    // The `finally` is not tidiness. If this test dies with the transaction still
    // open, the connection is recycled with the test app and the row lock outlives
    // it — and the next test's TRUNCATE waits on that lock forever, turning one red
    // test into a suite that never returns.
    $workers = [];
    $waiting = 0;

    try {
        DB::beginTransaction();
        Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

        $startAt = (int) (microtime(true) * 1000) + 750;
        $workers = [
            startRaceWorker($event->event_key, $alice->id, $startAt),
            startRaceWorker($event->event_key, $bob->id, $startAt),
        ];

        $deadline = microtime(true) + 20;

        while (microtime(true) < $deadline) {
            $waiting = backendsWaitingOnLock();

            if ($waiting >= 2) {
                break;
            }

            usleep(25_000);
        }
    } finally {
        DB::rollBack();
    }

    $results = array_map(finishRaceWorker(...), $workers);
    $outcomes = array_column($results, 'outcome');
    sort($outcomes);

    // Half one: they really did contend. A read-then-write implementation never
    // takes the lock, so it never queues behind us and this assertion is the one
    // that fails — before any of the counting below can accidentally pass.
    expect($waiting)->toBeGreaterThanOrEqual(
        2,
        'Both RSVP attempts should have blocked on the event row lock; '.
        'seeing fewer means the last slot is decided by a read-then-write, not by the database. '.
        'Outcomes were: '.json_encode($results),
    );

    // Half two: one winner, one clean typed refusal, and a database that agrees.
    expect($outcomes)->toBe(['accepted', 'at_capacity'], 'Outcomes were: '.json_encode($results));

    $refusal = collect($results)->firstWhere('outcome', 'at_capacity');
    expect($refusal['exception'])->toBe(EventAtCapacityException::class);

    expect(Rsvp::query()->where('event_id', $event->id)->where('status', RsvpStatus::Going)->count())->toBe(1);
});

it('lets a second member in when the winner stands down', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => 1]);
    $alice = User::factory()->create();
    $bob = User::factory()->create();

    $service = app(EventService::class);

    $service->rsvp($event, $alice, RsvpStatus::Going);

    expect(fn () => $service->rsvp($event, $bob, RsvpStatus::Going))
        ->toThrow(EventAtCapacityException::class);

    // "maybe" is not a seat, so standing down must free the slot for real.
    $service->rsvp($event, $alice, RsvpStatus::Maybe);
    $service->rsvp($event, $bob, RsvpStatus::Going);

    expect(Rsvp::query()->where('event_id', $event->id)->where('status', RsvpStatus::Going)->count())->toBe(1)
        ->and(Rsvp::query()->where('event_id', $event->id)->where('user_id', $bob->id)->value('status'))
        ->toBe(RsvpStatus::Going);
});

it('lets a member already holding a slot change nothing by re-answering going', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => 1]);
    $alice = User::factory()->create();

    $service = app(EventService::class);
    $service->rsvp($event, $alice, RsvpStatus::Going);
    $service->rsvp($event, $alice, RsvpStatus::Going);

    expect(Rsvp::query()->where('event_id', $event->id)->count())->toBe(1);
});
