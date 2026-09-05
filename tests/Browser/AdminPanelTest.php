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
            ->waitForText('Launch night is Friday')
            // Filament renders the boolean as a toggle button carrying the field
            // label, not a bare checkbox, so the label is the stable handle.
            ->click('label[for="data.is_published"]')
            ->press('Save changes')
            ->waitForText('Saved');

        // A fresh session for the visitor: the point is that the public page
        // changed, not that the moderator can see their own draft.
        $browser->logout()
            ->visit('/')
            ->assertSee('Launch night is Friday')
            ->assertSee('Doors open at seven.');
    });
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament forms need ext-intl; present in CI, absent in the local sandbox PHP');
