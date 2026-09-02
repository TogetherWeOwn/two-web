<?php

use App\Http\Middleware\SecurityHeaders;

/*
 * docs/dns.md specifies a set of security headers and requires that staging is
 * never indexable. Both were specified as nginx configuration on an origin that
 * does not exist yet, which meant nothing in CI could tell whether they were
 * present. These tests are that check.
 *
 * The noindex tests are the load-bearing ones. "Staging must never appear in a
 * search result" is the requirement with a consequence we cannot undo ourselves
 * — removal from an index is a request to a third party — so the default is
 * asserted directly rather than only the configured behaviour.
 */

it('sets the security headers that cost nothing', function () {
    $response = $this->get('/');

    foreach (SecurityHeaders::STATIC_HEADERS as $header => $value) {
        expect($response->headers->get($header))->toBe($value);
    }
});

it('defaults to not indexable, so a new environment is private until it opts in', function () {
    // Asserted against the shipped config file rather than a value set in the
    // test: the default IS the protection. An environment that has to remember
    // to opt out of indexing eventually forgets, and staging in Google's cache
    // is not something we can reverse on our own.
    expect(config('security.indexable'))->toBeFalse();

    $this->get('/')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('drops the noindex header when a deployment is explicitly marked indexable', function () {
    config()->set('security.indexable', true);

    expect($this->get('/')->headers->has('X-Robots-Tag'))->toBeFalse();
});

it('does not send HSTS over plaintext', function () {
    // The header is meaningless over http, and sending it from a local
    // `php artisan serve` would pin the developer's whole machine to HTTPS for
    // a year — including every other project on localhost.
    expect($this->get('http://localhost/')->headers->has('Strict-Transport-Security'))
        ->toBeFalse();
});

it('sends HSTS over https', function () {
    $this->get('https://localhost/')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('leaves includeSubDomains off by default', function () {
    // docs/dns.md: the apex is served by WordPress.com today, and
    // includeSubDomains from a host we do not control is a year-long commitment
    // covering names we do not own. It goes on in our own environment after the
    // cutover, deliberately, not by inheriting a default.
    expect(config('security.hsts.include_subdomains'))->toBeFalse();
});

it('sends includeSubDomains once an environment turns it on', function () {
    config()->set('security.hsts.include_subdomains', true);

    $this->get('https://localhost/')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('never sends preload', function () {
    // Preload is a submission to a list compiled into browsers. It is slow and
    // painful to reverse and it commits every subdomain forever. If it is ever
    // wanted it is a deliberate decision a month after cutover, not a default
    // somebody appended to the max-age string.
    config()->set('security.hsts.include_subdomains', true);

    expect($this->get('https://localhost/')->headers->get('Strict-Transport-Security'))
        ->not->toContain('preload');
});

it('protects the funnel routes too, which sit outside the web middleware group', function () {
    // routes/funnel.php runs with an empty middleware stack so `/discord`
    // survives a database outage (TOG-77). These are also the two most-shared
    // URLs we own, so "outside the web group" must not mean "unprotected".
    foreach (['/discord', '/join'] as $path) {
        $response = $this->get($path);

        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
        expect($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow');
    }
});
