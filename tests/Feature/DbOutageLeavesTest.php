<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/*
 * Dependency-free leaves under bot-database outage (TOG-6779).
 *
 * The `bot` connection (the Discord bot's views) is optional: every reader
 * must survive it being unreachable, and these leaves must keep answering.
 * This pins that promise with the production session/cache drivers configured,
 * so a middleware-stack regression that reintroduces a database read fails here.
 *
 * Scope note: this covers the *bot* connection only. The app's own `pgsql`
 * outage is pinned per-leaf where the leaf lives: `/about` survives via
 * routes/funnel.php (TOG-6853, pinned in tests/Feature/AboutPageTest.php)
 * and `/rules` survives the same way (TOG-6854, pinned in
 * tests/Feature/RulesPageTest.php) — both are session-free funnel routes, so
 * `StartSession` never opens pgsql before the view runs. Only `/discord`,
 * `/about` and `/rules` survive an app-DB outage. This file must stay green.
 */

/** Point the `bot` connection at something that cannot answer. */
if (! function_exists('breakBotConnectionForLeaves')) {
    function breakBotConnectionForLeaves(): void
    {
        Config::set('database.connections.bot', [
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
            'options' => [PDO::ATTR_TIMEOUT => 2],
        ]);

        // The connection is resolved and cached by the manager, so a config change
        // alone would not reach a connection an earlier test already opened.
        DB::purge('bot');
    }
}

it('serves the static leaves when the bot database refuses the connection', function () {
    // Production parity: SESSION_DRIVER=database and CACHE_STORE=database in
    // every environment we ship. phpunit.xml runs with array drivers, which
    // would hide a session/cache read on these routes.
    config()->set('session.driver', 'database');
    config()->set('cache.default', 'database');

    breakBotConnectionForLeaves();

    $this->get('/about')->assertOk()->assertSee('About Together We Own');
    $this->get('/rules')->assertOk()->assertSee('House rules');
});

it('keeps the discord fallback redirecting when the bot database is down', function () {
    config()->set('session.driver', 'database');
    config()->set('cache.default', 'database');

    breakBotConnectionForLeaves();

    $this->get('/discord')->assertStatus(302);
});

it('keeps the events page rendering when the bot database is down', function () {
    // Events read the app database, not the bot views — a bot outage is not
    // their outage. (App-DB outage honestly 500s; see the file header.)
    // Same production parity as the other two tests above, so a bot read
    // sneaking into this path fails here too.
    config()->set('session.driver', 'database');
    config()->set('cache.default', 'database');

    breakBotConnectionForLeaves();

    $this->get('/events')->assertOk();
});
