<?php

/*
 * What is allowed to block the first paint.
 *
 * This exists because of a measured breach, not a theory. TOG-53 added /events,
 * which is the first Livewire page in the funnel, and Livewire injects its own
 * 162 KB runtime as a plain <script src> with no defer. On the CI budget profile
 * — a mid-range phone, 4x CPU slowdown, simulated Slow 4G — that is ~900ms of
 * transfer sitting in the critical path, and Lighthouse measured the result:
 *
 *     largest-contentful-paint  2616ms  against a 2000ms budget
 *     first-contentful-paint    2166ms  against an 1800ms warning
 *
 * The budget is the CEO's and is not negotiable in a feature PR (ci/lighthouserc.cjs),
 * so the script has to leave the critical path instead.
 *
 * A parser-blocking script is asserted here rather than only in the budgets job
 * because the budgets job can only say "LCP breached". It cannot say why, it costs
 * three Lighthouse runs per URL to find out, and it does not run at all until a PR
 * is opened. This is the same fact, named, in half a second.
 */

use App\Enums\EventStatus;
use App\Models\Event;

it('never lets the Livewire runtime block the first paint', function () {
    Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);

    $html = $this->get(route('events.index'))->assertOk()->getContent();

    expect($html)->toBeString();

    // The tag Livewire injects, whatever else is on it. `livewire.min.js` in
    // production and `livewire.js` when debug is on, so the pattern takes either —
    // matching only the minified name would make this test silently vacuous in the
    // very environment it runs in.
    preg_match('/<script[^>]*livewire(\.min)?\.js[^>]*>/', (string) $html, $matches);

    expect($matches)->not->toBeEmpty('the Livewire runtime is not on the events page at all');
    // `defer` and not `async`: Livewire's runtime has to run after the document is
    // parsed, because it binds to the components already in it. `async` would let
    // it execute mid-parse against a half-built DOM.
    expect($matches[0])->toContain('defer');

    // `defer` stops the script blocking the parser, but it does NOT stop it
    // competing for bandwidth with the resources the paint is waiting on. That
    // distinction was measured, not assumed: adding defer alone cleared the FCP
    // warning and left LCP at 2684ms against a 2000ms budget, because on Slow 4G
    // the four assets this page needs are already a ~1.9s transfer floor and the
    // runtime is 900ms of it.
    //
    // fetchpriority="low" tells the browser to fund the paint first and the
    // interactivity after. It is safe precisely because the script is deferred —
    // nothing before DOMContentLoaded is waiting on it.
    expect($matches[0])->toContain('fetchpriority="low"');
});

it('serves the events page without a render-blocking script in the head', function () {
    // The head is where a blocking script costs the most — nothing paints until it
    // has been fetched and run. Stylesheets are exempt: they block on purpose, and
    // the alternative is a flash of unstyled content that spends the CLS budget.
    $html = (string) $this->get(route('events.index'))->assertOk()->getContent();

    $head = substr($html, 0, strpos($html, '</head>') ?: 0);

    preg_match_all('/<script[^>]*src=[^>]*>/', $head, $matches);

    foreach ($matches[0] ?? [] as $tag) {
        expect($tag)->toMatch('/\b(defer|async|type="module")\b/');
    }
});
