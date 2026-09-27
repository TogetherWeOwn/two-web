<?php

// Production is one Linux VM: nginx terminates TLS and hands PHP-FPM a plain
// http request over a local socket nothing else can reach (README, "How this
// is put together"). Trusting every proxy is safe only under that topology:
// anyone who could reach PHP-FPM directly could spoof X-Forwarded-Proto/Host
// and the app would believe it — https page links, and the Discord OAuth
// redirect_uri match, would all be attacker-controlled.
//
// If the topology ever changes (separate proxy host, container platform, CDN
// in front), name the proxy address(es) in bootstrap/app.php instead of '*'
// AND update this test. Any edit to the trust line without updating this test
// fails the build on purpose.

it('pins the trusted-proxy single-VM assumption', function () {
    $source = file_get_contents(base_path('bootstrap/app.php'));

    // The exact trust line. Widening, narrowing, or removing it must be a
    // conscious change reviewed alongside this test — never a silent edit.
    expect($source)->toContain("trustProxies(at: '*')");

    // The line must still carry its local-socket justification, so a future
    // editor sees the assumption at the point of change.
    expect($source)->toContain('local socket');

    // README is the human-readable record of the topology; keep it in sync.
    $readme = file_get_contents(base_path('README.md'));
    expect($readme)->toContain('trusts any proxy');
});
