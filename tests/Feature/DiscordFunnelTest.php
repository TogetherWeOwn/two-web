<?php

use App\Http\Controllers\DiscordInviteController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
 * `/discord` is the only web-to-Discord conversion path TWO has and the only
 * link the live homepage carries (TOG-77, docs/dns.md). The rule these tests
 * exist to keep is absolute: **a member who clicked it always ends up in
 * Discord** — when the config is wrong, when the database is down, when the bot
 * is gone, and while the site is mid-deploy.
 *
 * So most of what is below is the failure path. The happy path is one line.
 */

it('sends a visitor to the configured Discord invite', function () {
    config()->set('services.discord.invite_url', 'https://discord.gg/abcd1234');

    $this->get('/discord')
        ->assertRedirect('https://discord.gg/abcd1234');
});

it('redirects temporarily, so the door can be moved', function () {
    // 302, never 301. TOG-80 replaces the destination with the one-click
    // `guilds.join` flow, and a 301 would already be cached in the browser of
    // every member who had used the link — the one population we cannot reach.
    $this->get('/discord')->assertStatus(302);
});

it('does not let anything cache the join link', function () {
    // The break-glass in docs/dns.md is "retarget the link in a minute". That is
    // only true if nothing between us and the member is holding an old copy.
    $response = $this->get('/discord');

    expect($response->headers->get('Cache-Control'))
        ->toContain('no-store');
});

it('falls back to the hardcoded invite when the configured one is missing', function () {
    Log::spy();

    config()->set('services.discord.invite_url', null);

    $this->get('/discord')
        ->assertRedirect(DiscordInviteController::FALLBACK_INVITE);

    // A misconfigured funnel is invisible from the outside — the member is
    // redirected either way — so the log line is the only thing that tells us.
    Log::shouldHaveReceived('error')->once();
});

it('falls back rather than redirecting anywhere that is not Discord', function () {
    // The open-redirect guard. `/discord` is the most trusted link we own and it
    // ends up in stream titles and DMs; one wrong DISCORD_INVITE_URL must not
    // turn it into a way of sending our members to someone else's site.
    Log::spy();

    foreach ([
        'https://evil.example.com/discord.gg',
        'https://discord.gg.evil.example.com/x',   // suffix, not the host
        'http://discord.gg/plaintext',             // no downgrade
        'javascript:alert(1)',
        '/discord',                                // relative, would loop
        '',
    ] as $hostile) {
        config()->set('services.discord.invite_url', $hostile);

        $this->get('/discord')
            ->assertRedirect(DiscordInviteController::FALLBACK_INVITE);
    }
});

it('falls back when the configured invite is not even a string', function () {
    Log::spy();

    config()->set('services.discord.invite_url', ['https://discord.gg/abcd1234']);

    $this->get('/discord')
        ->assertRedirect(DiscordInviteController::FALLBACK_INVITE);
});

it('keeps the config default and the hardcoded fallback identical', function () {
    // They are deliberately written in two places — config is the knob, the
    // constant is what answers when reading the knob fails. This is the check
    // that stops a rotation landing in one of them and not the other, which
    // would leave the fallback pointing at a revoked code and nobody knowing
    // until the day it was needed.
    expect(config('services.discord.invite_url'))
        ->toBe(DiscordInviteController::FALLBACK_INVITE);
})->skip(
    fn () => env('DISCORD_INVITE_URL') !== null && env('DISCORD_INVITE_URL') !== '',
    'DISCORD_INVITE_URL is set in this environment, so config is not showing its default.',
);

it('touches no database at all, even with a database-backed session', function () {
    // The reason routes/funnel.php exists. SESSION_DRIVER=database in every
    // environment we ship, so a route inside the `web` middleware group queries
    // Postgres in StartSession before the controller is reached — and the day
    // Postgres is down is the day the join link 500s.
    //
    // phpunit.xml runs the suite with an array session, which would hide that.
    // Configure the production driver first, then count.
    config()->set('session.driver', 'database');
    config()->set('cache.default', 'database');

    $this->expectsDatabaseQueryCount(0);

    $this->get('/discord')->assertStatus(302);
});

it('carries no session, cookie or throttle middleware on the invite fallback', function () {
    // The structural half of the test above: query counting proves today's code
    // is clean, this proves `/discord` was not quietly moved back into a group
    // that would make it dirty again. One-click `/join` deliberately needs OAuth
    // state and therefore belongs in the web group.
    $route = Route::getRoutes()->getByName('discord');

    expect($route)->not->toBeNull('Route [discord] is missing. It is the funnel floor — it must exist.');
    expect($route->gatherMiddleware())->toBe([], 'Route [discord] has picked up middleware.');
});

it('keeps the invite fallback answering while the site is in maintenance mode', function () {
    $this->artisan('down')->assertSuccessful();

    try {
        $this->get('/discord')->assertStatus(302);
        $this->get('/join')->assertStatus(503);
    } finally {
        $this->artisan('up')->assertSuccessful();
    }
});

it('offers the one-click join journey on the homepage', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('data-testid="discord-join"', escape: false)
        ->assertSee(route('join'), escape: false);
});
