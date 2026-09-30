<?php

// TOG-7334: pin the exact production route surface.
//
// TOG-5618 proved the staging QA seam (`qa.login`) is absent outside staging,
// and TOG-5632 gates the design-lab routes out of production — but no general
// test asserts which routes exist in production at all. Any future staging-only
// or debug route (a second QA seam, a Dusk helper, an operator tool) would
// otherwise register in production silently. This test boots a production
// application and compares its full route list against an explicit allowlist,
// so adding, removing or renaming a production route fails loudly and the
// author updates the list deliberately.
//
// The allowlist pins reality, not intent: when a route is added, removed, or
// renamed in production, this test fails and the author updates the list
// deliberately — that failure is the mechanism working.
//
// TOG-9665: Livewire's FrontendAssets registers exactly one of
// `/livewire/livewire.js` (APP_DEBUG on) / `/livewire/livewire.min.js` (off),
// so no single allowlist line matches every boot. Both variants are normalized
// out of the exact comparison and the test pins the mechanism instead —
// exactly one variant registers — while anything else under a Livewire asset
// prefix still fails below as an unexpected route.

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Boot a fresh application in the given environment and return its routes as
 * sorted `METHODS uri` strings (for example `GET|HEAD auth/qa/{identity}`).
 *
 * @return list<string>
 */
function productionRouteKeys(string $environment): array
{
    $originalEnvironment = $_ENV['APP_ENV'] ?? null;
    $originalServerEnvironment = $_SERVER['APP_ENV'] ?? null;

    $_ENV['APP_ENV'] = $environment;
    $_SERVER['APP_ENV'] = $environment;

    try {
        /** @var Application $application */
        $application = require base_path('bootstrap/app.php');
        $application->make(Kernel::class)->bootstrap();

        $keys = [];

        foreach ($application['router']->getRoutes()->getRoutes() as $route) {
            $keys[] = implode('|', $route->methods()).' '.$route->uri();
        }

        sort($keys);

        return $keys;
    } finally {
        if ($originalEnvironment === null) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $originalEnvironment;
        }

        if ($originalServerEnvironment === null) {
            unset($_SERVER['APP_ENV']);
        } else {
            $_SERVER['APP_ENV'] = $originalServerEnvironment;
        }
    }
}

/**
 * Every route that may serve production traffic, as `METHODS uri`.
 *
 * Sorted. One line per route. A diff here is a deliberate product decision:
 * either a new production route ships (add its line) or a non-production
 * route leaked in (gate it out of production instead).
 *
 * @return list<string>
 */
