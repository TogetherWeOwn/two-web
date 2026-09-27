<?php

use App\Http\Middleware\AddContentSecurityPolicy;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * The about leaf (TOG-5310). A static Route::view page: no controller, no
 * database reads, no Livewire. It exists so a prospective member can see what
 * the community is before clicking join. Deliberately a different page than the
 * /rules slice in TOG-5147.
 *
 * It lives in routes/funnel.php with an empty middleware stack (TOG-6853), not
 * in the `web` group: SESSION_DRIVER=database everywhere we ship, so a `web`
 * route opens Postgres in StartSession before the view runs, and the day the
 * app database is down is the day the about page 500s. The tests below pin
 * that placement — structurally and behaviourally.
 */

it('serves the about page', function () {
    $this->get('/about')
        ->assertOk()
        ->assertSee('About Together We Own')
        ->assertSee('Est. 1998')
        ->assertSee('Voice-first')
        ->assertSee('data-testid="about-facts"', escape: false)
        ->assertSee('data-testid="about-join"', escape: false)
        ->assertSee(route('join'), escape: false);
});

it('advertises the about page in the sitemap', function () {
    $this->get('/sitemap_index.xml')
        ->assertOk()
        ->assertSee(route('about'), escape: false);
});

it('links the about page from the homepage footer', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(route('about'), escape: false);
});

it('touches no database at all, even with a database-backed session', function () {
    // The reason routes/funnel.php exists. SESSION_DRIVER=database in every
    // environment we ship, so a route inside the `web` middleware group queries
    // Postgres in StartSession before the view runs — and the day Postgres is
    // down is the day the about page 500s.
    //
    // phpunit.xml runs the suite with an array session, which would hide that.
    // Configure the production driver first, then count.
    config()->set('session.driver', 'database');
    config()->set('cache.default', 'database');

    $this->expectsDatabaseQueryCount(0);

    $this->get('/about')->assertOk();
});

it('carries no session, cookie or throttle middleware', function () {
    // The structural half of the test above: query counting proves today's code
    // is clean, this proves `/about` was not quietly moved back into a group
    // that would make it dirty again. The one allowed entry is the CSP
    // middleware (TOG-6770, pinned below): it reads no session, cache or
    // database, so it cannot reintroduce the outage failure.
    $route = Route::getRoutes()->getByName('about');

    expect($route)->not->toBeNull('Route [about] is missing. It is a static leaf — it must exist.');
    expect($route->gatherMiddleware())->toBe(
        [AddContentSecurityPolicy::class],
        'Route [about] has picked up middleware beyond the CSP policy.'
    );
});

it('stays 200 when the app database refuses the connection', function () {
    // The TOG-6853 repro with production parity: database session and cache
    // configured, then `pgsql` pointed at a refused port. The funnel route
    // carries only the CSP middleware, so StartSession never opens the
    // database and the static view still renders.
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
        $this->get('/about')->assertOk()->assertSee('About Together We Own');
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

it('keeps the strict CSP on the funnel-served about page', function () {
    // /about left the `web` group for TOG-6853, and the CSP middleware
    // (TOG-6770) is registered on that group — attaching it to the route alone
    // keeps the document policy without reintroducing a session or database
    // read. Unlike /discord's redirect, /about answers with HTML, so it earns
    // the same strict policy as the guest homepage. The expected value is
    // pinned in full, like tests/Feature/ContentSecurityPolicyTest.php pins
    // it — a widening has to edit an assertion, not slip past one.
    //
    // TOG-7095 follow-up: `upgrade-insecure-requests` is https-only, and the
    // explicit `form-action` directive is gone entirely (it blocked the admin
    // logout POST in Chrome while the identical site sign-out passed;
    // submissions fall back to `default-src 'self'`). Feature tests run over
    // http, so the http expectation carries neither. The https variant is
    // pinned in ContentSecurityPolicyTest.
    $this->get('/about')
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "default-src 'self'; "
            ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
            ."style-src 'self' 'unsafe-inline'; "
            ."img-src 'self' data: https:; "
            ."font-src 'self' data:; "
            .'connect-src \'self\'; '
            ."frame-ancestors 'none'; "
            ."base-uri 'self'; "
            ."object-src 'none'");
});
