<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Support\EventInput;
use App\Support\EventRss;

// The event collection as an RSS 2.0 feed: `GET /events.rss` as
// `application/rss+xml`. Public like the shareable page — a feed reader has no
// session — with drafts excluded outright, since per-user visibility cannot
// apply to a sessionless fetch.

beforeEach(function () {
    $this->moderator = User::factory()->create(['is_moderator' => true]);
});

/** Parse the feed body into a SimpleXMLElement, failing loudly on bad XML. */
function rssFeed(string $body): SimpleXMLElement
{
    $feed = simplexml_load_string($body);

    // A malformed feed must fail here, not three assertions later.
    expect($feed)->not->toBeFalse();

    return $feed;
}

it('serves published events as RSS with links to the shareable pages', function () {
    // Built through EventInput::instant like production does, not as a naive
    // factory string: the factory string would be parsed as UTC (APP_TIMEZONE)
    // while the `timezone` column claims London, and the feed must carry the
    // instant the host actually meant.
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'description' => 'Bring stims.',
        'starts_at' => EventInput::instant('2026-07-15 19:00', 'Europe/London'),
        'ends_at' => EventInput::instant('2026-07-15 21:00', 'Europe/London'),
        'timezone' => 'Europe/London',
        'status' => EventStatus::Published,
    ]);

    $response = $this->get(route('events.rss'))->assertOk();

    // Exact match on the media type, not `assertHeader('Content-Type', ...)`:
    // Laravel appends `; charset=UTF-8` and a plain equality check would fail
    // on the thing every correct response carries.
    expect($response->headers->get('Content-Type'))->toStartWith('application/rss+xml');

    $feed = rssFeed($response->getContent());

    expect((string) $feed->channel->title)->toContain('Events');

    $items = $feed->channel->item;
    expect($items)->toHaveCount(1);

    // 19:00 London in July is 18:00Z: the feed carries the instant, not the
    // wall time — a floating local time would reintroduce the DST ambiguity
    // the `timezone` column exists to kill.
    expect((string) $items[0]->title)->toBe('Friday night Helldivers')
        ->and((string) $items[0]->description)->toBe('Bring stims.')
        ->and((string) $items[0]->link)->toBe(route('events.page', $event))
        ->and((string) $items[0]->guid)->toBe(route('events.page', $event))
        ->and((string) $items[0]->pubDate)->toBe('Wed, 15 Jul 2026 18:00:00 +0000');
});

it('excludes drafts from the feed, even for moderators', function () {
    Event::factory()->create(['status' => EventStatus::Draft]);
    $published = Event::factory()->create(['status' => EventStatus::Published]);

    $guestItems = rssFeed($this->get(route('events.rss'))->assertOk()->getContent())->channel->item;
    $moderatorItems = rssFeed($this->actingAs($this->moderator)->get(route('events.rss'))->assertOk()->getContent())->channel->item;

    // The feed URL is fetched without a session, so per-user visibility cannot
    // apply: the guest rule is the only honest one, for everyone.
    expect($guestItems)->toHaveCount(1)
        ->and($moderatorItems)->toHaveCount(1)
        ->and((string) $guestItems[0]->guid)->toBe(route('events.page', $published));
});

it('marks cancelled events instead of silently listing them as upcoming', function () {
    $event = Event::factory()->create([
        'title' => 'Raid night',
        'status' => EventStatus::Cancelled,
    ]);

    $items = rssFeed($this->get(route('events.rss'))->assertOk()->getContent())->channel->item;

    expect($items)->toHaveCount(1)
        ->and((string) $items[0]->title)->toBe('[Cancelled] Raid night')
        ->and((string) $items[0]->guid)->toBe(route('events.page', $event));
});

it('orders items by start time and escapes markup in text fields', function () {
    Event::factory()->create([
        'title' => 'Later <b>event</b>',
        'starts_at' => now()->addWeeks(2),
        'ends_at' => now()->addWeeks(2)->addHours(2),
        'status' => EventStatus::Published,
    ]);
    Event::factory()->create([
        'title' => 'Sooner & friends',
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addHours(2),
        'status' => EventStatus::Published,
    ]);

    $items = rssFeed($this->get(route('events.rss'))->assertOk()->getContent())->channel->item;

    expect($items)->toHaveCount(2)
        ->and((string) $items[0]->title)->toBe('Sooner & friends')
        ->and((string) $items[1]->title)->toBe('Later <b>event</b>');
});

it('builds the feed from already-fetched models without an HTTP round trip', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $feed = rssFeed(EventRss::for(Event::all()));

    expect($feed->channel->item)->toHaveCount(1)
        ->and((string) $feed->channel->item[0]->guid)->toBe(route('events.page', $event));
});
