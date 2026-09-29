<?php

use App\Jobs\SyncEventToDiscord;
use App\Support\QueueHealth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * `/up` reports the queue depth (TOG-8414).
 *
 * Laravel's built-in `/up` only knows healthy (200) versus boot failure (500),
 * so a silent queue backlog — silent RSVP delay, no 500, no log line — read as
 * "up" through the one outage shape members actually feel. `GET /up` now folds
 * the box probe's counting in: queue-depth plus oldest-job age in the payload,
 * `degraded` (still 200, never down) when deep.
 *
 * What is asserted here is the endpoint contract, not the counting — the
 * counting is pinned once, in QueueHealth, and its other reader
 * (queue:check-depth) pins its own thresholds in
 * tests/Feature/Console/CheckQueueDepthCommandTest.php:
 *
 *   - an empty queue answers healthy with zeroed counts;
 *   - pending past the warn threshold answers degraded *at HTTP 200* with the
 *     count and the oldest wait — a backlog is not an outage, and the deploy
 *     poll (`curl -f` in .github/workflows/deploy.yml) must keep passing
 *     through one;
 *   - pending past critical is still degraded, never down;
 *   - a non-database driver answers healthy with the queue reported unknown
 *     rather than a zero that would read as healthy;
 *   - an unreadable queue table still answers 200, with the queue unknown;
 *   - the route answers through an app-DB outage the same way the static
 *     funnel leaves do (the framework's old `/up` never touched the database,
 *     and the deploy poll must keep answering while it is down).
 */

beforeEach(function () {
    // phpunit.xml runs the suite on QUEUE_CONNECTION=sync, where depth is
    // uncountable by construction. These tests point the default connection
    // at the database driver, like the check-depth tests do. The rows land
    // inside the test's own transaction and roll back with it.
    config()->set('queue.default', 'database');
});

/** Seed $count write-back jobs, each with a distinct idempotency scope. */
function seedQueueHealthJobs(int $count): void
{
    foreach (range(1, $count) as $i) {
        SyncEventToDiscord::dispatch('queue-health-event-'.$i);
    }
}

/**
 * Age every queued job past the debounce window, simulating a backlog.
 *
 * Both stamps move: `available_at` so the jobs read as pending rather than
 * delayed, and `created_at` because a real backlog is jobs dispatched minutes
 * ago that no worker has picked up — and the reported age is measured from
 * dispatch, which is when the member's answer started waiting.
 */
function ageQueueHealthJobs(): void
{
    $aged = now()->subMinutes(5)->timestamp;
    DB::table('jobs')->update(['available_at' => $aged, 'created_at' => $aged]);
}

it('reports an empty queue as healthy with zeroed counts', function () {
    $this->get('/up')
        ->assertOk()
        ->assertJson([
            'status' => 'healthy',
            'queue' => [
                'status' => 'healthy',
                'pending' => 0,
                'delayed' => 0,
                'reserved' => 0,
                'total' => 0,
                'failed' => 0,
                'oldest_pending_age_seconds' => null,
                'warn_at' => QueueHealth::WARN_AT,
                'critical_at' => QueueHealth::CRITICAL_AT,
            ],
        ]);
});

it('reports degraded at 200 with the count and oldest age past the warn threshold', function () {
    // Past warn (20) but below critical (100): 500 in the acceptance case is
    // the same shape one level up, and seeding hundreds of rows buys nothing
    // the threshold edge does not already prove.
    seedQueueHealthJobs(QueueHealth::WARN_AT + 5);
    ageQueueHealthJobs();

    $this->get('/up')
        ->assertOk()
        ->assertJson([
            'status' => 'degraded',
            'queue' => [
                'status' => 'degraded',
                'pending' => QueueHealth::WARN_AT + 5,
                'total' => QueueHealth::WARN_AT + 5,
            ],
        ])
        ->assertJsonPath('queue.oldest_pending_age_seconds', fn ($age) => $age >= 300);
});

it('reports degraded with the count at the 500-job acceptance scale', function () {
    // The card's literal acceptance case: 500 queued jobs → degraded with the
    // count. Same shape as the threshold edge above, at production scale.
    seedQueueHealthJobs(500);
    ageQueueHealthJobs();

    $this->get('/up')
        ->assertOk()
        ->assertJson([
            'status' => 'degraded',
            'queue' => [
                'status' => 'degraded',
                'pending' => 500,
                'total' => 500,
            ],
        ])
        ->assertJsonPath('queue.oldest_pending_age_seconds', fn ($age) => $age >= 300);
});

