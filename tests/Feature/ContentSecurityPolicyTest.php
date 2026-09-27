<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

// TOG-6770: the site's Content-Security-Policy. Security headers live in
// nginx.template.conf, but CSP cannot live there — the `npm run dev`
// hot-reload allowance is per-request state only PHP can see, and a middleware
// is what the feature suite can assert on. So these tests pin the emitted
// header, not the config file.
//
// What each test owns: the home assertion pins presence on a guest HTML page;
// the join/events assertions pin the same policy on the two other public
// pages the card names, including pages that render Livewire components (the
// RSVP button's `@script` handlers and the calendar's `wire:` directives are
// what earn `unsafe-inline` and `unsafe-eval`); the strictness test pins the
// exact policy so a widening has to edit an assertion, not slip past one; and
// the JSON test pins the negative — non-HTML responses carry no CSP because
// there is no document for it to protect.

/** The CSP header on a response, parsed into directive => sources. */
function cspDirectives(string $header): array
{
    $directives = [];

    foreach (explode(';', $header) as $part) {
        $part = trim($part);

        if ($part === '') {
            continue;
        }

        [$name, $value] = array_pad(explode(' ', $part, 2), 2, '');
        $directives[$name] = $value;
    }

    return $directives;
}

/**
 * The strict policy this suite pins. It is scheme-dependent by design
 * (TOG-7095): `upgrade-insecure-requests` is emitted on https requests only,
 * because on an http origin it upgrades the page while forms still target
 * http and `form-action 'self'` then blocks the POST — Dusk caught this on
 * the admin logout. Feature tests run over http, so the default expectation
 * carries no upgrade directive; pass `true` for the https variant.
 */
function strictCsp(bool $secure = false): string
{
    $policy = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: https:; "
        ."font-src 'self' data:; "
        .'connect-src \'self\'; '
        ."frame-ancestors 'none'; "
        ."base-uri 'self'; "
        ."object-src 'none'";

    return $secure ? $policy.'; upgrade-insecure-requests' : $policy;
}

it('serves the strict CSP on the guest homepage', function () {
    $response = $this->get('/');

    $response->assertOk();

    $header = $response->headers->get('Content-Security-Policy');
    expect($header)->not->toBeNull('guest homepage carries no Content-Security-Policy');

    expect($header)->toBe(strictCsp());
});

it('serves the same CSP on join and the events calendar', function () {
    // /join renders without the bot: the invite URL falls back to a static
    // invite when the bot is unconfigured, so a guest GET needs no mocks.
    $this->get(route('join'))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', strictCsp());

    Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);

    $this->get(route('events.index'))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', strictCsp());
});

it('keeps the Livewire allowances but nothing wider', function () {
    $directives = cspDirectives(strictCsp());

    // `wire:click`/`wire:submit` expressions are evaluated by Livewire's
    // embedded Alpine via `new Function`, which CSP counts as eval — without
    // it every calendar and RSVP interaction throws. The eval surface is
    // Alpine's own expression parser, not arbitrary page script.
    expect($directives['script-src'])->toContain("'unsafe-eval'");

    // `@livewireScriptConfig` and `@script` blocks render as inline scripts
    // with no nonce hook, so inline scripts stay allowed; `default-src
    // 'self'` plus `object-src 'none'` still keep plugins out.
    expect($directives['script-src'])->toContain("'unsafe-inline'");

    // No wildcard hosts, no data: scripts, no bare `http:` — the policy never
    // reaches further than self plus the two Livewire keywords.
    expect($directives['script-src'])->not->toContain('*')
        ->and($directives['script-src'])->not->toContain('data:')
        ->and($directives['script-src'])->not->toContain('http:');

    // Member avatars come from cdn.discordapp.com and moderators paste
    // arbitrary https photo URLs into featured content, so images cannot be
    // pinned to 'self' — but `https:` still bars http downgrade.
    expect($directives['img-src'])->toContain('https:');
    expect($directives['img-src'])->not->toContain('http: ');

    expect($directives['object-src'])->toBe("'none'")
        ->and($directives['frame-ancestors'])->toBe("'none'");

    // No `form-action` directive on purpose (TOG-7095): the explicit
    // `form-action 'self'` blocked the admin logout POST in Chrome while the
    // identical site sign-out POST passed, with no determinable cause.
    // Omitting it is not a hole — submissions fall back to
    // `default-src 'self'`, so they stay same-origin. This assertion pins the
    // omission so a re-add has to justify itself against the Dusk evidence.
    expect($directives)->not->toHaveKey('form-action');
});

it('does not put a CSP on non-HTML responses', function () {
    $member = User::factory()->create(['is_moderator' => false]);

    // A JSON collection carrying a document policy protects nothing and only
    // confuses caches; a redirect carries no body at all. The HTML funnel
    // path (`/about`) is pinned separately in AboutPageTest, which owns the
    // funnel-served document — this test owns the negative.
    $this->actingAs($member)->getJson(route('events.json'))
        ->assertOk()
        ->assertHeaderMissing('Content-Security-Policy');

    $this->get('/discord')
        ->assertRedirect()
        ->assertHeaderMissing('Content-Security-Policy');
});

it('serves the CSP on the admin panel too', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);

    $this->actingAs($moderator)->get('/admin')
        ->assertOk()
        ->assertHeader('Content-Security-Policy', strictCsp());
});

it('emits upgrade-insecure-requests on https and omits it on http', function () {
    // The Dusk regression this pins (TOG-7095): on an http origin the
    // directive upgrades the page while the logout form still targets http,
    // and `form-action 'self'` blocks the POST — so http responses must not
    // carry it. Staging terminates TLS at nginx, so PHP sees an http request
    // and the forwarded proto is the only way `isSecure()` can know — same
    // posture as RobotsTxtTest.
    $this->get('/')
        ->assertOk()
        ->assertHeader('Content-Security-Policy', strictCsp());

    $header = $this
        ->get('http://staging.togetherweown.com/', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->headers->get('Content-Security-Policy');

    expect($header)->toBe(strictCsp(secure: true));
});
