<?php

// TOG-7328: the nginx half of the security-header parity. Four of the
// headers live statically in nginx.template.conf (the edge copy) and in
// App\Http\Middleware\AddSecurityHeaders (the tested copy, pinned by
// tests/Feature/SecurityHeadersTest.php). Two more — Strict-Transport-Security
// and X-Robots-Tag — live ONLY in the template (HSTS is meaningless over
// plaintext and dangerous from `php artisan serve`; the robots tag is
// environment-conditional), so this file is their only Pest pin. Together the
// two tests below assert the template ships every required security header,
// so dropping any one of them fails the build instead of silently halving
// the protection.
//
// Offline like the other config pins (ImmutableAssetCacheTest,
// TrustedProxiesPinnedTest): it needs no nginx binary, no build and no
// database — which is why it lives in Unit, not Feature.

use Illuminate\Support\Facades\File;

require_once __DIR__.'/../Support/NginxSecurityHeaders.php';

it('keeps the nginx template carrying the identical security headers', function () {
    $contents = File::get(base_path('nginx.template.conf'));

    assertNginxAppSecurityHeaders($contents);
});

it('keeps the nginx-only headers on the template too', function () {
    // TOG-8729: Strict-Transport-Security and X-Robots-Tag exist only at the
    // edge — AddSecurityHeaders deliberately does not emit them (HSTS is
    // meaningless over plaintext and dangerous from `php artisan serve`; the
    // robots tag is environment-conditional) — so the parity loop above
    // cannot see them. This pins both with their exact values, so dropping
    // either fails the build instead of shipping quiet.
    $contents = File::get(base_path('nginx.template.conf'));

    // `always` is load-bearing: without it nginx skips error responses, and
    // an HSTS policy that stops at the first 404 is the defect this flags.
    expect(countActiveNginxDirective($contents, 'add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;'))
        ->toBe(1, 'nginx.template.conf no longer emits HSTS with always — error responses would leave the policy.');

    // The staging-only noindex: the map must send staging to noindex, and
    // the emission must read from the map with `always`. A mapping without
    // the emission (or vice versa) is a silent no-op, so both halves pin.
    expect(countActiveNginxDirective($contents, 'staging.togetherweown.com "noindex, nofollow";'))
        ->toBe(1, 'nginx.template.conf no longer maps staging to noindex, nofollow.');
    expect(countActiveNginxDirective($contents, 'add_header X-Robots-Tag $robots_tag always;'))->toBe(1);
});
