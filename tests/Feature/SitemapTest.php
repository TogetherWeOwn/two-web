<?php

it('advertises the public pages as XML', function () {
    $this->get('/sitemap_index.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee(route('home'), escape: false)
        ->assertSee(route('join'), escape: false)
        ->assertSee(route('events.index'), escape: false)
        ->assertDontSee(route('profile'), escape: false)
        ->assertDontSee(route('login.callback'), escape: false);
});
