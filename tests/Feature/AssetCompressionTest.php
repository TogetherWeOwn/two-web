<?php

/*
 * What goes over the wire, in bytes.
 *
 * The LCP budget on /events is bandwidth-bound, not paint-bound. ci/lighthouserc.cjs
 * uses `throttlingMethod: 'simulate'` at ~184 KB/s, so a resource that blocks nothing
 * still pushes LCP out simply by occupying the connection while the largest element
 * waits for the font it renders in. Measured on TOG-53 (median of 3, CI runner):
 *
 *              LCP      FCP
 *     /        1805ms   1256ms     gap 549ms  ~= the 88 KB font at 184 KB/s (489ms)
 *     /events  2687ms   1261ms     gap 1426ms ~= font + 162 KB Livewire     (1390ms)
 *
 * FCP is the same on both pages to within 5ms, which is what proves the paint is not
 * blocked and that a loading hint cannot help. `defer` and `fetchpriority="low"` were
 * both tried and both measured as noise on LCP; only removing bytes moves it.
 *
 * Livewire serves its runtime from a PHP route (FrontendAssets::returnJavaScriptAsFile),
 * which answers with no Content-Encoding even when the client offers gzip — verified
 * against `artisan serve` in a production-shaped app: 166,146 bytes on the wire while
 * the same file gzips to ~55 KB. That is the single largest resource on the page and
 * two thirds of it is compressible air.
 *
 * These tests assert the bytes, not the mechanism, so a future change that gets the
 * same win a different way (a published asset, a CDN, nginx gzip_static) keeps them
 * green. What they will not let happen is the runtime silently going back to being
 * shipped uncompressed.
 */

use App\Enums\EventStatus;
use App\Models\Event;

/**
 * The URL Livewire is actually serving its runtime from, read off the page rather
 * than written down here.
 *
 * It is `livewire.min.js` normally and `livewire.js` when APP_DEBUG is on, and the
 * route carries no name to ask for instead. Hardcoding the minified path made both
 * tests below 404 in CI — which copies .env.example, where APP_DEBUG=true — while
 * passing locally against a production-shaped .env. Taking it from the rendered page
 * also means these tests assert the asset the page really requests, so a Livewire
 * release that moves it fails here as a 404 on a real URL rather than passing
 * vacuously against one nobody loads.
 */
function livewireRuntimeUrl(): string
{
    $html = (string) test()->get(route('events.index'))->assertOk()->getContent();

    preg_match(
        '/(?:<script[^>]*\bsrc=|livewire\.src\s*=)\s*([\'\"])([^\'\"]*livewire(?:\.min)?\.js[^\'\"]*)\1/',
        $html,
        $matches,
    );

    expect($matches)->not->toBeEmpty('the Livewire runtime URL is not on the events page at all');

    // `?id=...` cache-buster and an absolute host both come along on the URL;
    // @js also escapes slashes when the runtime is appended after window.load.
    // The test client wants a plain path.
    $url = str_replace('\/', '/', html_entity_decode($matches[2]));

    return (string) parse_url($url, PHP_URL_PATH);
}

it('serves the Livewire runtime compressed when the client offers gzip', function () {
    $url = livewireRuntimeUrl();

    $response = $this->withHeaders(['Accept-Encoding' => 'gzip'])->get($url);

    $response->assertOk();

    expect($response->headers->get('Content-Encoding'))
        ->toBe('gzip', 'the Livewire runtime is being shipped uncompressed');

    // Assert the body actually got smaller rather than just carrying the header —
    // a lying header would still put the full runtime on the connection while
    // looking fixed.
    //
    // Measured against the same asset served uncompressed, not against a byte
    // count written down here. Livewire serves the minified runtime (~162 KB)
    // normally and the unminified one (~248 KB) when APP_DEBUG is on, so a fixed
    // ceiling calibrated on one is either wrong or vacuous on the other — that is
    // exactly how the first version of this test passed locally and failed in CI.
    // Both compress to roughly a third; half is a wide margin around that and
    // still far below anything an uncompressed body could reach.
    $bytes = strlen((string) $response->getContent());
    $original = strlen(uncompressedRuntime($url));

    expect($bytes)->toBeLessThan(
        (int) ($original / 2),
        "the runtime went over the wire at {$bytes} bytes against {$original} uncompressed — that is not a compressed file",
    );
});

/**
 * The runtime as served to a client that cannot accept gzip — the middleware's own
 * pass-through path, which is the right baseline to measure the compressed body
 * against.
 *
 * Untouched, this is still Livewire's BinaryFileResponse: the bytes are on disk
 * rather than in memory, so `getContent()` is empty and they have to be read the
 * way the framework would send them.
 */
function uncompressedRuntime(string $url): string
{
    $response = test()->withHeaders(['Accept-Encoding' => 'identity'])->get($url);

    $response->assertOk();

    expect($response->headers->get('Content-Encoding'))->toBeNull();

    $body = $response->getContent();

    if ($body === false || $body === '') {
        $body = (string) file_get_contents($response->baseResponse->getFile()->getPathname());
    }

    return (string) $body;
}

it('leaves the response byte-identical when the client cannot accept gzip', function () {
    // A client that does not send Accept-Encoding must still get working JavaScript.
    // Compressing unconditionally would serve gzip bytes as `application/javascript`
    // to anything that did not ask, which is a broken page rather than a slow one.
    $body = uncompressedRuntime(livewireRuntimeUrl());

    // Real JavaScript, not a gzip member. Cheapest possible check that we did not
    // hand an unsuspecting client a compressed body: gzip starts with 0x1f 0x8b.
    expect(substr($body, 0, 2))->not->toBe("\x1f\x8b");
});

it('keeps the events page bundle free of an HTTP client nothing calls', function () {
    Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);

    $this->get(route('events.index'))->assertOk();

    // resources/js/app.js imported axios and hung it on `window.axios`; nothing in
    // the application ever read it. Unused, it was 48 KB of the 49 KB bundle on every
    // page — ~267ms of transfer on the budget profile, competing with the font the
    // largest text is waiting on. Livewire ships its own fetch layer.
    //
    // Asserted against the source rather than the built bundle so it fails in the
    // pest job, which does not run `npm run build`.
    // Strip comments before matching: this file explains at length why axios is
    // gone, and a naive substring match would fail on the explanation itself.
    $entrypoint = (string) file_get_contents(resource_path('js/app.js'));
    $code = (string) preg_replace(['#/\*.*?\*/#s', '#//.*$#m'], '', $entrypoint);

    expect($code)->not->toContain('axios');
    expect($code)->not->toContain('import');
    expect(file_exists(resource_path('js/bootstrap.js')))->toBeFalse();

    $package = json_decode((string) file_get_contents(base_path('package.json')), true);

    expect($package['dependencies'] ?? [])->not->toHaveKey('axios');
    expect($package['devDependencies'] ?? [])->not->toHaveKey('axios');
});
