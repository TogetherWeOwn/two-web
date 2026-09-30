<?php

use App\Models\Profile;
use App\Models\User;
use App\Support\SpamTrap;
use Facebook\WebDriver\WebDriverTargetLocator;
use Laravel\Dusk\Browser;

it('lets a member edit their sparse profile with the keyboard-visible form', function () {
    $member = User::factory()->create([
        'display_name' => 'Wren',
        'avatar' => null,
    ]);

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->visit('/profile')
            ->assertVisible('[data-testid="profile-new-member"]')
            ->assertSee('Your profile has room to grow.')
            ->press('Add profile details')
            ->waitFor('[data-testid="profile-edit-form"]')
            ->type('bio', 'Usually on after work.')
            ->type('gamesText', "Minecraft\nHelldivers 2")
            ->type('timezone', 'Europe/London')
            // TOG-8715: Dusk types instantaneously, but the fill-time trap
            // only accepts saves past the floor — a real member takes seconds
            // to fill three fields. Pause like a human before saving.
            ->pause(SpamTrap::MIN_FILL_MS + 500)
            ->press('Save')
            ->waitFor('[data-testid="profile-saved"]')
            ->assertSee('Usually on after work.')
            ->assertSee('Helldivers 2')
            ->assertSee('Europe/London')
            ->refresh()
            ->waitForText('Usually on after work.')
            ->assertSee('Usually on after work.')
            ->assertSee('Helldivers 2')
            ->assertSee('Europe/London');
    });

    expect(Profile::query()->whereBelongsTo($member)->sole())
        ->bio->toBe('Usually on after work.')
        ->games->toBe(['Minecraft', 'Helldivers 2'])
        ->timezone->toBe('Europe/London');
});

it('moves focus into the form on open and back to Edit profile on cancel (TOG-5634)', function () {
    $member = User::factory()->create();

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->visit('/profile')
            ->press('Add profile details')
            ->waitFor('[data-testid="profile-edit-form"]')
            // The trigger unmounts when the form renders: without the focus
            // contract the keyboard user is left on <body>.
            ->waitUntil('document.activeElement?.id === "edit-profile-heading"')
            ->assertSee('Edit your profile')
            ->press('Cancel')
            ->waitUntil('document.activeElement?.dataset?.testid === "profile-edit"')
            ->assertVisible('[data-testid="profile-edit"]');
    });
});

it('recovers deferred profile input after a real CSRF 419 and page round trip', function () {
    $member = User::factory()->create();

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->visit('/profile')
            ->press('Add profile details')
            ->waitFor('[data-testid="profile-edit-form"]')
            ->type('bio', 'Unsent <bio>')
            ->type('gamesText', "Chess\nCo-op")
            ->type('timezone', 'Europe/London');

        // Reject the actual Livewire POST in CSRF middleware. Keep the login
        // destination local to isolate recovery from Discord availability;
        // the authenticated fresh page supplies the new CSRF token.
        $browser->script(<<<'JS'
            document.querySelector('[data-profile-id]').dataset.loginUrl = '/profile';
            document.querySelectorAll('meta[name="csrf-token"]').forEach(meta => meta.content = 'expired-token');
            document.querySelectorAll('script[data-csrf]').forEach(script => script.dataset.csrf = 'expired-token');
        JS);

        $browser->press('Save')
            ->waitFor('[data-testid="profile-draft-restored"]')
            ->assertInputValue('bio', 'Unsent <bio>')
            ->assertInputValue('gamesText', "Chess\nCo-op")
            ->assertInputValue('timezone', 'Europe/London')
            ->waitUntil('document.activeElement?.dataset?.testid === "profile-draft-restored"');

        expect($member->profile()->first())->toBeNull();

        $browser->pause(SpamTrap::MIN_FILL_MS + 500)
            ->press('Save')
            ->waitFor('[data-testid="profile-saved"]')
            ->refresh()
            ->waitForText('Unsent <bio>');
    });

    expect($member->profile()->sole())
        ->bio->toBe('Unsent <bio>')
        ->games->toBe(['Chess', 'Co-op'])
        ->timezone->toBe('Europe/London');
});

