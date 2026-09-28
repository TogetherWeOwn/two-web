<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Support\EventInput;

// Consumer-shape checklist for `GET /events.rss`: what a calendar/bot consumer
// needs beyond "valid XML". Complements TOG-6790 (JSON contract, bot side) and
// the scope/validity pins in `EventRssTest` — here each row is one consumer
// promise: a guid that survives edits, a pubDate that parses as UTC on both
// sides of the DST boundary, no drafts or cancellations, and poller-friendly
// headers.

beforeEach(function () {
    $this->moderator = User::factory()->create(['is_moderator' => true]);
});

/** Parse the feed body into a SimpleXMLElement, failing loudly on bad XML. */
function rssConsumerFeed(string $body): SimpleXMLElement
{
    $feed = simplexml_load_string($body);

    // A malformed feed must fail here, not three assertions later.
    expect($feed)->not->toBeFalse();

    return $feed;
}

it('keeps the guid stable when the event is renamed', function () {
    // The bot keys its mirror on the guid. If a host rename changed it, the
    // bot would post a duplicate instead of updating the entry.
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $guidOf = fn () => (string) rssConsumerFeed($this->get(route('events.rss'))->assertOk()->getContent())->channel->item[0]->guid;

    $before = $guidOf();

    $event->update(['title' => 'Renamed after the bot stored the guid']);

    expect($guidOf())->toBe($before);
});

it('marks the guid as a permalink to the shareable page carrying the event key', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $item = rssConsumerFeed($this->get(route('events.rss'))->assertOk()->getContent())->channel->item[0];

    expect((string) $item->guid)->toBe((string) $item->link)
        // The key, never the autoincrement id: staging's row 7 and
        // production's row 7 are different events (see EventKeyTest).
        ->and((string) $item->guid)->toContain($event->event_key)
        ->and((string) $item->guid['isPermaLink'])->toBe('true');
});

it('emits pubDate as a UTC instant on both sides of the DST boundary', function () {
    Event::factory()->create([
        'title' => 'Summer raid',
        'starts_at' => EventInput::instant('2027-07-15 19:00', 'Europe/London'),
        'ends_at' => EventInput::instant('2027-07-15 21:00', 'Europe/London'),
        'timezone' => 'Europe/London',
        'status' => EventStatus::Published,
    ]);
    Event::factory()->create([
        'title' => 'Winter raid',
        'starts_at' => EventInput::instant('2027-01-15 19:00', 'Europe/London'),
        'ends_at' => EventInput::instant('2027-01-15 21:00', 'Europe/London'),
        'timezone' => 'Europe/London',
        'status' => EventStatus::Published,
    ]);

    $items = rssConsumerFeed($this->get(route('events.rss'))->assertOk()->getContent())->channel->item;

    // 19:00 London wall time is a different instant in January (GMT) than in
    // July (BST); the feed must carry the instant, not the wall time.
    expect($items)->toHaveCount(2)
        ->and((string) $items[0]->pubDate)->toBe('Fri, 15 Jan 2027 19:00:00 +0000')
        ->and((string) $items[1]->pubDate)->toBe('Thu, 15 Jul 2027 18:00:00 +0000');

    // And a bot must be able to parse each one as UTC, not just eyeball it.
    foreach ($items as $item) {
        $date = new DateTimeImmutable((string) $item->pubDate);

        expect($date->getOffset())->toBe(0);
    }
});

it('stamps a parseable UTC lastBuildDate on the channel', function () {
    Event::factory()->create(['status' => EventStatus::Published]);

    $feed = rssConsumerFeed($this->get(route('events.rss'))->assertOk()->getContent());

    $built = new DateTimeImmutable((string) $feed->channel->lastBuildDate);

    expect($built->getOffset())->toBe(0);
});

it('sends poller-friendly cache headers', function () {
    Event::factory()->create(['status' => EventStatus::Published]);

    $response = $this->get(route('events.rss'))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('public')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=300');
});

it('omits the description element when there is nothing to say', function () {
    $event = Event::factory()->create([
        'description' => null,
        'status' => EventStatus::Published,
    ]);

    $item = rssConsumerFeed($this->get(route('events.rss'))->assertOk()->getContent())->channel->item[0];

    expect(isset($item->description))->toBeFalse();

    $event->update(['description' => '']);

    $item = rssConsumerFeed($this->get(route('events.rss'))->assertOk()->getContent())->channel->item[0];

    expect(isset($item->description))->toBeFalse();
});

it('excludes cancelled upcoming events for every viewer, unlike the ICS feed', function () {
    // The ICS collection keeps cancelled rows as STATUS:CANCELLED so a synced
    // calendar retracts them; RSS has no such status, so a cancelled row must
    // vanish. A bot watching only RSS therefore never learns about
    // cancellations — that contrast is the point of this pin.
    Event::factory()->create([
        'title' => 'Called-off raid',
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addHours(2),
        'status' => EventStatus::Cancelled,
    ]);
    $upcoming = Event::factory()->create(['status' => EventStatus::Published]);

    $guestItems = rssConsumerFeed($this->get(route('events.rss'))->assertOk()->getContent())->channel->item;
    $moderatorItems = rssConsumerFeed($this->actingAs($this->moderator)->get(route('events.rss'))->assertOk()->getContent())->channel->item;

    expect($guestItems)->toHaveCount(1)
        ->and($moderatorItems)->toHaveCount(1)
        ->and((string) $guestItems[0]->guid)->toBe(route('events.page', $upcoming));
});

it('round-trips emoji and multibyte titles a bot would repost', function () {
    Event::factory()->create([
        'title' => 'Raid night 🛡️ — “final” push',
        'status' => EventStatus::Published,
    ]);

    $item = rssConsumerFeed($this->get(route('events.rss'))->assertOk()->getContent())->channel->item[0];

    expect((string) $item->title)->toBe('Raid night 🛡️ — “final” push');
});
