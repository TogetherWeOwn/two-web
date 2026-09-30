<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Facebook\WebDriver\WebDriverTargetLocator;
use Laravel\Dusk\Browser;

/*
 * The two-tab sign-out journey in a real browser (TOG-8136).
 *
 * Logout destroys the session server-side, but a second tab kept rendering
 * @auth controls until its next load — the next click there 302'd with no
 * explanation. The layout's tab-sync script closes that gap: the logging-out
 * tab broadcasts `two-auth = signed-out` over a `storage` event, and every
 * authenticated tab also re-checks GET auth.status on visibility/focus.
 *
 * What this owns that the Pest suite cannot. AuthStatusTest proves the probe
 * answers correctly and the script is emitted; neither proves the browser
 * actually runs it. Only here do two tabs share one session, one tab signs
 * out, and the other tab is observed re-rendering to the guest pitch without
 * a failing click first.
 *
 * The focus event is dispatched synthetically. A real tab-switch focus change
 * is a window-manager act WebDriver cannot promise headless Chrome will
 * report, and waiting on it would be a flake (docs/flake-policy.md). The
 * dispatch only rings the fallback doorbell — the session verdict and reload
 * are real, whether storage or the focus re-check gets there first.
 */

test('a second tab re-renders to the guest pitch after sign-out elsewhere', function () {
    $member = User::factory()->create();
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
        'capacity' => 4,
    ]);

    $this->browse(function (Browser $browser) use ($member, $event) {
        $eventUrl = route('events.page', $event);

        // Tab A: signed in, looking at the event with its RSVP control.
        // The tab-sync marker is a `script` element: never displayed, so
        // `waitFor()` (which requires visibility) can never match it — wait
        // on the visible RSVP control and assert the script by presence.
        $browser->loginAs($member)
            ->visit($eventUrl)
            ->waitFor('[data-testid="rsvp-going"]')
            ->assertPresent('[data-testid="auth-tab-sync"]')
            ->assertVisible('[data-testid="rsvp-going"]');

        $tabA = $browser->driver->getWindowHandle();

        // Tab B: same session, same event. A new tab shares the browser
        // profile, so it shares the session — this is what makes it a second
        // tab rather than a second member.
        $browser->driver->switchTo()->newWindow(WebDriverTargetLocator::WINDOW_TYPE_TAB);
        $tabB = $browser->driver->getWindowHandle();
        $browser->visit($eventUrl)
            ->waitFor('[data-testid="rsvp-going"]')
            ->assertPresent('[data-testid="auth-tab-sync"]')
            ->assertVisible('[data-testid="rsvp-going"]');

        // Sign out in tab B, through the real profile form.
        $browser->visit(route('profile'))
            ->waitForText('Sign out')
            ->waitForReload(fn (Browser $page) => $page->press('Sign out'))
            ->assertPathIs('/')
            ->assertSeeLink('Log in with Discord');

        // Back to tab A: the storage event may already have refreshed it to
        // the guest render. Requiring a stale RSVP control here races that
        // intended reload. Both tabs' signed-in controls were checked above.
        $browser->driver->switchTo()->window($tabA);
        // Ring the fallback doorbell if the storage path has not refreshed it.
        // `script` returns the evaluation result, not the browser.
        $browser->script('window.dispatchEvent(new Event("focus"));');

        // No failing click needed: the tab reloads into the guest pitch.
        $browser->waitFor('[data-testid="event-join-pitch"]')
            ->assertVisible('[data-testid="event-join-pitch"]')
            ->assertMissing('[data-testid="rsvp-going"]')
            ->assertMissing('[data-testid="auth-tab-sync"]');

        $browser->driver->switchTo()->window($tabB);
        $browser->driver->close();
        $browser->driver->switchTo()->window($tabA);
    });
});
