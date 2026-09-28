<?php

/*
 * The house rules leaf (TOG-5147). A static Route::view page: no controller, no
 * database reads, no Livewire. It exists so a prospective member can see what the
 * community asks of them before clicking join.
 */

it('serves the house rules page', function () {
    $this->get('/rules')
        ->assertOk()
        ->assertSee('House rules')
        ->assertSee('18+ only')
        ->assertSee('Moderators have the last word')
        ->assertSee('data-testid="rules-list"', escape: false)
        ->assertSee('data-testid="rules-join"', escape: false)
        ->assertSee(route('join'), escape: false);
});

it('shows a maintainer-editable last-updated stamp', function () {
    config(['community.rules_last_updated' => '2026-09-01']);

    $this->get('/rules')
        ->assertOk()
        ->assertSee('data-testid="rules-last-updated"', escape: false)
        ->assertSee('Last updated', escape: false)
        ->assertSee('1 September 2026', escape: false)
        ->assertSee('datetime="2026-09-01"', escape: false);
});

it('hides the stamp instead of 500ing on an invalid last-updated date', function () {
    // A mistyped RULES_LAST_UPDATED must never take down this
    // dependency-free leaf (TOG-7323 review): the page stays 200 and the
    // stamp is hidden instead of rendered.
    config(['community.rules_last_updated' => 'not-a-date']);

    $this->get('/rules')
        ->assertOk()
        ->assertDontSee('data-testid="rules-last-updated"', escape: false);
});

it('hides the stamp instead of 500ing on an empty last-updated date', function () {
    config(['community.rules_last_updated' => '']);

    $this->get('/rules')
        ->assertOk()
        ->assertDontSee('data-testid="rules-last-updated"', escape: false);
});

it('advertises the house rules in the sitemap', function () {
    $this->get('/sitemap_index.xml')
        ->assertOk()
        ->assertSee(route('rules'), escape: false);
});

it('links the house rules from the homepage footer', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(route('rules'), escape: false);
});