it('does not resurrect a retired draft over a genuine save after refresh', function () {
    $member = User::factory()->create();

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->visit('/profile');

        // Reproduce the failed-first-restore state directly: a stored copy
        // the member never saw (the restore threw, e.g. offline), then the
        // member opens the form by hand and saves genuinely. The save must
        // retire the stored copy — otherwise refresh resurrects the obsolete
        // text over the newer saved text.
        $key = "two:profile-draft:{$member->getKey()}";
        $browser->script(<<<JS
            sessionStorage.setItem('{$key}', JSON.stringify({
                version: 1,
                savedAt: Date.now(),
                bio: 'Stale draft text',
                gamesText: '',
                timezone: '',
            }));
        JS);

        $browser->press('Add profile details')
            ->waitFor('[data-testid="profile-edit-form"]')
            ->type('bio', 'Genuine save')
            // Same human-fill pause as the other save journeys: the
            // server-locked fill floor applies to hand-opened forms too.
            ->pause(SpamTrap::MIN_FILL_MS + 500)
            ->press('Save')
            ->waitFor('[data-testid="profile-saved"]')
            // Deterministic red/green: without the retire signal the only
            // stored copy survives the genuine save.
            ->assertScript("sessionStorage.getItem('{$key}')", null)
            ->refresh()
            ->waitForText('Genuine save')
            // Give a buggy restore round trip time to land before asserting
            // its absence: the banner renders only when a stored copy exists.
            ->pause(1000)
            ->assertSee('Genuine save')
            ->assertDontSee('Stale draft text')
            ->assertMissing('[data-testid="profile-draft-restored"]');
    });

    expect($member->profile()->sole()->bio)->toBe('Genuine save');
});

it('preserves unsent profile input when an expiry probe navigates before save', function () {
    $member = User::factory()->create();

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->visit('/profile')
            ->press('Add profile details')
            ->waitFor('[data-testid="profile-edit-form"]')
            ->type('bio', 'Auth-sync draft')
            ->type('gamesText', "Chess\nCo-op")
            ->type('timezone', 'Europe/London');

        // Exercise the shipped layout and component listeners, then a real
        // page round trip. Stub only the probe verdict/login destination;
        // Discord and an actual logout are covered by their own journeys.
        $browser->script(<<<'JS'
            document.querySelector('[data-profile-id]').dataset.loginUrl = '/profile';
            const originalFetch = window.fetch;
            window.fetch = (url, options) => String(url).endsWith('/auth/status')
                ? Promise.resolve(new Response(JSON.stringify({ authenticated: false }), { status: 200 }))
                : originalFetch(url, options);
        JS);
        $browser->script('window.dispatchEvent(new Event("focus"));');

        $browser->waitFor('[data-testid="profile-draft-restored"]')
            ->assertInputValue('bio', 'Auth-sync draft')
            ->assertInputValue('gamesText', "Chess\nCo-op")
            ->assertInputValue('timezone', 'Europe/London');
        expect($member->profile()->first())->toBeNull();
    });
});

it('stashes the draft without starting login when another tab broadcasts sign-out', function () {
    $member = User::factory()->create();

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->visit('/profile')
            // Order-dependence guard (docs/flake-policy.md; DiscordLoginTest
            // 'a member can sign out again'): press() resolves then clicks
            // in separate round trips, so resolve against the rendered
            // closed page rather than a page mid-morph — including the
            // morph a leaked draft's auto-restore would cause. The next
            // test's prefix depends on this storage being clean.
            ->waitFor('[data-testid="profile-new-member"]')
            ->press('Add profile details')
            ->waitFor('[data-testid="profile-edit-form"]')
            ->type('bio', 'Sign-out broadcast draft')
            ->type('gamesText', "Chess\nCo-op")
            ->type('timezone', 'Europe/London');

        // Point the handoff at the real OAuth redirect so the regression is
        // meaningful: the old listener navigated here, where the pinned
        // Discord driver's prompt=none could silently complete an existing
        // grant and sign the shared browser back in with no login click
        // (TOG-9355 review). The broadcast dispatch below runs the shipped
        // listeners synchronously, so the assertions after it are exact.
        $browser->script(<<<'JS'
            document.querySelector('[data-profile-id]').dataset.loginUrl = '/auth/discord/redirect?next=%2Fprofile';
        JS);
        $browser->script('window.dispatchEvent(new StorageEvent("storage", { key: "two-auth", newValue: "signed-out" }));');

        $key = "two:profile-draft:{$member->getKey()}";
        $browser->assertPathIs('/profile')
            ->assertVisible('[data-testid="profile-edit-form"]')
            ->assertInputValue('bio', 'Sign-out broadcast draft')
            ->assertScript("JSON.parse(sessionStorage.getItem('{$key}')).bio", 'Sign-out broadcast draft')
            ->assertScript("JSON.parse(sessionStorage.getItem('{$key}')).timezone", 'Europe/London');
        expect($member->profile()->first())->toBeNull();

        // A later expiry probe describes the same dead session, never a
        // quiet expiry: it must not start login either. The stubbed probe is
        // async, so give a would-be navigation time to land before asserting
        // its absence (same negative-assertion pattern as the retire test).
        $browser->script(<<<'JS'
            const originalFetch = window.fetch;
            window.fetch = (url, options) => String(url).endsWith('/auth/status')
                ? Promise.resolve(new Response(JSON.stringify({ authenticated: false }), { status: 200 }))
                : originalFetch(url, options);
        JS);
        $browser->script('window.dispatchEvent(new Event("focus"));');
        $browser->pause(1500)
            ->assertPathIs('/profile')
            ->assertVisible('[data-testid="profile-edit-form"]')
            ->assertInputValue('bio', 'Sign-out broadcast draft');

        // Leave no stored draft behind. This tab is shared with the next
        // test and profile ids are reused across tests, so a leftover here
        // would restore on their page load and open a form they never
        // asked for (docs/flake-policy.md: order dependence).
        $browser->script("sessionStorage.removeItem('{$key}');");
    });
});

