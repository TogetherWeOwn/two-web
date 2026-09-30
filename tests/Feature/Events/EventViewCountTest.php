<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

// First-party view counting (TOG-8408): the shareable page counts its own
// audience behind route middleware, without third-party analytics. One human
// page view is one increment on today's narrow row; bots never reach the
// write; the lifetime total is a SUM moderators can see.

const VIEW_COUNT_BROWSER_UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

beforeEach(function () {
    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->moderator = User::factory()->create(['is_moderator' => true]);
});

function viewCountEvent(array $overrides = []): Event
{
    return Event::factory()->create(array_merge([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ], $overrides));
}

it('counts ten guest views once each', function () {
    $event = viewCountEvent();

    expect($event->viewCount())->toBe(0);

    // Guests, no session: the Discord-link audience this exists for.
    for ($i = 0; $i < 10; $i++) {
        $this->get(route('events.page', $event), ['User-Agent' => VIEW_COUNT_BROWSER_UA])->assertOk();
    }

    expect($event->refresh()->viewCount())->toBe(10)
        // Pre-aggregated write, not a row per visitor: one narrow row holds
        // all ten of today's views.
        ->and($event->viewCounts()->count())->toBe(1)
        ->and((int) $event->viewCounts()->first()->views)->toBe(10);
});

it('excludes bot and crawler user-agents', function () {
    $event = viewCountEvent();

    $bots = [
        'Googlebot/2.1 (+http://www.google.com/bot.html)',
        'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)',
        'facebookexternalhit/1.1',
        'curl/8.5.0',
        'python-requests/2.31.0',
    ];

    foreach ($bots as $bot) {
        $this->get(route('events.page', $event), ['User-Agent' => $bot])->assertOk();
    }

    expect($event->refresh()->viewCount())->toBe(0);

    // ...and the filter is not a blanket block: a person still counts after
    // the crawlers have been through.
    $this->get(route('events.page', $event), ['User-Agent' => VIEW_COUNT_BROWSER_UA])->assertOk();

    expect($event->refresh()->viewCount())->toBe(1);
});

it('shows the lifetime total to moderators', function () {
    $event = viewCountEvent();

    for ($i = 0; $i < 3; $i++) {
        $this->get(route('events.page', $event), ['User-Agent' => VIEW_COUNT_BROWSER_UA])->assertOk();
    }

    // The badge shows the count as loaded for this render — the middleware
    // increments after the response, so the moderator's own view lands after
    // the number they see (3, not 4).
    $this->actingAs($this->moderator)
        ->get(route('events.page', $event), ['User-Agent' => VIEW_COUNT_BROWSER_UA])
        ->assertOk()
        ->assertSeeHtml('data-testid="event-view-count"')
        ->assertSee('3 views', false);
});

it('hides the view total from guests and members', function () {
    $event = viewCountEvent();

    $this->get(route('events.page', $event), ['User-Agent' => VIEW_COUNT_BROWSER_UA])
        ->assertOk()
        ->assertDontSeeHtml('data-testid="event-view-count"');

    $this->actingAs($this->member)
        ->get(route('events.page', $event), ['User-Agent' => VIEW_COUNT_BROWSER_UA])
        ->assertOk()
        ->assertDontSeeHtml('data-testid="event-view-count"');
});

it('does not count pages that never rendered', function () {
    $cancelled = viewCountEvent(['status' => EventStatus::Cancelled]);
    $draft = viewCountEvent(['status' => EventStatus::Draft]);

    $this->get(route('events.page', $cancelled), ['User-Agent' => VIEW_COUNT_BROWSER_UA])->assertStatus(410);
    $this->get(route('events.page', $draft), ['User-Agent' => VIEW_COUNT_BROWSER_UA])->assertForbidden();
    $this->get('/e/no-such-event', ['User-Agent' => VIEW_COUNT_BROWSER_UA])->assertNotFound();

    expect($cancelled->refresh()->viewCount())->toBe(0)
        ->and($draft->refresh()->viewCount())->toBe(0);
});

it('sums daily rows into the lifetime total', function () {
    $event = viewCountEvent();
    $event->viewCounts()->create(['viewed_on' => today()->subDay()->toDateString(), 'views' => 7]);

    $this->get(route('events.page', $event), ['User-Agent' => VIEW_COUNT_BROWSER_UA])->assertOk();

    // Yesterday's 7 plus today's 1: the "daily rollup" is the write pattern
    // (one row per day), and the total is the SUM over those rows.
    expect($event->refresh()->viewCount())->toBe(8);
});
