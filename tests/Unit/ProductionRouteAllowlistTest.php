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
// The allowlist pins reality, not intent: the design-lab routes are present
// here because TOG-5632 has not merged yet. When it merges, this test fails
// and the merger drops those two lines — that failure is the mechanism working.

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
        'GET|HEAD auth/discord/callback',
        'GET|HEAD auth/discord/redirect',
        // TOG-5632 removes these two lines when the design-lab gating merges.
        'GET|HEAD design-lab/hallmark',
        'GET|HEAD design-lab/taste',
        'GET|HEAD discord',
        'GET|HEAD e/{event}',
        'GET|HEAD events',
        'GET|HEAD events.ics',
        'GET|HEAD events.json',
        'GET|HEAD events.rss',
        'GET|HEAD events/past',
        'GET|HEAD events/{event}',
        'GET|HEAD events/{event}.ics',
        'GET|HEAD filament/exports/{export}/download',
        'GET|HEAD filament/imports/{import}/failed-rows/download',
        'GET|HEAD join',
        'GET|HEAD join/callback',
        'GET|HEAD join/discord',
        'GET|HEAD livewire/livewire.js',
        'GET|HEAD livewire/livewire.min.js.map',
        'GET|HEAD livewire/preview-file/{filename}',
        'GET|HEAD members/{user}',
        'GET|HEAD profile',
        'GET|HEAD robots.txt',
        'GET|HEAD rules',
        'GET|HEAD sitemap_index.xml',
        'GET|HEAD storage/{path}',
        'GET|HEAD up',
        'PATCH events/{event}',
        'PATCH members/{user}',
        'POST admin/logout',
        'POST api/agent-events',
        'POST events',
        'POST events/{event}/cancel',
        'POST events/{event}/publish',
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
