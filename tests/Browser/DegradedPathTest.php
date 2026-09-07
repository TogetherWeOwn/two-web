<?php

use Laravel\Dusk\Browser;

/*
 * The browser-facing degraded journey. The Dusk server starts from .env.example,
 * where the bot database is deliberately unconfigured, so this is a real failed
 * connection rather than a fake returned inside the test process.
 *
 * Feature tests own the exact exception and logging contracts. This owns the part
 * only a browser can prove: the failure does not replace the page with an error,
 * the funnel still has a visible working door, and unavailable counts are not
 * painted as zero.
 */
test('the site keeps its join path when the bot is unreachable', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/')
            ->assertSeeIn('h1', 'SINCE 1998.')
            ->assertVisible('[data-testid="discord-join"]')
            ->assertAttribute('[data-testid="discord-join"]', 'href', url('/join'))
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('could not connect')
            ->assertDontSee('0 members')
            ->click('[data-testid="discord-join"]')
            ->assertPathIs('/join')
            ->assertVisible('[data-testid="one-click-join"]')
            ->assertVisible('[data-testid="invite-link"]');
    });
});
