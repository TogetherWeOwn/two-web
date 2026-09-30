<?php

use App\Models\Profile;
use App\Models\User;
use App\Support\SpamTrap;
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

it('preserves unsent profile input when auth sync navigates before save', function (string $source) {
    $member = User::factory()->create();

    $this->browse(function (Browser $browser) use ($member, $source) {
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
        $browser->script($source === 'focus'
            ? 'window.dispatchEvent(new Event("focus"));'
            : 'window.dispatchEvent(new StorageEvent("storage", { key: "two-auth", newValue: "signed-out" }));');

        $browser->waitFor('[data-testid="profile-draft-restored"]')
            ->assertInputValue('bio', 'Auth-sync draft')
            ->assertInputValue('gamesText', "Chess\nCo-op")
            ->assertInputValue('timezone', 'Europe/London');
        expect($member->profile()->first())->toBeNull();
    });
})->with(['focus', 'storage']);

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
