<?php

use App\Models\Event;
use App\Models\FeaturedContent;
use App\Models\User;
use Laravel\Dusk\Browser;

// The moderator journey in a real browser.
//
// The Pest suite already proves the authorisation rules against the HTTP kernel.
// What it cannot prove is that the panel is a working screen: Filament renders
// through Livewire, so a resource can pass every feature test and still show a
// moderator a blank page, a 500 from a bad column reference, or a table that
// never finishes loading. This journey is the DoD line "Dusk journey green" for
// TOG-54, and the review of PR #218 found it absent.
//
// The last test is the one that matters most — it is the card's premise end to
// end: a moderator changes what the site shows, without a deploy.
//
// Two of these skip without ext-intl, for the same reason the Pest table tests in
// tests/Feature/Admin/EventResourceServiceRoutingTest.php do: rendering a
// *populated* Filament table or form calls Number::format, which needs the
// extension. CI installs it in every job (ci.yml:91,145,214,321) so these run
// there; the local sandbox PHP has no intl.so on disk at all. The two tests that
// assert authorisation do not render a populated table, so they always run.

test('a moderator reaches the panel and sees both resources', function () {
    $moderator = User::factory()->moderator()->create(['display_name' => 'Robin']);

    $this->browse(function (Browser $browser) use ($moderator) {
        $browser->loginAs($moderator)
            ->visit('/admin')
            ->waitForText('TWO Moderation')
            ->assertSee('Events')
            ->assertSee('Featured content');
    });
});

test('a plain member is refused the panel and is not bounced into a login loop', function () {
    // The card names this explicitly: a 403, not a login loop. In a real browser
    // a login loop shows up as the URL leaving /admin; a 403 keeps it there and
    // renders Laravel's forbidden page. Asserting the path is what tells the two
    // apart — a status-code assertion in Pest cannot.
    $member = User::factory()->create();

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->visit('/admin')
            ->assertPathIs('/admin')
            ->assertDontSee('TWO Moderation');
    });
});

