<?php

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 *   - a missing or unreadable migration directory is unknown schema state,
 *     not proof the schema is current: `db` stays `ok`, the count is
 *     `null`, still 503 — discovery is globbing, so a missing path would
 *     otherwise yield zero files and read as healthy;
 *   - the diagnostics themselves can never sink the probe: even a throwing
 *     logger still answers the 503 envelope, and only the exception class
 *     (never the message) reaches the log;
 *   - nothing thrown ever reaches the wire — no exception text, no SQL.
 *
 * The outage fixtures point at a refused port, the way the funnel outage
 * tests point `pgsql`: fast, no timeout wait, covered by test only — no
 * staging outage is needed.
 */

/**
 * Make the health-check diagnostics themselves throw, the way a
 * full/unwritable/misconfigured log destination would. Only the probe's own
 * messages throw — everything else the framework logs during the request
 * still passes through, so the throwing mock cannot 500 the request by
 * another path.
 */
function throwOnHealthCheckLog(): void
{
    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []) {
        if (str_starts_with($message, 'Health check')) {
            throw new RuntimeException('synthetic logger failure');
        }

        return true;
    });
}

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

it('still answers 503 when its own diagnostics throw during a database outage', function () {
    // A full, unwritable or misconfigured log destination concurrent with a
    // database outage: the probe's diagnostics throw, but the promised JSON
    // 503 must still answer — the catch must never let the logger sink the
    // endpoint it reports on.
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
    throwOnHealthCheckLog();

    try {
        $response = $this->get('/up');

        $response->assertStatus(503)->assertJson([
            'status' => 'degraded',
            'db' => 'error',
            'queue' => ['status' => 'unknown'],
        ]);

        expect($response->json('pending_migrations'))->toBeNull();
    } finally {
        Config::set('database.connections.pgsql', $original);
        DB::purge('pgsql');
        DB::connection('pgsql')->beginTransaction();
    }
});

it('logs the exception class only, never the message, on probe failure', function () {
    // The refused credentials ride along in the connection exception, the
    // way a real outage carries host and SQLSTATE text. The probe's
    // diagnostics must record the class only: the logged `exception` value
    // is an actual class name, and the failure details appear in no record.
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

    $records = [];
    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []) use (&$records) {
        $records[] = ['message' => $message, 'context' => $context];

        return true;
    });

    try {
        $response = $this->get('/up');

        $response->assertStatus(503)->assertJson([
            'status' => 'degraded',
            'db' => 'error',
        ]);

        $probe = array_values(array_filter(
            $records,
            fn (array $record) => str_starts_with($record['message'], 'Health check')
        ));

        expect($probe)->not->toBeEmpty('The probe must log its own diagnostic on failure.');

        foreach ($probe as $record) {
            expect($record['context']['exception'] ?? null)->toBeString();
            expect(class_exists($record['context']['exception']))->toBeTrue(
                'The logged exception must be a class name, never a free-form message.'
            );
            expect(json_encode($record))->not->toContain('nope')->not->toContain('SQLSTATE');
        }
    } finally {
        Config::set('database.connections.pgsql', $original);
        DB::purge('pgsql');
        DB::connection('pgsql')->beginTransaction();
    }
});

it('still answers 503 when its own diagnostics throw during a repository failure', function () {
    // Same logger-failure shape as the database outage above, on the other
    // probe path: the database answers but the migration repository behind
    // the pending count does not, and the diagnostics throw too.
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

    throwOnHealthCheckLog();

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

it('answers 503 with a null count when a migration directory is missing', function () {
    // Discovery is globbing: a missing path yields zero files, which would
    // read as "current" if it were counted blindly. A missing required
    // directory is unknown schema state, not proof the schema is current —
    // still 503, with a null count. Registered on this test's own migrator
    // singleton only — the next test boots a fresh app.
    $missing = (getenv('PAPERCLIP_SCRATCH_DIR') ?: sys_get_temp_dir()).'/up-health-missing-'.uniqid();

    /** @var Migrator $migrator */
    $migrator = app(Migrator::class);
    $migrator->path($missing);

    expect(is_dir($missing))->toBeFalse('The probe path must be missing for this test to mean anything.');

    $response = $this->get('/up');

    $response->assertStatus(503)->assertJson([
        'status' => 'degraded',
        'db' => 'ok',
    ]);

    expect($response->json('pending_migrations'))->toBeNull();
});

it('answers 503 with a null count when a migration directory is unreadable', function () {
    // Same unknown-schema shape as a missing directory, for the path that
    // exists but cannot be read: zero discovered files is not evidence the
    // schema is current. Permissions are restored in teardown so the scratch
    // directory can be removed. Root bypasses permission checks, so the
    // unreadable probe needs a non-root user to mean anything.
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Unreadable-directory probe needs a non-root user.');
    }

    $dir = (getenv('PAPERCLIP_SCRATCH_DIR') ?: sys_get_temp_dir()).'/up-health-unreadable-'.uniqid();
    mkdir($dir, 0777, true);
    chmod($dir, 0000);

    /** @var Migrator $migrator */
    $migrator = app(Migrator::class);
    $migrator->path($dir);

    try {
        $response = $this->get('/up');

        $response->assertStatus(503)->assertJson([
            'status' => 'degraded',
            'db' => 'ok',
        ]);

        expect($response->json('pending_migrations'))->toBeNull();
    } finally {
        chmod($dir, 0777);
        rmdir($dir);
    }
});

it('still answers healthy with an existing but empty migration directory', function () {
    // Zero files is evidence when the directory exists — only a missing or
    // unreadable path is unknown schema state. The pending count still comes
    // from the real discovery paths, so a migrated suite answers 200.
    $dir = (getenv('PAPERCLIP_SCRATCH_DIR') ?: sys_get_temp_dir()).'/up-health-empty-'.uniqid();
    mkdir($dir, 0777, true);

    /** @var Migrator $migrator */
    $migrator = app(Migrator::class);
    $migrator->path($dir);

    try {
        $this->get('/up')
            ->assertOk()
            ->assertJson([
                'status' => 'healthy',
                'db' => 'ok',
                'pending_migrations' => 0,
            ]);
    } finally {
        rmdir($dir);
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
