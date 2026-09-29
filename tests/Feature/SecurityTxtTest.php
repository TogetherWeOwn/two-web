<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// RFC 9116 security.txt (TOG-8724). A session-free funnel route, not a static
// file in public/.well-known/: nginx try_files serves a static file before
// Laravel ever runs, the same shadowing that pinned TOG-7071 for robots.txt.
// Contact is GitHub private vulnerability reporting per SECURITY.md — the repo
// has no security mailbox, so there is no email address to put here.

it('serves security.txt as text/plain with contact and expiry', function () {
    $this->get('/.well-known/security.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('Contact: https://github.com/TogetherWeOwn/two-web/security/advisories/new', escape: false)
        ->assertSee('Expires: ', escape: false);
});

it('advertises a future expiry within one year', function () {
    $body = $this->get('/.well-known/security.txt')->assertOk()->getContent();

    preg_match('/^Expires: (\S+)$/m', $body, $matches);
    expect($matches)->toHaveCount(2, 'security.txt carries no Expires line: '.$body);

    $expires = strtotime($matches[1]);
    expect($expires)->not->toBeFalse('Expires is not a parseable timestamp: '.$matches[1])
        ->and($expires)->toBeGreaterThan(time(), 'Expires is in the past')
        ->and($expires)->toBeLessThanOrEqual(time() + 366 * 86400, 'Expires is more than a year out');
});

it('has no static well-known file shadowing the route', function () {
    // A static public/.well-known/security.txt would win under nginx
    // try_files (and Apache !-f) and silently re-pin a stale Expires date.
    expect(public_path('.well-known/security.txt'))->not->toBeFile();
});

it('carries no session, cookie or throttle middleware', function () {
    // The structural half of the zero-query test below: this proves
    // `security-txt` was not quietly moved back into a group that would make
    // it dirty again. No CSP entry either — unlike the funnel's HTML leaves,
    // this answers text/plain and earns no document policy.
    $route = Route::getRoutes()->getByName('security-txt');

    expect($route)->not->toBeNull('Route [security-txt] is missing. It is a static leaf — it must exist.');
    expect($route->gatherMiddleware())->toBe(
        [],
        'Route [security-txt] has picked up middleware beyond the empty funnel stack.'
    );
});

it('touches no database at all, even with a database-backed session', function () {
    // The reason routes/funnel.php exists. SESSION_DRIVER=database in every
    // environment we ship, so a route inside the `web` middleware group queries
    // Postgres in StartSession before the closure runs — and the day Postgres
    // is down is the day the contact file 500s, exactly when a reporter needs
    // it most. phpunit.xml runs the suite with an array session, which would
    // hide that. Configure the production driver first, then count.
    config()->set('session.driver', 'database');
    config()->set('cache.default', 'database');

    $this->expectsDatabaseQueryCount(0);

    $this->get('/.well-known/security.txt')->assertOk();
});

it('stays 200 when the app database refuses the connection', function () {
    // Production parity: database session and cache configured, then `pgsql`
    // pointed at a refused port. The funnel route carries no middleware, so
    // StartSession never opens the database and the static body still renders.
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

    // The connection is resolved and cached by the manager, so a config change
    // alone would not reach a connection an earlier part of the test opened.
    DB::purge('pgsql');

    try {
        $this->get('/.well-known/security.txt')->assertOk()
            ->assertSee('Contact: ', escape: false);
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
