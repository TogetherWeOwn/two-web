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

// TOG-7072: the sitemap lists the shareable event pages a guest can open
// with a 200 — published events only — with lastmod. Drafts 403 for guests,
// cancelled answers 410 Gone (TOG-6781), and past events are over, so none of
// those are advertised. Auth-gated and off-host URLs never leak in either.
it('lists published event pages with lastmod, never drafts, gone or gated URLs', function () {
    $published = Event::factory()->create(['status' => EventStatus::Published]);
    $draft = Event::factory()->create(['status' => EventStatus::Draft]);
    $cancelled = Event::factory()->create(['status' => EventStatus::Cancelled]);
    $past = Event::factory()->create([
        'status' => EventStatus::Past,
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->subDays(2)->addHours(2),
    ]);

    $body = $this->get('/sitemap_index.xml')->assertOk()->getContent();

    $xml = simplexml_load_string($body);
    expect($xml)->not->toBeFalse();

    // preserve_keys: false — SimpleXML siblings share the `url` key, so the
    // default collapses every <url> into one and all but the last vanish.
    $locs = collect(iterator_to_array($xml->url, false))->map(fn ($url) => (string) $url->loc)->all();

    // The published event is advertised as an absolute same-host URL with lastmod.
    // Matched by iterating, not xpath: the sitemap root declares the default
    // `http://www.sitemaps.org/schemas/sitemap/0.9` namespace, so a bare
    // `//url[loc=...]` xpath silently matches nothing.
    $pageUrl = route('events.page', $published);
    expect($locs)->toContain($pageUrl);

    $matches = [];
    foreach ($xml->url as $url) {
        if ((string) $url->loc === $pageUrl) {
            $matches[] = $url;
        }
    }
    expect($matches)->toHaveCount(1)
        ->and((string) $matches[0]->lastmod)->toBe($published->fresh()->updated_at->toAtomString());

    // Draft (guest 403), cancelled (410 Gone) and past (over) stay out.
    foreach ([$draft, $cancelled, $past] as $event) {
        expect($locs)->not->toContain(route('events.page', $event));
    }

    // No auth-gated, API, or wrong-host URL leaks into the index.
    foreach ($locs as $loc) {
        expect($loc)->toStartWith(config('app.url'))
            ->and($loc)->not->toContain('/profile')
            ->and($loc)->not->toContain('/members/')
            ->and($loc)->not->toContain('/events.json')
            ->and($loc)->not->toContain('/auth/')
            ->and($loc)->not->toContain('/admin');
    }

    // The advertised page actually opens for a guest.
    $this->get($pageUrl)->assertOk();
});

it('serves a valid sitemap with no events', function () {
    $body = $this->get('/sitemap_index.xml')->assertOk()->getContent();

    $xml = simplexml_load_string($body);
    expect($xml)->not->toBeFalse();

    $locs = collect(iterator_to_array($xml->url, false))->map(fn ($url) => (string) $url->loc)->all();

    expect($locs)->toContain(route('home'))
        ->and($locs)->toContain(route('join'))
        ->and($locs)->toContain(route('events.index'));
});
