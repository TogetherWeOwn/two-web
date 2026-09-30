<?php

use App\Http\Controllers\PrivacyController;
use App\Http\Middleware\AddContentSecurityPolicy;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
 * The privacy-policy leaf (TOG-8609). A controller page, not Route::view,
 * because it renders the versioned content/privacy-policy-v*.md source — but
 * its one read is a file from disk, so like `/about` (TOG-6853) and `/faq`
 * (TOG-8396) it lives in routes/funnel.php with an empty middleware stack:
 * SESSION_DRIVER=database in every environment we ship, so a `web` route
 * opens Postgres in StartSession before the view runs, and the day the app
 * database is down is the day the policy page 500s. The tests below pin that
 * placement — structurally and behaviourally.
 */

it('serves the privacy policy page', function () {
    $this->get('/privacy')
        ->assertOk()
        ->assertSee('Privacy policy')
        ->assertSee('Your data, in plain language')
        ->assertSee('What we never store, ever', escape: false)
        ->assertSee('we never ask for one', escape: false)
        ->assertSee('Ask anytime to be removed', escape: false)
        ->assertSee('Version 1')
        ->assertSee('data-testid="privacy-policy"', escape: false)
        ->assertSee('data-testid="privacy-join"', escape: false)
        ->assertSee(route('join'), escape: false);
});

it('renders the versioned content file the controller points at', function () {
    // A policy change ships as a NEW file and the controller repoints; this
    // pins the wiring so a repoint at a missing file fails here, not as a
    // 500 on the public page.
    expect(file_exists(base_path(PrivacyController::POLICY_FILE)))->toBeTrue(
        'PrivacyController::POLICY_FILE does not exist on disk.'
    );

    $markdown = file_get_contents(base_path(PrivacyController::POLICY_FILE));

    expect($markdown)->toContain('What we store')
        ->and($markdown)->toContain('Deletion');
});

it('advertises the privacy page in the sitemap', function () {
    $this->get('/sitemap_index.xml')
        ->assertOk()
        ->assertSee(route('privacy'), escape: false);
});

it('links the privacy page from the homepage footer', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(route('privacy'), escape: false);
});

it('touches no database at all, even with a database-backed session', function () {
    // The reason routes/funnel.php exists. SESSION_DRIVER=database in every
    // environment we ship, so a route inside the `web` middleware group queries
    // Postgres in StartSession before the view runs — and the day Postgres is
    // down is the day the privacy page 500s.
    //
    // phpunit.xml runs the suite with an array session, which would hide that.
    // Configure the production driver first, then count. The policy source is
    // a file read from disk, which is why this stays at zero queries.
    config()->set('session.driver', 'database');
    config()->set('cache.default', 'database');

    $this->expectsDatabaseQueryCount(0);

    $this->get('/privacy')->assertOk();
});

it('carries no session, cookie or throttle middleware', function () {
    // The structural half of the test above: query counting proves today's code
    // is clean, this proves `/privacy` was not quietly moved back into a group
    // that would make it dirty again. The one allowed entry is the CSP
    // middleware (TOG-6770, pinned below): it reads no session, cache or
    // database, so it cannot reintroduce the outage failure.
    $route = Route::getRoutes()->getByName('privacy');

    expect($route)->not->toBeNull('Route [privacy] is missing. It is a public leaf — it must exist.');
    expect($route->gatherMiddleware())->toBe(
        [AddContentSecurityPolicy::class],
        'Route [privacy] has picked up middleware beyond the CSP policy.'
    );
});

it('stays 200 when the app database refuses the connection', function () {
    // The TOG-6853 repro with production parity: database session and cache
    // configured, then `pgsql` pointed at a refused port. The funnel route
    // carries only the CSP middleware, so StartSession never opens the
    // database and the file-rendered view still renders.
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
        $this->get('/privacy')->assertOk()->assertSee('Privacy policy');
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

it('keeps the strict CSP on the funnel-served privacy page', function () {
    // /privacy lives outside the `web` group like /about (TOG-6853) and /faq
    // (TOG-8396), and the CSP middleware (TOG-6770) is registered on that
    // group — attaching it to the route alone keeps the document policy
    // without reintroducing a session or database read. Unlike /discord's
    // redirect, /privacy answers with HTML, so it earns the same strict policy
    // as the guest homepage. The expected value is pinned in full, like
    // tests/Feature/ContentSecurityPolicyTest.php pins it — a widening has to
    // edit an assertion, not slip past one. Keep the two in sync: both quote
    // App\Http\Middleware\AddContentSecurityPolicy.
    // (TOG-7095 removed `form-action` and made `upgrade-insecure-requests`
    // https-only, so the http expectation carries neither.)
    $this->get('/privacy')
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
