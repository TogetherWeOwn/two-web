<?php

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/*
 * `/up` is the deploy and uptime signal: the database readiness folded in
 * here (TOG-8711), the queue depth in
 * tests/Feature/QueueHealthEndpointTest.php (TOG-8414).
 *
 * The deploy poll (`curl -f` in .github/workflows/deploy.yml) and the CI
 * pipeline both read it, so the contract pinned here is the release gate:
 *
 *   - a healthy app answers 200 with `db: ok` and `pending_migrations: 0`;
 *   - an unreachable database answers 503 `degraded` with `db: error` — a
 *     deploy with a failed database fails the poll instead of shipping;
 *   - pending migrations answer 503 `degraded` with the count — a deploy
 *     with a failed migrate fails the poll instead of serving a behind
 *     schema (mirrors `migrate:status`: files minus ran);
 *   - a missing migration repository means nothing has run, so every file
 *     is pending — still 503, never an unhandled 500;
 *   - a repository that will not answer is distinct from a dead database:
 *     `db` stays `ok`, the count is `null`, still 503;
 *   - nothing thrown ever reaches the wire — no exception text, no SQL.
 *
 * The outage fixtures point at a refused port, the way the funnel outage
 * tests point `pgsql`: fast, no timeout wait, covered by test only — no
 * staging outage is needed.
 */

// The deploy and the CI pipeline both poll /up. If the app cannot boot far enough
// to answer it, nothing else in this suite is meaningful.
it('answers the health check', function () {
    $this->get('/up')
        ->assertOk()
        ->assertJson([
            'status' => 'healthy',
            'db' => 'ok',
            'pending_migrations' => 0,
        ]);
});

it('answers 503 degraded with db:error when the database is unreachable', function () {
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
        $response = $this->get('/up');

        $response->assertStatus(503)->assertJson([
            'status' => 'degraded',
            'db' => 'error',
            'queue' => ['status' => 'unknown'],
        ]);

        expect($response->json('pending_migrations'))->toBeNull();

        // Nothing thrown reaches the wire: neither the refused username nor
        // any SQLSTATE text may leak into the probe body.
        expect($response->getContent())->not->toContain('nope')->not->toContain('SQLSTATE');
    } finally {
        // Restore before RefreshDatabase teardown runs: its
        // beforeApplicationDestroyed callback reconnects the transacted
        // default connection, and leaving the refused-port config in place
        // would fail every later test there instead of failing here. The
        // replacement transaction keeps the suite's wrapping transaction
        // intact so no re-migration is triggered.
        Config::set('database.connections.pgsql', $original);
        DB::purge('pgsql');
        DB::connection('pgsql')->beginTransaction();
    }
});

it('answers 503 degraded with the pending count when migrations are behind', function () {
    // A scratch migration path the suite never runs: one file the
    // repository has not seen, so the pending count the endpoint reports
    // mirrors `migrate:status` saying Pending. Registered on this test's
    // own migrator singleton only — the next test boots a fresh app.
    $dir = (getenv('PAPERCLIP_SCRATCH_DIR') ?: sys_get_temp_dir()).'/up-health-pending-'.uniqid();
    mkdir($dir, 0777, true);
    $stub = $dir.'/2099_01_01_000000_health_check_probe_stub.php';
    file_put_contents($stub, "<?php\n\n// Probe stub for the /up pending-migration test. Never migrated on purpose.\n");

    /** @var Migrator $migrator */
    $migrator = app(Migrator::class);
    $migrator->path($dir);

    try {
        $response = $this->get('/up');

        $response->assertStatus(503)->assertJson([
            'status' => 'degraded',
            'db' => 'ok',
        ]);

        expect($response->json('pending_migrations'))->toBeGreaterThanOrEqual(1);
    } finally {
        unlink($stub);
        rmdir($dir);
    }
});

it('counts every migration file as pending when the repository table is missing', function () {
    // An unmigrated database has no repository table — nothing has run, so
    // every file is pending. DDL is transactional in Postgres, so the drop
    // rolls back with the test's own transaction.
    DB::statement('drop table migrations');

    $response = $this->get('/up');

    $response->assertStatus(503)->assertJson([
        'status' => 'degraded',
        'db' => 'ok',
    ]);

    expect($response->json('pending_migrations'))->toBeGreaterThan(0);
});

it('answers 503 with db:ok and a null count when the migration repository will not answer', function () {
    // The database answers, but the repository behind the pending count
    // does not — pointed at a refused port through the repository's own
    // source, leaving the default connection healthy. Distinct from a dead
    // database: `db` stays `ok`, the count is `null`, still 503, never an
    // unhandled 500.
    Config::set('database.connections.repo_refused', [
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

    /** @var Migrator $migrator */
    $migrator = app(Migrator::class);
    $migrator->getRepository()->setSource('repo_refused');

    try {
        $response = $this->get('/up');

        $response->assertStatus(503)->assertJson([
            'status' => 'degraded',
            'db' => 'ok',
        ]);

        expect($response->json('pending_migrations'))->toBeNull();
    } finally {
        $migrator->getRepository()->setSource(config('database.default'));
        DB::purge('repo_refused');
    }
});

it('keeps answering through maintenance mode', function () {
    // The framework exempts `/up` from maintenance (`health: '/up'` in
    // bootstrap/app.php calls PreventRequestsDuringMaintenance::except) and
    // the funnel override must not lose that: the deploy poll reads /up to
    // learn the box is back, and maintenance must not silence it.
    Artisan::call('down');

    try {
        $this->get('/up')
            ->assertOk()
            ->assertJson([
                'status' => 'healthy',
                'db' => 'ok',
                'pending_migrations' => 0,
            ]);
    } finally {
        Artisan::call('up');
    }
});