it('keeps an open editor on the page when a second tab signs out for real', function () {
    $member = User::factory()->create();

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->visit('/profile')
            // Same order-dependence guard as the broadcast test above:
            // the wait only passes on a genuinely fresh page, so a leaked
            // draft's auto-restore cannot silently steal this press.
            ->waitFor('[data-testid="profile-new-member"]')
            ->press('Add profile details')
            ->waitFor('[data-testid="profile-edit-form"]')
            ->type('bio', 'Cross-tab sign-out draft')
            ->type('gamesText', "Chess\nCo-op")
            ->type('timezone', 'Europe/London');

        $tabA = $browser->driver->getWindowHandle();

        // Tab B shares the session: a real sign-out there broadcasts
        // `two-auth = signed-out` to the editing tab — no synthetic events.
        $browser->driver->switchTo()->newWindow(WebDriverTargetLocator::WINDOW_TYPE_TAB);
        $tabB = $browser->driver->getWindowHandle();
        $browser->visit('/profile')
            ->waitForText('Sign out')
            ->waitForReload(fn (Browser $page) => $page->press('Sign out'))
            ->assertPathIs('/');

        // Back to the editing tab. The broadcast stashes the draft, but the
        // tab must stay on the open form: navigating to the login handoff
        // would let Discord prompt=none silently sign the shared browser
        // back in. Only an explicit login action may leave this page.
        $browser->driver->switchTo()->window($tabA);
        $key = "two:profile-draft:{$member->getKey()}";
        $browser->waitUntil("JSON.parse(sessionStorage.getItem('{$key}') || 'null')?.bio === 'Cross-tab sign-out draft'");

        $browser->assertPathIs('/profile')
            ->assertVisible('[data-testid="profile-edit-form"]')
            ->assertInputValue('bio', 'Cross-tab sign-out draft')
            ->assertInputValue('gamesText', "Chess\nCo-op")
            ->assertInputValue('timezone', 'Europe/London');
        expect($member->profile()->first())->toBeNull();

        // Same leak guard as the broadcast test: tab A's sign-out stash
        // must not restore into the next test's fresh page.
        $browser->script("sessionStorage.removeItem('{$key}');");

        $browser->driver->switchTo()->window($tabB);
        $browser->driver->close();
        $browser->driver->switchTo()->window($tabA);
    });
});

it('blocks auth-sync navigation when profile draft storage is unavailable', function (string $source) {
    $member = User::factory()->create();

    $this->browse(function (Browser $browser) use ($member, $source) {
        $browser->loginAs($member)
            ->visit('/profile')
            ->press('Add profile details')
            ->waitFor('[data-testid="profile-edit-form"]')
            ->type('bio', 'Copy this before login');

        $browser->script(<<<'JS'
            window.__draftRecoveryProbe = 'unsent';
            Object.defineProperty(window, 'sessionStorage', { get() { throw new Error('disabled'); } });
            const originalFetch = window.fetch;
            window.fetch = (url, options) => String(url).endsWith('/auth/status')
                ? Promise.resolve(new Response(JSON.stringify({ authenticated: false }), { status: 200 }))
                : originalFetch(url, options);
        JS);
        $browser->script($source === 'focus'
            ? 'window.dispatchEvent(new Event("focus"));'
            : 'window.dispatchEvent(new StorageEvent("storage", { key: "two-auth", newValue: "signed-out" }));');

        $browser->waitFor('[data-testid="profile-draft-unavailable"]')
            ->assertInputValue('bio', 'Copy this before login')
            ->assertScript('window.__draftRecoveryProbe', 'unsent')
            ->waitUntil('document.activeElement?.dataset?.testid === "profile-draft-unavailable"');
        expect($member->profile()->first())->toBeNull();
    });
})->with(['focus', 'storage']);

it('keeps an invalid edit open and gives the field an accessible error', function () {
    $member = User::factory()->create();

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->visit('/profile')
            ->press('Add profile details')
            ->waitFor('[data-testid="profile-edit-form"]')
            ->type('timezone', 'BST')
            ->press('Save')
            ->waitFor('[data-testid="profile-edit-failed"]')
            ->assertVisible('[data-testid="profile-edit-form"]')
            ->assertAttribute('#timezone', 'aria-invalid', 'true')
            ->assertSee('Check the highlighted fields and try again.');
    });
});
