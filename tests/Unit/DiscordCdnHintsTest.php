<?php

// TOG-8413: member avatars come from cdn.discordapp.com (the Discord OAuth
// provider builds https://cdn.discordapp.com/avatars/... URLs, and the CSP
// middleware docblock names the same host) and the profile avatar <img> is
// eager above the fold, so the shared layout warms DNS/TLS for that origin on
// every page. This pins both hints in every layout scheme — unconditional, so
// a page cannot silently opt out, same reasoning as the feed autodiscovery
// link beside them. The exact-string match also pins the preconnect carrying
// no crossorigin: the avatar is a no-CORS <img>, and a crossorigin hint would
// open a pooled connection the image never reuses.

it('hints the Discord CDN in every shared layout scheme', function (?string $scheme) {
    $html = $this->blade('<x-layouts.app :scheme="$scheme">Test page</x-layouts.app>', ['scheme' => $scheme]);

    $html->assertSee('<link rel="dns-prefetch" href="https://cdn.discordapp.com">', false)
        ->assertSee('<link rel="preconnect" href="https://cdn.discordapp.com">', false);
})->with([null, 'ledger', 'taste', 'hallmark']);
