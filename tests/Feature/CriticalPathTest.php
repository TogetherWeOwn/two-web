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

    // The runtime must not be fetched by the parser. A `defer` attribute was not
    // enough on the slower staging host: Chrome still executed Livewire before
    // DOMContentLoaded, and that CPU work competed with the empty-state LCP.
    preg_match('/<script[^>]*src="[^"]*livewire(\.min)?\.js[^>]*>/', (string) $html, $matches);

    expect($matches)->toBeEmpty('the Livewire runtime is still on the parser-discovered critical path');

    // Keep the configuration inline and tiny, then append the runtime only after
    // all paint-critical resources have completed. `Livewire.start()` is explicit
    // because @livewireScriptConfig deliberately disables the runtime's automatic
    // DOMContentLoaded start.
    expect($html)->toContain('window.livewireScriptConfig');
    expect($html)->toContain("window.addEventListener('load'");
    expect($html)->toContain('livewire.onload = () => Livewire.start()');
    expect($html)->toContain('document.head.appendChild(livewire)');

    // The normal app shell must not pay for Livewire when it renders a non-Livewire
    // page. The delay is scoped to /events, not a global asset policy change.
    $home = (string) $this->get(route('home'))->assertOk()->getContent();
    expect($home)->not->toContain('window.livewireScriptConfig');
    expect($home)->not->toContain('document.head.appendChild(livewire)');

    // The server-rendered component and the versioned runtime URL remain present;
    // only parser discovery and startup timing change.
    expect($html)->toContain('data-testid="events-view-list"');
    expect($html)->toContain('data-testid="events-list"');
    expect($html)->toContain('wire:snapshot');
    expect($html)->toMatch('/livewire(\.min)?\.js\?id=/');
    expect(substr_count($html, 'Livewire.start()'))->toBe(1);
    expect(substr_count($html, 'window.livewireScriptConfig'))->toBe(1);
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
