<?php

use App\Enums\EventStatus;
use App\Models\Event;

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

// The sitemap used to list only the five static pages (TOG-6774). A shared
// `/e/{key}` link is how most guests first arrive, so every published event
// gets a URL — and drafts stay out, because the view policy 403s them for
// guests and the sitemap must not advertise URLs that do not work.
it('lists every published event page and no draft', function () {
    $published = Event::factory()->create(['status' => EventStatus::Published]);
    $draft = Event::factory()->draft()->create();

    $this->get('/sitemap_index.xml')
        ->assertOk()
        ->assertSee(route('events.page', $published), escape: false)
        ->assertDontSee(route('events.page', $draft), escape: false);
});
