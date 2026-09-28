<?php

// TOG-7328: the response-header pass beyond CSP. Content-Security-Policy is
// TOG-6770 / ContentSecurityPolicyTest (excluded here); these tests pin the
// other four headers — X-Content-Type-Options, Referrer-Policy,
// X-Frame-Options, Permissions-Policy (HSTS stays nginx-only: it is
// meaningless over plaintext and dangerous from `php artisan serve`).
//
// The headers live in two places by design: statically in
// nginx.template.conf (the edge copy) and in App\Http\Middleware\
// AddSecurityHeaders (the tested copy). Nothing in CI can see the nginx
// config, so the middleware is what the suite asserts on — and the nginx
// half of these tests reads the template from disk and pins byte-identical
// values, so drift between the two fails the build instead of silently
// halving the protection. Offline like the other config pins
// (ImmutableAssetCacheTest, TrustedProxiesPinnedTest): no nginx binary, no
// build, no database.
//
// What each test owns: presence on a guest HTML page; presence on the funnel
// redirect and the join page (the two most-shared URLs sit outside the `web`
// group by design, and "outside the group" must not mean "unprotected");
// presence on JSON; and the DENY pin. The nginx-template parity half lives
// in tests/Unit/SecurityHeadersNginxTest.php — an offline pin needs no
// database, and the Feature suite's RefreshDatabase would force one.

use App\Http\Middleware\AddSecurityHeaders;
use App\Models\User;

it('sets the four security headers on a guest HTML page', function () {
    $response = $this->get('/');

    $response->assertOk();

    foreach (AddSecurityHeaders::HEADERS as $header => $value) {
        $response->assertHeader($header, $value);
    }
});

it('sets them on the funnel redirect and the join page too', function () {
    // `/discord` answers a 302 with a deliberately empty middleware stack
    // (routes/funnel.php) so it survives a database outage; the headers must
    // ride along anyway — the middleware is global, not on `web`.
    $this->get('/discord')
        ->assertRedirect()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

    $this->get(route('join'))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('sets them on a JSON response as well', function () {
    // Unlike CSP, which protects documents, sniffing and framing guards apply
    // to every response — a JSON body mis-sniffed as script is the attack.
    $member = User::factory()->create(['is_moderator' => false]);

    $this->actingAs($member)->getJson(route('events.json'))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('pins X-Frame-Options to DENY, not SAMEORIGIN', function () {
    // Nothing frames this site, and DENY is what the CISO session-handling
    // bar (TOG-5469) pins. A SAMEORIGIN "relaxation" must edit this
    // assertion, not slip past one.
    expect(AddSecurityHeaders::HEADERS['X-Frame-Options'])->toBe('DENY');

    $this->get('/')
        ->assertHeader('X-Frame-Options', 'DENY');
});
