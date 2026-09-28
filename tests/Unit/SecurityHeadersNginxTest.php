<?php

// TOG-7328: the nginx half of the security-header parity. The same four
// headers live statically in nginx.template.conf (the edge copy) and in
// App\Http\Middleware\AddSecurityHeaders (the tested copy, pinned by
// tests/Feature/SecurityHeadersTest.php). This test reads the template from
// disk and pins byte-identical values, so drift between the two fails the
// build instead of silently halving the protection.
//
// Offline like the other config pins (ImmutableAssetCacheTest,
// TrustedProxiesPinnedTest): it needs no nginx binary, no build and no
// database — which is why it lives in Unit, not Feature.

use App\Http\Middleware\AddSecurityHeaders;

it('keeps the nginx template carrying the identical security headers', function () {
    $contents = file_get_contents(base_path('nginx.template.conf'));

    expect($contents)->not->toBeFalse('nginx.template.conf is missing or unreadable');

    foreach (AddSecurityHeaders::HEADERS as $header => $value) {
        expect(str_contains($contents, 'add_header '.$header.' "'.$value.'"'))
            ->toBeTrue("nginx.template.conf no longer carries {$header}: \"{$value}\" — the edge and app copies drifted.");
    }
});
