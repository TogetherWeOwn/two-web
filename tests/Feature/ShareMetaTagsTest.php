<?php

// The join funnel lives on shared links (TOG-5624): home, join and events each
// carry their own canonical URL plus OG and Twitter Card tags. Every URL is
// built with route() from APP_URL — a literal hostname here would trip
// tests/Unit/NoHardcodedHostnamesTest.php, so the expectations interpolate
// route() rather than spelling a host.

function assertShareTags(string $html, string $canonical, string $title, string $description): void
{
    $needles = [
        '<link rel="canonical" href="'.$canonical.'">' => 'canonical',
        '<meta property="og:type" content="website">' => 'og:type',
        '<meta property="og:site_name" content="'.e(config('app.name')).'">' => 'og:site_name',
        '<meta property="og:url" content="'.$canonical.'">' => 'og:url',
        '<meta property="og:title" content="'.e($title).'">' => 'og:title',
        '<meta property="og:description" content="'.e($description).'">' => 'og:description',
        '<meta name="twitter:card" content="summary">' => 'twitter:card',
        '<meta name="twitter:title" content="'.e($title).'">' => 'twitter:title',
        '<meta name="twitter:description" content="'.e($description).'">' => 'twitter:description',
    ];

    foreach ($needles as $needle => $label) {
        expect($html)->toContain($needle);
    }
}

it('tags the homepage for sharing', function () {
    $html = (string) $this->get(route('home'))->assertOk()->getContent();

    assertShareTags(
        $html,
        route('home'),
        'Together We Own — the lobby is open',
        'We spent most of our life private. Now you can just turn up.',
    );
});

it('tags the join page for sharing', function () {
    $html = (string) $this->get(route('join'))->assertOk()->getContent();

    assertShareTags(
        $html,
        route('join'),
        'Join Together We Own',
        __('join.intro'),
    );
});

it('tags the events page for sharing', function () {
    $html = (string) $this->get(route('events.index'))->assertOk()->getContent();

    assertShareTags(
        $html,
        route('events.index'),
        'Events — Together We Own',
        'Game nights, tournaments and whatever else the community puts on.',
    );
});

it('keeps exactly one canonical per page, pointing at itself', function () {
    $home = (string) $this->get(route('home'))->assertOk()->getContent();
    $join = (string) $this->get(route('join'))->assertOk()->getContent();
    $events = (string) $this->get(route('events.index'))->assertOk()->getContent();

    // Exactly one canonical per page: two would leave crawlers guessing.
    expect(substr_count($home, 'rel="canonical"'))->toBe(1, 'home has the wrong canonical count');
    expect(substr_count($join, 'rel="canonical"'))->toBe(1, 'join has the wrong canonical count');
    expect(substr_count($events, 'rel="canonical"'))->toBe(1, 'events has the wrong canonical count');

    // The join URL appears all over the homepage as CTAs, so only the
    // canonical line itself is asserted — elsewhere the href must differ.
    expect($home)->toContain('<link rel="canonical" href="'.route('home').'">');
    expect($join)->toContain('<link rel="canonical" href="'.route('join').'">');
    expect($events)->toContain('<link rel="canonical" href="'.route('events.index').'">');
});
