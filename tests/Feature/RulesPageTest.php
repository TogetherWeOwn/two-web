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
