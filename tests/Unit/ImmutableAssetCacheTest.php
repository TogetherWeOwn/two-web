<?php

// Immutable long-lived caching for versioned static assets (TOG-6785).
//
// nginx serves `public/` directly in production, so PHP never runs for the
// Vite bundle or the fonts — which means no middleware or controller can set
// their cache headers. The only place this can live is nginx.template.conf.
//
// The deal is safe only because the URLs name exact bytes. `/build/assets/*`
// filenames carry a Vite content hash: a rebuild mints new URLs and the HTML
// points at them, so a year-long `immutable` can never serve stale code.
// `/fonts/*` are pinned binaries that likewise only change under a new
// filename. Anything without a content hash — HTML, and the `/discord` join
// link whose whole point is being retargetable in a minute — must stay
// revalidatable, so these tests also pin the absence of Cache-Control on
// `location /` and the PHP block. `/discord`'s own `no-store` is pinned
// separately by DiscordFunnelTest ('does not let anything cache the join
// link'); what is pinned here is that this change does not touch it.
//
// Offline like the other config pins (TrustedProxiesPinnedTest,
// ViteEntrypointsTest): it reads the template from disk, needs no nginx
// binary, no build and no database.

/** The raw template under test. */
function immutableCacheTemplate(): string
{
    $contents = file_get_contents(base_path('nginx.template.conf'));

    expect($contents)->not->toBeFalse('nginx.template.conf is missing or unreadable');

    return (string) $contents;
}

/**
 * The body of `location <name> { ... }`, brace-balanced.
 *
 * A regex cannot match nested braces; this walks the block instead. Returns
 * null when the location does not exist.
 */
function nginxLocationBlock(string $config, string $name): ?string
{
    $open = strpos($config, 'location '.$name.' {');

    if ($open === false) {
        return null;
    }

    $depth = 0;
    $length = strlen($config);

    for ($i = $open; $i < $length; $i++) {
        if ($config[$i] === '{') {
            $depth++;
        } elseif ($config[$i] === '}') {
            $depth--;

            if ($depth === 0) {
                return substr($config, $open, $i - $open + 1);
            }
        }
    }

    return null;
}

/** Every `add_header Cache-Control ...;` line in the template. */
function cacheControlDirectives(string $config): array
{
    preg_match_all('/^[[:space:]]*add_header[[:space:]]+Cache-Control[[:space:]]+(.*?);[[:space:]]*$/m', $config, $matches);

    return $matches[0];
}

it('caches versioned build assets immutably for a year', function () {
    $block = nginxLocationBlock(immutableCacheTemplate(), '/build/assets/');

    expect($block)->not->toBeNull('nginx.template.conf has no location /build/assets/ block');

    // The exact header. `immutable` is what lets a repeat visit skip the
    // revalidation request entirely; without it a year-long max-age still
    // costs a 304 round trip per deploy-pinned URL.
    expect($block)->toContain('add_header Cache-Control "public, max-age=31536000, immutable";');

    // No `always`: without it nginx sends the header on success responses
    // (200/304) but not on errors, so a 404 for a mistyped hash is never
    // cached for a year.
    expect($block)->not->toContain('always');
});

it('caches pinned fonts immutably for a year', function () {
    $block = nginxLocationBlock(immutableCacheTemplate(), '/fonts/');

    expect($block)->not->toBeNull('nginx.template.conf has no location /fonts/ block');

    expect($block)->toContain('add_header Cache-Control "public, max-age=31536000, immutable";');
    expect($block)->not->toContain('always');
});

it('sets Cache-Control on exactly those two locations and nowhere else', function () {
    $config = immutableCacheTemplate();

    // Two versioned-asset locations, each emitting the header once. Anything
    // more is scope creep onto a URL that might not name exact bytes.
    expect(cacheControlDirectives($config))->toHaveCount(2);

    // HTML and every dynamic response flow through `location /` and the PHP
    // block. A Cache-Control in either would cache pages — or the join
    // redirect — at the edge, which is the defect this slice exists to not
    // ship. Both blocks must keep carrying none.
    foreach (['/', '~ \\.php$'] as $name) {
        $block = nginxLocationBlock($config, $name);

        expect($block)->not->toBeNull("nginx.template.conf has no location {$name} block");
        expect($block)->not->toContain('Cache-Control', "location {$name} must not set Cache-Control");
    }
});
