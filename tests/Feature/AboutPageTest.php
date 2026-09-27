<?php

/*
 * The about leaf (TOG-5310). A static Route::view page: no controller, no
 * database reads, no Livewire. It exists so a prospective member can see what
 * the community is before clicking join. Deliberately a different page than the
 * /rules slice in TOG-5147.
 */

it('serves the about page', function () {
    $this->get('/about')
        ->assertOk()
        ->assertSee('About Together We Own')
        ->assertSee('Est. 1998')
        ->assertSee('Voice-first')
        ->assertSee('data-testid="about-facts"', escape: false)
        ->assertSee('data-testid="about-join"', escape: false)
        ->assertSee(route('join'), escape: false);
});

it('advertises the about page in the sitemap', function () {
    $this->get('/sitemap_index.xml')
        ->assertOk()
        ->assertSee(route('about'), escape: false);
});

it('links the about page from the homepage footer', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee(route('about'), escape: false);
});
