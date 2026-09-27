<?php

use App\Models\User;
use Laravel\Dusk\Browser;

// The login journey in a real browser.
//
// Discord itself is not a merge dependency. The application server registers a
// Discord-shaped provider whose endpoints are ci/dusk-stub.mjs, so the browser
// still clicks the real login link and traverses Socialite's state, code, token and
// user exchange before the real callback creates the local member.

// "Log in with Discord", not "Sign in with Discord": the placeholder homepage
// said the latter, but two-design docs/COPY.md is the authority on the words and
// its login row says "Log in with Discord". The real landing page (TOG-48) uses
// the spec's wording, so these assertions follow it. The `data-testid` is the
// part that must not drift — the text is allowed to, the hook is not.
test('a signed-out visitor is offered Discord as the way in', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/')
            ->assertSee('Together We Own')
            ->assertSeeLink('Log in with Discord')
            ->assertAttribute('[data-testid="discord-login"]', 'href', url('/auth/discord/redirect'));
    });
});

test('a member signs in through Discord and lands on their profile', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/')
            ->assertSeeLink('Log in with Discord')
            ->click('[data-testid="discord-login"]')
            ->waitForLocation('/profile')
            ->assertPathIs('/profile')
            ->assertSee('Your profile')
            ->assertSee('WREN')
            ->assertMissing('[data-testid="admin-link"]');
    });

    $member = User::query()->sole();

    expect($member->discord_id)->toBe('111222333444555666')
        ->and($member->username)->toBe('wren')
        ->and($member->display_name)->toBe('Wren')
        ->and($member->discord_synced_at)->not->toBeNull();
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
            ->assertSeeLink('Log in with Discord');
    });
});

test('cancelling on the Discord consent screen gives a sentence, not a stack trace', function () {
    // A real browser hitting the real callback with the real thing Discord sends
    // when a member presses Cancel. No stubbing involved. TOG-5606 renders the
    // recovery page in place (200), so the path stays on the callback.
    $this->browse(function (Browser $browser) {
        $browser->visit('/auth/discord/callback?error=access_denied&error_description=The+user+denied+access')
            ->assertPathIs('/auth/discord/callback')
            ->assertVisible('[data-testid="oauth-recovery"]')
            ->assertSee(__('auth-discord.recovery_denied'))
            ->assertAttribute('[data-testid="oauth-recovery"]', 'role', 'alert')
            ->assertVisible('[data-testid="oauth-recovery-retry"]')
            ->assertAttribute('[data-testid="oauth-recovery-retry"]', 'href', url('/auth/discord/redirect'));
    });
});
