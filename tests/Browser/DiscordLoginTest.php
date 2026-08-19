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
    $this->browse(function (Browser $browser) {
        $browser->loginAs(User::factory()->create())
            ->visit('/profile')
            ->press('Sign out')
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
