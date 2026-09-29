<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Tests\Support\TestDatabaseGuard;
use Tests\Support\TestDatabaseRefusedException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Pest runs the PHP half of the app and nothing else — no Node, no
     * `npm run build`, no `public/build/manifest.json`. Without this, every test
     * that renders a page through the `app` layout dies inside `@vite` with a 500
     * and a stack trace that says nothing about the thing under test.
     *
     * This is not cosmetic. The manifest is a build artifact and `public/build`
     * is gitignored, so anyone who has run `npm run build` once has it forever
     * and a clean runner never does. The suite passed on every developer machine
     * and went red the first time it ran on real GitHub. Stubbing Vite here makes
     * the result identical everywhere — clean clone, laptop, CI.
     *
     * Nothing is given up for it. `@vite` output is not what a feature test
     * asserts, and the two defects this could hide are each covered harder
     * elsewhere:
     *
     *   - a missing or broken build fails `dusk` and `budgets`, which both build
     *     for real and render the layout in real Chrome. `ci/verify-pipeline.sh
     *     --lint` fails if either job ever stops building, because that is the
     *     assumption this method rests on.
     *   - an entrypoint that does not exist is caught with no build at all by
     *     tests/Unit/ViteEntrypointsTest.php.
     *
     * Dusk does not come through here — DuskTestCase extends Laravel\Dusk\TestCase
     * — so the browser suite still gets the real, built assets.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Refuse to touch the wrong database before any migration runs (TOG-9649).
     *
     * Ordering: `setUpTheTestEnvironment()` boots the application (so config is
     * final — `.env`, real environment and `phpunit.xml` merged) and then calls
     * `setUpTraits()`, inside which `RefreshDatabase` runs `migrate:fresh`. The
     * guard therefore sees the database the suite would actually migrate, and
     * throws before the first migration runs. The exception fails the test as an
     * error — never a skip, so a mispointed suite reads as red, not green.
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');

        if (! is_string($connection) || $connection === '') {
            throw new TestDatabaseRefusedException('Refusing to run tests: no default database connection is configured (TOG-9649).');
        }

        $database = config("database.connections.{$connection}.database");
        $username = config("database.connections.{$connection}.username");

        if (! is_string($database) || $database === '') {
            throw new TestDatabaseRefusedException('Refusing to run tests: the default database connection has no database name configured (TOG-9649).');
        }

        TestDatabaseGuard::check($connection, $database, is_string($username) ? $username : null);

        return parent::setUpTraits();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            // Production-mode tests populate Symfony's static host allowlist.
            // Laravel resets TrustHosts config, but not this request state;
            // otherwise a later testing-mode request inherits the old hosts.
            Request::setTrustedHosts([]);
        }
    }
}
