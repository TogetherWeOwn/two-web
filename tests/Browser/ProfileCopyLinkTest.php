<?php

use App\Models\User;
use Laravel\Dusk\Browser;

/*
 * Copy-profile-link in a real browser (TOG-6926) — the half Pest cannot reach.
 *
 * The Feature test proves the button carries the canonical URL and the toast
 * slot renders; none of that proves the click copies anything. Clipboard lives
 * entirely in the browser: `navigator.clipboard.writeText` in secure contexts,
 * the hidden-textarea + `execCommand` fallback everywhere else. Both paths are
 * driven here, plus the total-failure toast.
 *
 * `waitForText`/`waitUntil`, never `pause` (docs/flake-policy.md). The clipboard
 * stubs are installed with `script()` after load so each test controls exactly
 * which path the module takes, independent of whatever context CI's Chrome
 * decides `localhost` is that day.
 */

test('copying a profile link shows the confirmation toast', function () {
    $member = User::factory()->create(['display_name' => 'Wren']);

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->visit(route('profiles.show', $member))
            ->waitFor('[data-testid="profile-copy-link"]')
            ->assertSeeIn('[data-testid="profile-copy-link"]', 'Copy link')
            ->click('[data-testid="profile-copy-link"]')
            ->waitForText('Profile link copied.')
            ->assertVisible('[data-testid="profile-copy-toast"]');
    });
});

test('the execCommand fallback copies when there is no async clipboard', function () {
    $member = User::factory()->create(['display_name' => 'Rowan']);

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->visit(route('profiles.show', $member))
            ->waitFor('[data-testid="profile-copy-link"]')
            // No secure-context clipboard here (plain http, old browser), and a
            // stubbed execCommand that records the call instead of touching a
            // real clipboard headless Chrome may or may not grant.
            ->script([
                "Object.defineProperty(window.navigator, 'clipboard', {value: undefined, configurable: true});",
                'window.__fallbackCalls = []; document.execCommand = function (cmd) { window.__fallbackCalls.push(cmd); return true; };',
            ])
            ->click('[data-testid="profile-copy-link"]')
            ->waitUntil("window.__fallbackCalls.length === 1 && window.__fallbackCalls[0] === 'copy'")
            ->waitForText('Profile link copied.');
    });
});

test('a failed copy says so instead of confirming', function () {
    $member = User::factory()->create(['display_name' => 'Ash']);

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->visit(route('profiles.show', $member))
            ->waitFor('[data-testid="profile-copy-link"]')
            // Both paths dead: no async clipboard, execCommand refuses.
            ->script([
                "Object.defineProperty(window.navigator, 'clipboard', {value: undefined, configurable: true});",
                'document.execCommand = function () { return false; };',
            ])
            ->click('[data-testid="profile-copy-link"]')
            ->waitForText("That link didn't copy");
    });
});