test('a moderator can see an event in the panel table', function () {
    $moderator = User::factory()->moderator()->create();
    Event::factory()->create(['title' => 'Tabletop Tuesday']);

    $this->browse(function (Browser $browser) use ($moderator) {
        $browser->loginAs($moderator)
            ->visit('/admin/events')
            ->waitForText('Tabletop Tuesday')
            ->assertSee('Tabletop Tuesday');
    });
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament tables need ext-intl; present in CI, absent in the local sandbox PHP');

test('a moderator publishes featured content and a signed-out visitor sees it', function () {
    // The whole card in one journey. The row starts unpublished and absent from
    // the landing page; the moderator flips it in the panel; the visitor — in a
    // fresh session, signed out — sees it. No deploy anywhere in between.
    $moderator = User::factory()->moderator()->create();
    $row = FeaturedContent::factory()->create([
        'title' => 'Launch night is Friday',
        'body' => 'Doors open at seven.',
    ]);

    $this->browse(function (Browser $browser) use ($moderator, $row) {
        $browser->visit('/')
            ->assertDontSee('Launch night is Friday');

        $browser->loginAs($moderator)
            ->visit("/admin/featured-contents/{$row->getKey()}/edit")
            // Waited for, not asserted: the form is Livewire, so the field is
            // empty for a beat after the document arrives. And waited for by
            // *value* — the title on an edit form is the contents of an input,
            // which `waitForText` will never match because it reads text nodes.
            ->waitUsing(10, 100, fn (): bool => $browser->value('#form\\.title') === 'Launch night is Friday')
            // Filament renders the boolean as a button with role="switch", not a
            // checkbox, so `check()` has nothing to tick. Note the id is
            // `form.is_published`, not `data.is_published` — the wire:model is
            // the latter but the DOM id is the former, and clicking a selector
            // that matches nothing is a silent no-op that surfaces much later as
            // "the visitor never saw the row".
            ->click('#form\\.is_published')
            ->press('Save changes')
            ->waitForText('Saved');

        // A fresh session for the visitor: the point is that the public page
        // changed, not that the moderator can see their own draft.
        $browser->logout()
            ->visit('/')
            ->assertSee('Launch night is Friday')
            ->assertSee('Doors open at seven.');
    });
});

test('the current sidebar item shows a keyboard focus indicator distinct from its selected state', function () {
    // TOG-1655. Filament's only focus treatment for a sidebar item is a
    // background change (`focus-visible:bg-white/5` in the forced-dark panel),
    // and the active item already carries that exact background — so the
    // focused and resting states were the same pixels and WCAG 2.4.7 failed on
    // precisely the item a keyboard user lands on after navigating.
    //
    // The fix is a ring on the active item. This journey proves the computed
    // style actually changes when the link takes keyboard focus, by focus and
    // measurement rather than by asserting a class name — the audit on TOG-1387
    // found the defect exactly because the class was present and the pixels
    // were not.
    //
    // Needs no ext-intl: the dashboard renders no populated table or form, so
    // nothing here calls Number::format.
    $moderator = User::factory()->moderator()->create();

    // Reads the computed focus-relevant properties of the item that is both
    // focused and current, so focused-vs-blurred is one comparable object.
    // Written as an executeScript *body* (`return …;`), not a bare arrow
    // function: chromedriver wraps the string in `function () { … }` and only
    // returns what the body returns — a bare `() => …` evaluates to a function
    // object and comes back as null.
    $computed = <<<'JS'
        const link = document.querySelector('.fi-sidebar-item.fi-active > .fi-sidebar-item-btn');
        if (!link) return null;
        const cs = getComputedStyle(link);
        return {
            matched: document.activeElement === link,
            outline: cs.outlineStyle + ' ' + cs.outlineWidth,
            shadow: cs.boxShadow,
            background: cs.backgroundColor,
        };
        JS;

    $this->browse(function (Browser $browser) use ($moderator, $computed) {
        $browser->loginAs($moderator)
            ->visit('/admin')
            ->waitForText('TWO Moderation');

        // Keyboard focus, not a click: :focus-visible only matches focus from
        // the keyboard, so Tab is the input that could ever reproduce the audit
        // finding. The active item sits behind the skip link, the brand link,
        // the global search input and the user-menu trigger in tab order
        // (measured), so Tab is sent until the link itself reports focus — a
        // fixed count would break the next time the topbar gains or loses a
        // control, and asserting on a not-yet-focused link would compare blur
        // against blur and pass vacuously.
        for ($tabs = 0; $tabs < 12; $tabs++) {
            $browser->keys('', '{TAB}');

            if ((bool) ($browser->script($computed)[0]['matched'] ?? false)) {
                break;
            }
        }

        $focused = $browser->script($computed)[0] ?? null;
        expect($focused)->not->toBeNull()
            ->and($focused['matched'])->toBeTrue();

        // Blur and wait for the ring to drain before reading the resting
        // "selected" state. The ring animates (Filament's duration-75
        // transition), so an immediate read can still see the tail of the
        // fading shadow and the test would compare a half-focused ring
        // against a full one instead of focused against selected.
        $browser->script('document.activeElement && document.activeElement.blur();');
        $browser->waitUsing(5, 100, function () use ($browser, $computed) {
            $blurred = $browser->script($computed)[0] ?? null;

            return $blurred !== null
                && $blurred['matched'] === false
                && $blurred['shadow'] === 'none';
        });
        $blurred = $browser->script($computed)[0] ?? null;
        expect($blurred)->not->toBeNull();

        // The regression: focused must differ from selected-but-unfocused on at
        // least one visible property, or the indicator is not there. (outline,
        // shadow and background cover every way this panel signals focus.)
        expect($focused)->not->toEqual($blurred);

        // And the fix specifically: a visible focus surface — the ring renders
        // as a box-shadow, so a focused current item with `shadow: none` means
        // the treatment is absent even if the objects differ. (Checking the
        // ring rather than an outline pins the mechanism this panel actually
        // uses; a background-only delta would pass the comparison above and
        // still be an indicator that fails 2.4.7 on a low-contrast display.)
        expect($focused['shadow'])->not->toBe('none')
            ->and($focused['shadow'])->not->toBe('')
            // The resting state has no ring: selected-but-unfocused must stay
            // clean, or the ring would stop meaning "focused".
            ->and($blurred['shadow'])->toBe('none');
    });
});
