<?php

use Symfony\Component\HttpFoundation\Cookie;

// The CISO session-handling bar (TOG-5469), violations V1 and V3: the session
// cookie flags must be explicit in the shipped environment and asserted on a
// real response, so a regression goes red here instead of leaking a cookie.
//
// Two layers. The file test pins the source: .env.example carries the explicit
// values CI copies into .env, so the flags below are the deploy default, not a
// framework fallback. The response test pins the behaviour: whatever the app
// serves on a session-bearing page carries Secure + HttpOnly + SameSite=Lax on
// a host-scoped cookie. Either half can fail on its own — a pinned file with a
// broken emission path, or correct emission from an unpinned default — so both
// stay.

it('pins the session cookie flags explicitly in .env.example', function () {
    $example = file_get_contents(base_path('.env.example'));
    expect($example)->not->toBeFalse();

    // One assertion, not a loop with a message: Pest's toContain is variadic
    // over needles and takes no message argument, so a second argument would
    // be asserted as file content too. Either half of this file can fail on
    // its own, so both stay — see the header comment.
    expect($example)->toContain(
        'SESSION_SECURE_COOKIE=true',
        'SESSION_SAME_SITE=lax',
        'SESSION_DOMAIN=null',
    );
});

/** The session cookie on a session-bearing response, with its flags attached. */
function sessionResponseCookie(): Cookie
{
    // withSession forces the session to carry data, so StartSession attaches
    // the cookie the way a signed-in page does; a bare GET may legitimately
    // send no Set-Cookie at all.
    $response = test()->withSession(['bar_probe' => 'present'])->get('/');

    $response->assertOk();

    $cookies = collect($response->headers->getCookies())
        ->keyBy(fn (Cookie $cookie): string => $cookie->getName());

    expect($cookies->has(config('session.cookie')))
        ->toBeTrue('no session cookie on a session-bearing response');

    return $cookies->get(config('session.cookie'));
}

it('serves the session cookie Secure, HttpOnly, SameSite=Lax and host-scoped', function () {
    $cookie = sessionResponseCookie();

    expect($cookie->isSecure())->toBeTrue('session cookie must be Secure');
    expect($cookie->isHttpOnly())->toBeTrue('session cookie must be HttpOnly');
    expect(strtolower((string) $cookie->getSameSite()))->toBe('lax', 'session cookie must be SameSite=Lax');
    expect($cookie->getDomain())->toBeIn([null, ''], 'session cookie must be host-scoped, never a parent domain');
    expect($cookie->getPath())->toBe('/');
});

it('keeps the Secure flag behind the proxy, the way staging serves', function () {
    // Staging terminates TLS at the edge and hands PHP-FPM plain http; the
    // Secure flag comes from pinned config, not from the request scheme, so it
    // must survive exactly that shape. The scheme half of the same story —
    // absolute URLs generated as https behind the proxy — is pinned by
    // DiscordRedirectUriTest, which owns X-Forwarded-Proto.
    $response = test()->withSession(['bar_probe' => 'present'])
        ->get('http://staging.togetherweown.com/', ['X-Forwarded-Proto' => 'https']);

    $response->assertOk();

    $cookie = collect($response->headers->getCookies())
        ->firstWhere(fn (Cookie $c): bool => $c->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull('no session cookie on a proxied session-bearing response');
    expect($cookie->isSecure())->toBeTrue('session cookie must stay Secure behind the TLS-terminating proxy');
});
