<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// The deploy and the CI pipeline both poll /up. Laravel's built-in health
// route only proves the app boots far enough to answer — it renders a fixed
// page with no database signal, so a deploy with a failed migrate looks
// healthy. TOG-8711 replaces it with an explicit probe (routes/health.php,
// App\Http\Controllers\HealthCheckController): a DB ping plus the pending
// migration count as JSON, 503 when either is wrong. These tests pin that
// contract.

it('answers the health check with the database signal', function () {
    // RefreshDatabase migrates everything on disk, so a healthy test app has
    // nothing pending: this pins both the shape and the zero baseline the
    // pending test below measures against.
    $this->get('/up')->assertOk()->assertExactJson([
        'status' => 'ok',
        'db' => 'ok',
        'pending_migrations' => 0,
    ]);
});

it('answers 503 with a null pending count when the database refuses the connection', function () {
    // No staging outage needed: point `pgsql` at a refused port like
    // AboutPageTest does for the funnel leaves. A refused connection comes
    // back immediately, so this stays a fast test rather than one that waits
    // out a timeout.
    $original = config('database.connections.pgsql');

    Config::set('database.connections.pgsql', [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
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

    // The connection is resolved and cached by the manager, so a config change
    // alone would not reach a connection an earlier part of the test opened.
    DB::purge('pgsql');

    try {
        $this->get('/up')->assertStatus(503)->assertExactJson([
            'status' => 'error',
            'db' => 'error',
            'pending_migrations' => null,
        ]);
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

it('answers 503 with the pending count when a migration has not run', function () {
    // A migration file on disk that no connection has recorded: the failed
    // `migrate --force` this probe exists to catch. A scratch directory
    // registered on the test app's migrator only — never on the repo's
    // database/migrations — so nothing leaks into other tests or the suite's
    // own migration run. The probe only globs file names against the
    // `migrations` table, it never requires the file, so the stub is inert.
    $dir = sys_get_temp_dir().'/tog-8711-pending-'.uniqid();
    mkdir($dir);
    $file = $dir.'/2099_01_01_000000_fake_pending_migration.php';
    file_put_contents($file, "<?php\n\n// Inert stub for the /up pending-migration test.\n");

    app('migrator')->path($dir);

    try {
        $response = $this->get('/up')->assertStatus(503)->assertJson([
            'status' => 'error',
            'db' => 'ok',
        ]);

        expect($response->json('pending_migrations'))->toBeGreaterThanOrEqual(1);
    } finally {
        unlink($file);
        rmdir($dir);
    }
});

it('carries no route middleware, so the database failure reaches the probe', function () {
    // The structural half of the outage test above: SESSION_DRIVER=database
    // in every environment shipped, so a `web`-group route opens Postgres in
    // StartSession before the controller runs — and the day Postgres is down
    // that throws a 500 before the probe can answer its structured 503.
    // routes/health.php loads with an empty stack for exactly this reason.
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($route): bool => $route->uri() === 'up');

    expect($route)->not->toBeNull('Route [up] is missing. The explicit health probe must exist.');
    expect($route->gatherMiddleware())->toBe(
        [],
        'Route [up] has picked up middleware that would touch the database before the probe runs.',
    );
});

it('keeps answering during maintenance mode, so deploys can poll it', function () {
    // Replacing the framework's `health: '/up'` route dropped the
    // maintenance exemption the framework would have added; bootstrap/app.php
    // carries it explicitly instead. The deploy pipeline polls `/up` while
    // the site is down for maintenance, so losing it reads as a failed
    // deploy. Same artisan-down pattern DiscordFunnelTest uses for /discord.
    $this->artisan('down')->assertSuccessful();

    try {
        $this->get('/up')->assertOk()->assertJson(['status' => 'ok']);
    } finally {
        $this->artisan('up')->assertSuccessful();
    }
});
