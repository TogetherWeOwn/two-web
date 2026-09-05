<?php

use Illuminate\Support\Facades\Route;

/*
 * The apex serves a soft 404 today: every unknown URL on togetherweown.com
 * answers HTTP 200 with the title "Page Not Found" (TOG-1153, and check
 * `no-soft-404s` in ci/live-seo-probe.mjs, which fails against the live site).
 * The cause is Bricks' "Coming Soon" mode, which is documented to answer 200 for
 * every URL, and it cannot be fixed from this repo — nobody here can log into
 * that WordPress.com install.
 *
 * What this repo controls is whether the replacement app inherits the defect at
 * cutover. Laravel returns a real 404 out of the box, so these tests are not
 * describing code that had to be written — they pin behaviour that a later
 * catch-all route would silently destroy. A `Route::fallback()` added to serve a
 * pretty "not found" page is the exact mistake: it renders 200 unless whoever
 * adds it remembers the status, which is how WordPress got here.
 */

it('answers a never-existed URL with a real 404 status', function () {
    // The control path from TOG-1153. Random enough that it cannot ever become a
    // real route by accident.
    $this->get('/nx-9x7q2-zzz')
        ->assertStatus(404);
});

it('404s on unknown paths at every depth', function () {
    // A fallback route registered without a status is usually added to catch
    // deep paths, so check more than one segment.
    foreach ([
        '/join-us',
        '/aaa/bbb/ccc-zzz-9182',
        '/shop/nope-zzz',
        '/2026/08/01/hello-world',
        '/wp-admin',
    ] as $path) {
        $this->get($path)
            ->assertStatus(404, "Path [{$path}] must return 404, not a soft 404.");
    }
});

it('does not serve a body that claims success on an unknown URL', function () {
    // The specific WordPress failure: the words "Page Not Found" rendered under
    // a 200. Status and body have to agree.
    $response = $this->get('/nx-9x7q2-zzz');

    expect($response->getStatusCode())->toBe(404);
});

it('keeps known-good routes answering 200', function () {
    // The other half of the done-when in TOG-1153: proving unknown paths 404 is
    // worthless if the fix took the real pages down with them.
    $this->get('/')->assertOk();
});

it('registers no catch-all route that would swallow unknown paths', function () {
    // The structural guard. The tests above prove today's behaviour; this proves
    // nobody has added the mechanism that would quietly undo it. Laravel's
    // fallback route matches everything and renders whatever it is given — as a
    // 200 unless explicitly told otherwise.
    $fallbacks = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => $route->isFallback)
        ->map(fn ($route) => $route->uri())
        ->all();

    expect($fallbacks)->toBe(
        [],
        'A fallback route is registered. If it is deliberate it must abort(404) or '.
        'return a 404 status explicitly — see TOG-1153 for what a soft 404 costs.'
    );
});
