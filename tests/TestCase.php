<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

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
     *   - a missing or broken build fails `dusk` and `budgets`, which both build
     *     for real and render the layout in real Chrome.
     *
     * Dusk does not come through here — DuskTestCase extends Laravel\Dusk\TestCase
     * — so the browser suite still gets the real, built assets.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
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
