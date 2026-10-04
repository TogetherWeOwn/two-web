<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Support\EventRss;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-07-15 21:00:00', 'UTC'));
});

afterEach(function () {
    $this->travelBack();
});

it('keeps an empty RSS representation stable across clock advances without writes', function (string $scenario) {
    $url = route('events.rss');

    if ($scenario === 'excluded rows') {
        Event::factory()->create(['status' => EventStatus::Draft]);
        Event::factory()->create([
            'status' => EventStatus::Published,
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->subHour(),
        ]);
    }

    if ($scenario === 'last event expired') {
        $event = Event::factory()->create([
            'status' => EventStatus::Published,
            'starts_at' => now()->subHours(2),
            'ends_at' => now(),
        ]);
        $populated = $this->get($url)->assertOk();
        expect((string) $populated->getContent())->toContain('<item>', $event->title);

        $this->travel(1)->seconds();
        $empty = $this->get($url, ['If-None-Match' => $populated->headers->get('ETag')])->assertOk();
    } else {
        $empty = $this->get($url)->assertOk();
    }

    $body = (string) $empty->getContent();
    $etag = $empty->headers->get('ETag');
    $rows = Event::all()->map->getRawOriginal()->all();
    $feed = simplexml_load_string($body);

    expect($feed)->not->toBeFalse();
    expect($feed->channel->item)->toHaveCount(0)
        ->and(isset($feed->channel->lastBuildDate))->toBeFalse()
        ->and($etag)->toBe('"'.hash('sha256', $body).'"');

    foreach ([1, 86400] as $seconds) {
        $this->travel($seconds)->seconds();
        $later = $this->get($url)->assertOk();

        expect((string) $later->getContent())->toBe($body)
            ->and($later->headers->get('ETag'))->toBe($etag);

        $this->get($url, ['If-None-Match' => $etag])
            ->assertNoContent(304)
            ->assertHeader('ETag', $etag);
    }

    expect(Event::all()->map->getRawOriginal()->all())->toBe($rows);
})->with(['no rows', 'excluded rows', 'last event expired']);

it('invalidates the empty RSS validator when an upcoming event is published', function () {
    $url = route('events.rss');
    $empty = $this->get($url)->assertOk();
    $etag = $empty->headers->get('ETag');

    $this->travel(1)->days();
    $event = Event::factory()->create(['status' => EventStatus::Draft]);
    $this->get($url, ['If-None-Match' => $etag])->assertNoContent(304);

    $event->update(['status' => EventStatus::Published]);
    $published = $this->get($url, ['If-None-Match' => $etag])->assertOk();
    $body = (string) $published->getContent();
    $publishedEtag = $published->headers->get('ETag');

    expect($body)->toContain('<item>', $event->title)
        ->not->toBe((string) $empty->getContent())
        ->and($publishedEtag)->not->toBe($etag)
        ->toBe('"'.hash('sha256', $body).'"');
    expect((string) simplexml_load_string($body)->channel->lastBuildDate)
        ->toBe($event->refresh()->updated_at->format(DATE_RSS));

    $this->travel(1)->hours();
    expect((string) $this->get($url)->assertOk()->getContent())->toBe($body);
    $this->get($url, ['If-None-Match' => $publishedEtag])
        ->assertNoContent(304)
        ->assertHeader('ETag', $publishedEtag);
});

it('keeps an empty iterable RSS builder stable without a content timestamp', function () {
    $empty = fn () => yield from [];
    $body = EventRss::for($empty());

    $this->travel(1)->days();

    expect(EventRss::for($empty()))->toBe($body)
        ->not->toContain('<lastBuildDate>');
});