function expectedProductionRoutes(): array
{
    return [
        'DELETE events/{event}/rsvp',
        'GET|HEAD /',
        'GET|HEAD about',
        'GET|HEAD admin',
        'GET|HEAD admin/events',
        'GET|HEAD admin/events/create',
        'GET|HEAD admin/events/{record}/edit',
        'GET|HEAD admin/featured-contents',
        'GET|HEAD admin/featured-contents/create',
        'GET|HEAD admin/featured-contents/{record}/edit',
        'GET|HEAD admin/join-attempts',
        'GET|HEAD admin/join-attempts/{record}',
        'GET|HEAD auth/discord/callback',
        'GET|HEAD auth/discord/redirect',
        'GET|HEAD auth/status',
        'GET|HEAD discord',
        'GET|HEAD e/{event}',
        'GET|HEAD events',
        'GET|HEAD events.ics',
        'GET|HEAD events.json',
        'GET|HEAD events.rss',
        'GET|HEAD events/past',
        'GET|HEAD events/{event}',
        'GET|HEAD events/{event}.ics',
        'GET|HEAD faq',
        'GET|HEAD filament/exports/{export}/download',
        'GET|HEAD filament/imports/{import}/failed-rows/download',
        'GET|HEAD join',
        'GET|HEAD join/callback',
        'GET|HEAD join/discord',
        // TOG-9665: no runtime-variant line here — Livewire registers exactly
        // one of `livewire.js` (APP_DEBUG on) / `livewire.min.js` (off), so the
        // exact comparison cannot pin either. The test below normalizes both
        // variants out and asserts exactly one registers. The `.map` file is
        // unconditional, so it stays pinned.
        'GET|HEAD livewire/livewire.min.js.map',
        'GET|HEAD livewire/preview-file/{filename}',
        'GET|HEAD members/{user}',
        'GET|HEAD privacy',
        'GET|HEAD profile',
        'GET|HEAD robots.txt',
        'GET|HEAD rules',
        'GET|HEAD sitemap_index.xml',
        'GET|HEAD storage/{path}',
        'GET|HEAD up',
        'PATCH events/{event}',
        // TOG-8440 deleted PATCH members/{user} (`profiles.update`): the
        // Livewire form is the single profile writer, so the member surface is
        // GET-only plus the shared POST livewire/update endpoint below.
        'POST admin/logout',
        'POST api/agent-events',
        // TOG-8403: the session-free CSP violation sink in routes/funnel.php.
        'POST csp-reports',
        'POST events',
        'POST events/{event}/cancel',
        'POST events/{event}/publish',
        'POST events/{event}/rsvp-pause',
        'POST events/{event}/rsvp-reopen',
        'POST livewire/update',
        'POST livewire/upload-file',
        'POST logout',
        'PUT events/{event}/rsvp',
        'PUT storage/{path}',
    ];
}

it('registers exactly the allowlisted routes in production', function () {
    $actual = productionRouteKeys('production');
    $expected = expectedProductionRoutes();

    // TOG-9665: normalize the debug-conditional Livewire runtime out of the
    // exact comparison. FrontendAssets registers exactly one of `livewire.js`
    // (APP_DEBUG on) / `livewire.min.js` (off), so pinning either line fails
    // on every boot with the other value — and forcing APP_DEBUG=false from
    // this helper does not stick: phpdotenv's immutable writer treats a
    // variable it loaded on an earlier boot in this process as fair game and
    // overwrites the override from .env on the next boot (probed 2026-09-29:
    // env() reads false right after forcing, true again after bootstrap when
    // the ambient variable is unset). Pin the mechanism instead: exactly one
    // of the two known variants registers. Anything else under a Livewire
    // asset URI — a second variant, a renamed file — is not filtered and
    // still fails below as an unexpected route.
    $livewireVariants = ['GET|HEAD livewire/livewire.js', 'GET|HEAD livewire/livewire.min.js'];
    $actualLivewire = array_values(array_intersect($actual, $livewireVariants));

    expect($actualLivewire)->toHaveCount(1, 'Livewire must register exactly one runtime variant, got: '.implode(', ', $actualLivewire));

    $actual = array_values(array_diff($actual, $livewireVariants));
    $expected = array_values(array_diff($expected, $livewireVariants));

    $unexpected = array_values(array_diff($actual, $expected));
    $missing = array_values(array_diff($expected, $actual));

    // The allowlist comparison below already covers this, but the staging QA
    // seam is the case the card calls out by name: if `qa.login` ever
    // registers outside staging, this message says so instead of burying it
    // in a list diff.
    expect($unexpected)->not->toContain('GET|HEAD auth/qa/{identity}');

    expect($unexpected)->toBeEmpty('non-production routes registered in production: '.implode(', ', $unexpected))
        ->and($missing)->toBeEmpty('allowlisted routes missing from production: '.implode(', ', $missing))
        ->and($actual)->toEqual($expected);
});

it('fails if the staging QA route registers outside staging', function (string $environment) {
    $keys = productionRouteKeys($environment);

    expect($keys)->not->toContain('GET|HEAD auth/qa/{identity}');

    $qaUris = array_values(array_filter(
        $keys,
        fn (string $key): bool => str_contains($key, 'auth/qa'),
    ));

    expect($qaUris)->toBeEmpty();
})->with(['production', 'local', 'testing']);
