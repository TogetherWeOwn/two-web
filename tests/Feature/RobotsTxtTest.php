<?php

// TOG-7071: staging served a static public/robots.txt whose Sitemap line named
// the apex production host, because nginx try_files serves a static file before
// Laravel ever sees the request. robots.txt is now a route built from APP_URL,
// so each environment advertises its own sitemap index.

it('advertises this environment’s own sitemap host in robots.txt', function () {
    $sitemap = route('sitemap');

    // The route helper carries the app host: whatever APP_URL says, the Sitemap
    // line must say the same.
    expect(parse_url($sitemap, PHP_URL_HOST))->toBe(parse_url(config('app.url'), PHP_URL_HOST));

    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee("Sitemap: {$sitemap}", escape: false);
});

it('has no static robots.txt shadowing the route', function () {
    // A static public/robots.txt would win under nginx try_files (and Apache
    // !-f) and silently re-pin whatever host is written in it.
    expect(public_path('robots.txt'))->not->toBeFile();
});
