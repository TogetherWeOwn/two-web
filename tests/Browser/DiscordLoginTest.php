<?php

use App\Models\User;
use Laravel\Dusk\Browser;

// The login journey in a real browser.
//
// The one thing Dusk deliberately does NOT do here is drive the actual Discord
// consent screen. That would mean a real Discord account, a real password and a
// real third party in our merge pipeline — flaky by construction. The OAuth leg is
// covered by the Pest suite against a stubbed provider, and by QA doing the real
// round-trip once on staging. What Dusk owns is everything on our side of it:
// the way in, what you see once you are in, the way out, and the failure message.

test('a signed-out visitor is offered Discord as the way in', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/')
            ->assertSee('Together We Own')
            ->assertSeeLink('Sign in with Discord')
            ->assertAttribute('[data-testid="discord-login"]', 'href', url('/auth/discord/redirect'));
    });
});

test('a member lands on their profile and is not offered the admin panel', function () {
    $member = User::factory()->create(['display_name' => 'Wren']);

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->visit('/profile')
            ->assertSee('Your profile')
            ->assertSee('Wren')
            ->assertMissing('[data-testid="admin-link"]');
    });
});

test('a moderator is offered the admin panel', function () {
    $moderator = User::factory()->moderator()->create();

    $this->browse(function (Browser $browser) use ($moderator) {
        $browser->loginAs($moderator)
            ->visit('/profile')
            ->assertVisible('[data-testid="admin-link"]');
    });
});

test('a member can sign out again', function () {
    // Two waits, and neither of them is a retry. This test failed once with a
    // StaleElementReferenceException and passed on the same code minutes later,
    // which makes it a flake, which makes it a bug (docs/flake-policy.md).
    //
    // Both halves of the old version asserted without waiting for a condition:
    //
    //   press()        resolves the button and clicks it in two separate round
    //                  trips with nothing in between. If the document is replaced
    //                  in that gap the handle is detached and WebDriver throws
    //                  instead of clicking — so the click provably never happened,
    //                  and the failure is real rather than cosmetic.
    //   assertPathIs() reads the URL on the line after the click, assuming the
    //                  POST, the redirect and the GET have all landed already.
    //                  Nothing guarantees that.
    //
    // waitForText makes the resolve happen against a rendered page rather than a
    // page part-way through becoming one. waitForReload waits for the outcome that
    // actually matters — the old document going away — so assertPathIs runs on the
    // page we ended up on, and says "path is /profile" rather than timing out if
    // logout is broken. Nothing here re-runs a step that failed.
    $this->browse(function (Browser $browser) {
        $browser->loginAs(User::factory()->create())
            ->visit('/profile')
            ->waitForText('Sign out')
            ->waitForReload(fn (Browser $page) => $page->press('Sign out'))
            ->assertPathIs('/')
            ->assertSeeLink('Sign in with Discord');
    });
});

test('cancelling on the Discord consent screen gives a sentence, not a stack trace', function () {
    // A real browser hitting the real callback with the real thing Discord sends
    // when a member presses Cancel. No stubbing involved.
    $this->browse(function (Browser $browser) {
        $browser->visit('/auth/discord/callback?error=access_denied&error_description=The+user+denied+access')
            ->assertPathIs('/')
            ->assertVisible('[data-testid="auth-error"]')
            ->assertSee(__('auth-discord.denied'))
            ->assertAttribute('[data-testid="auth-error"]', 'role', 'alert');
    });
});