it('stays degraded, never down, past the critical threshold', function () {
    seedQueueHealthJobs(QueueHealth::CRITICAL_AT + 5);
    ageQueueHealthJobs();

    // 200, not 500: deep past critical points at the worker being down, but
    // the web release itself is fine, and the deploy poll must keep passing
    // through a backlog.
    $this->get('/up')
        ->assertOk()
        ->assertJson([
            'status' => 'degraded',
            'queue' => [
                'status' => 'degraded',
                'pending' => QueueHealth::CRITICAL_AT + 5,
            ],
        ]);
});

it('reports the queue unknown, not zero, on a driver with no countable depth', function () {
    config()->set('queue.default', 'sync');

    $this->get('/up')
        ->assertOk()
        ->assertJson([
            'status' => 'healthy',
            'queue' => [
                'status' => 'unknown',
                'pending' => null,
            ],
        ]);
});

it('still answers 200 with the queue unknown when the queue table will not answer', function () {
    // Point the queue's connection at a refused port, the way the funnel
    // outage tests point `pgsql`. The queue read must fail into `unknown`,
    // never into a 500 — an `/up` that errors because the queue is slow
    // mistakes the smoke detector for the fire.
    $original = config('database.connections.pgsql');

    Config::set('database.connections.pgsql', [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        // Nothing listens here. A refused connection comes back immediately,
        // so this stays a fast test rather than one that waits out a timeout.
        'port' => 1,
        'database' => 'nope',
        'username' => 'nope',
        'password' => 'nope',
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => 'prefer',
        'timezone' => 'Etc/UTC',
    ]);

    DB::purge(config('queue.connections.database.connection'));

    try {
        $this->get('/up')
            ->assertOk()
            ->assertJson([
                'status' => 'healthy',
                'queue' => [
                    'status' => 'unknown',
                    'pending' => null,
                ],
            ]);
    } finally {
        // Restore before RefreshDatabase teardown runs: its
        // beforeApplicationDestroyed callback reconnects the transacted
        // default connection, and leaving the refused-port config in place
        // would fail every later test there instead of failing here. The
        // replacement transaction keeps the suite's wrapping transaction
        // intact so no re-migration is triggered.
        Config::set('database.connections.pgsql', $original);
        DB::purge(config('queue.connections.database.connection'));
        DB::connection('pgsql')->beginTransaction();
    }
});

it('keeps the funnel placement: no session, cookie or throttle middleware', function () {
    // The structural pin, matching the about/faq leaves: the endpoint must
    // keep answering through an app-DB outage, and a `web`-group StartSession
    // database open before the controller runs would end that. Unlike those
    // leaves it needs no CSP — it answers JSON, not a document.
    $route = Route::getRoutes()->getByName('up');

    expect($route)->not->toBeNull('Route [up] is missing. It is the deploy health check — it must exist.');
    expect($route->gatherMiddleware())->toBe(
        [],
        'Route [up] has picked up middleware. The health check must stay on the funnel\'s empty stack.'
    );
});

it('answers through an app-DB outage with the queue unknown', function () {
    // The TOG-6853 repro with production parity, applied to the endpoint:
    // database session and cache configured, then `pgsql` pointed at a
    // refused port. The funnel route carries no middleware, so StartSession
    // never opens the database; only the queue read fails, into `unknown`.
    config()->set('session.driver', 'database');
    config()->set('cache.default', 'database');

    $original = config('database.connections.pgsql');

    Config::set('database.connections.pgsql', [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        // Nothing listens here. A refused connection comes back immediately,
        // so this stays a fast test rather than one that waits out a timeout.
        'port' => 1,
        'database' => 'nope',
        'username' => 'nope',
        'password' => 'nope',
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => 'prefer',
        'timezone' => 'Etc/UTC',
    ]);

    DB::purge('pgsql');

    try {
        $this->get('/up')
            ->assertOk()
            ->assertJson([
                'status' => 'healthy',
                'queue' => [
                    'status' => 'unknown',
                    'pending' => null,
                ],
            ]);
    } finally {
        Config::set('database.connections.pgsql', $original);
        DB::purge('pgsql');
        DB::connection('pgsql')->beginTransaction();
    }
});
