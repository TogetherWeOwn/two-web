<?php

use App\Support\SafeRedirect;

// TOG-9254: the `?next=` return-to-page guard. Join and login may send a
// member back to the page they came from after Discord, and that page arrives
// as a query value an attacker can write — so only a relative path may pass,
// never a URL. Anything else must mean the old landing, never someone else's
// site.
//
// Pure string logic, no database, so these live in Unit: this box cannot
// reach Postgres, and the guard must not need it anyway.

it('passes a relative path through untouched', function () {
    expect(SafeRedirect::safe('/e/sunday-squad-01'))->toBe('/e/sunday-squad-01')
        ->and(SafeRedirect::safe('/events'))->toBe('/events')
        ->and(SafeRedirect::safe('/'))->toBe('/')
        ->and(SafeRedirect::safe('/e/abc123?tab=rsvp'))->toBe('/e/abc123?tab=rsvp');
});

it('rejects absolute URLs, protocol-relative hosts, and the rest of the hostile set', function () {
    // https://evil.test is the acceptance case: a full URL must never pass.
    // `//evil.test/x` looks relative but browsers read it as a host.
    // `/\evil.test` reads as a host in some browsers too, so the backslash
    // is rejected outright. `javascript:` is a scheme without slashes.
    // A newline smuggles a second header/line into a redirect target.
    foreach ([
        'https://evil.test',
        'http://evil.test/e/x',
        '//evil.test/x',
        '/\\evil.test/x',
        'javascript:alert(1)',
        "/e/x\nSet-Cookie: evil=1",
        '/e/x with spaces',
        'e/relative-without-slash',
        '',
    ] as $hostile) {
        expect(SafeRedirect::safe($hostile))->toBeNull("'{$hostile}' must not pass");
    }
});

it('rejects non-strings instead of casting them', function () {
    // `?next[]=x` arrives as an array. Casting it would warn and pass junk;
    // the guard says no and the callback keeps the old landing.
    expect(SafeRedirect::safe(['https://evil.test']))->toBeNull()
        ->and(SafeRedirect::safe(null))->toBeNull()
        ->and(SafeRedirect::safe(42))->toBeNull();
});
