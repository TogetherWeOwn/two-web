<?php

use Laravel\Dusk\Browser;

/*
 * The join journey in a real browser: land on the homepage and find a working
 * way into Discord. This is journey #1 on TOG-55 and the one the whole site
 * exists to serve.
 *
 * Where this stops, and why. Dusk does not follow the link out to `discord.gg`,
 * for the same reason tests/Browser/DiscordLoginTest.php does not drive the
 * Discord consent screen: it would put a third party we do not control inside
 * the merge gate, and `dusk` is a required check — Discord having a slow
 * afternoon would mean nobody merges. See docs/flake-policy.md.
 *
 * So the split is: Dusk owns the page and the affordance on it,
 * tests/Feature/DiscordFunnelTest.php owns what `/discord` answers with —
 * including every failure path, which a browser could not exercise anyway — and
 * the `budgets` job's axe pass owns the accessibility of this page. Nothing here
 * can prove Discord still honours the invite; QA does that by hand on staging
 * once, and it is step 7 of the cutover checklist in docs/dns.md.
 */

test('a visitor is offered the way into Discord', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/')
            ->assertSee('Together We Own')
            ->assertSeeLink('Join the Discord')
            ->assertVisible('[data-testid="discord-join"]')
            // A real anchor with a real href — which is what makes it
            // keyboard-reachable, middle-clickable and copyable. A div with a
            // click handler passes "looks like a button" and fails all three.
            ->assertAttribute('[data-testid="discord-join"]', 'href', url('/discord'));
    });
});
