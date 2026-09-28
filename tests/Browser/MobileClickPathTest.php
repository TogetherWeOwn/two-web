<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Laravel\Dusk\Browser;

/*
 * The 360px click-path: join → events → RSVP → profile in one mobile viewport.
 *
 * What this owns that no other test does. EventsRsvpTest owns each journey at
 * 360px, but always one page at a time and always with `resize()` — which sets
 * a window size, not a device. This test drives the full funnel a phone user
 * actually walks (landing → join → calendar → RSVP → profile) behind genuine
 * mobile emulation, and asserts the three things that break specifically at
 * 360px: horizontal overflow, sub-tap-size controls, and overlapping copy.
 * `assertDontOverflowHorizontally` reads `document.scrollWidth` against the
 * layout viewport, so it catches the fixed-width child that screenshots hide
 * (a cropped screenshot looks "fine" while the page scrolls sideways).
 *
 * The RSVP here is the plain local round-trip only: commit, re-render,
 * persistence across reload. The Discord sync boundary is EventsRsvpTest's
 * ground (dusk-stub receipt, write-back stamp) and is not re-proved here —
 * QUEUE_CONNECTION=sync on the Dusk server drains it inline instead.
 *
 * TOG-6769. Runs in the `dusk` CI job.
 */

/** The shared mobile-journey assertions, run once per page. */
function assertMobilePageSound(Browser $browser, string $page): void
{
    // No horizontal scrollbar at 360px: the CSS width the brief names.
    $browser->assertScript('return document.documentElement.scrollWidth <= window.innerWidth + 1;');

    // Every visible control meets the WCAG 2.5.8 target-size minimum: 24px
    // in both dimensions, with two deliberate exceptions. The skip link is
    // 1x1 until focused by design. An inline text link (an <a> with no
    // button sizing, e.g. the invite fallback) is exempt per WCAG 2.5.8's
    // inline exception — its line box, not its glyph box, is the target —
    // but it must still clear 24px of *height* so two adjacent text links
    // cannot stack into an untappable sandwich.
    //
    // NOTE: `$browser->script()` returns one entry per script passed, so
    // index [0] is the array the JS returned — not the array itself.
    $tooSmall = $browser->script(<<<'JS'
        return [...document.querySelectorAll('main a, main button')]
            .filter((el) => {
                const r = el.getBoundingClientRect();
                if (r.width === 0 && r.height === 0) return false;
                if (el.textContent.trim() === 'Skip to content') return false;
                const isTextLink = el.tagName === 'A'
                    && !/(min-h-11|inline-flex|inline-block|px-|py-|p-)/.test(el.className.baseVal ?? el.className ?? '');
                if (isTextLink) return r.height < 24;
                return r.width < 24 || r.height < 24;
            })
            .map((el) => `${el.tagName} "${el.textContent.trim().slice(0, 40)}"`);
    JS)[0];

    expect($tooSmall)->toBe([], "sub-tap-size controls on {$page}");

    // No two visible text blocks overlap: the flex-wrap failure mode at 360px.
    $overlaps = $browser->script(<<<'JS'
        const els = [...document.querySelectorAll('main h1, main h2, main p, main a, main button')]
            .filter((e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0; });
        const out = [];
        for (let i = 0; i < els.length; i++) {
            for (let j = i + 1; j < els.length; j++) {
                if (els[i].contains(els[j]) || els[j].contains(els[i])) continue;
                const a = els[i].getBoundingClientRect(), b = els[j].getBoundingClientRect();
                if (Math.min(a.right, b.right) - Math.max(a.left, b.left) > 4
                    && Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top) > 4) {
                    out.push(`"${els[i].textContent.trim().slice(0, 30)}" x "${els[j].textContent.trim().slice(0, 30)}"`);
                }
            }
        }
        return out.slice(0, 5);
    JS)[0];

    expect($overlaps)->toBe([], "overlapping copy on {$page}");
}

test('the 360px click-path: join, events, RSVP, profile', function () {
    $member = User::factory()->create();
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
        'capacity' => 8,
    ]);

    $this->browse(function (Browser $browser) use ($member, $event) {
        // Genuine device emulation, not a window size: resize(360, x) leaves
        // deviceScaleFactor and isMobile unset, so the page renders the
        // *desktop* layout in a 360px crop and a responsive bug hides.
        // --no-sandbox/--disable-dev-shm-usage are NOT passed here: Chrome
        // refuses --no-sandbox as a non-privileged user in some builds and CI
        // runs as one, so those two flags live in DUSK_CHROME_ARGS (read by
        // DuskTestCase::driver()) and are set only on hosts that need them.
        (new ChromeDevToolsDriver($browser->driver))->execute('Emulation.setDeviceMetricsOverride', [
            'width' => 360,
            'height' => 780,
            'deviceScaleFactor' => 2,
            'mobile' => true,
        ]);

        // 1. Land, find the way in.
        $browser->visit('/')
            ->assertSeeLink('Come say hello')
            ->click('[data-testid="discord-join"]')
            ->assertPathIs('/join');
        assertMobilePageSound($browser, 'join');

        // 2. The calendar is public; a guest gets the login ask, not a
        // button that cannot work (EventsRsvpTest owns that contract too —
        // here it is the departure point for the click-path).
        $browser->visit('/events')
            ->waitForText('Friday night Helldivers')
            ->assertVisible('[data-testid="event-card"]')
            ->assertSeeLink('Log in with Discord')
            ->assertMissing('[data-testid="rsvp-going"]');
        assertMobilePageSound($browser, 'events');

        // 3. Answer, and prove the answer survived the round trip.
        $browser->loginAs($member)
            ->visit('/events')
            ->waitFor('[data-testid="rsvp-going"]')
            ->assertVisible('[data-testid="rsvp-going"]')
            ->click('[data-testid="rsvp-going"]')
            ->waitFor('[data-testid="rsvp-confirmed"]')
            ->assertSeeIn('[data-testid="rsvp-confirmed"]', "You're in")
            ->refresh()
            ->waitFor('[data-testid="rsvp-confirmed"]');
        assertMobilePageSound($browser, 'events-rsvpd');

        expect(Rsvp::query()
            ->where('event_id', $event->id)
            ->where('user_id', $member->id)
            ->exists())->toBeTrue();

        // 4. Profile: the page the join handshake lands on.
        $browser->visit('/profile')
            ->waitForText($member->username)
            ->assertVisible('[data-testid="profile-edit"]');
        assertMobilePageSound($browser, 'profile');
    });
});
