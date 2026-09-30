<?php

use App\Enums\EventStatus;
use App\Models\Event;
use Carbon\CarbonImmutable;

afterEach(function () {
    $this->travelBack();
});

it('invalidates the collection validator when an event expires without a write', function (string $routeName, string $itemMarker) {
    $endsAt = CarbonImmutable::parse('2027-07-15 21:00:00', 'UTC');
    $this->travelTo($endsAt);

    $event = Event::factory()->create([
        'title' => 'Clock-only expiry sentinel',
        'status' => EventStatus::Published,
        'starts_at' => $endsAt->subHours(2),
        'ends_at' => $endsAt,
        'timezone' => 'UTC',
    ]);
    $original = $event->refresh()->getRawOriginal();
    $url = route($routeName);

    // Equality is still upcoming: the first body must contain the event.
    $first = $this->get($url)->assertOk();
    $body = (string) $first->getContent();
    $etag = $first->headers->get('ETag');

    expect($body)->toContain($itemMarker, $event->title)
        ->and($etag)->toBe('"'.hash('sha256', $body).'"');

    $this->get($url, ['If-None-Match' => $etag])
        ->assertNoContent(304)
        ->assertHeader('ETag', $etag);

    // Only the request clock moves; no status edit or reconciliation runs.
    $this->travelTo($endsAt->addSecond());

    $expired = $this->get($url, ['If-None-Match' => $etag])->assertOk();
    $expiredBody = (string) $expired->getContent();
    $expiredEtag = $expired->headers->get('ETag');

    expect($expiredBody)->not->toBe($body)
        ->not->toContain($itemMarker)
        ->not->toContain($event->title)
        ->and($expiredEtag)->not->toBe($etag)
        ->toBe('"'.hash('sha256', $expiredBody).'"');

    $this->get($url, ['If-None-Match' => $expiredEtag])
        ->assertNoContent(304)
        ->assertHeader('ETag', $expiredEtag);

    expect($event->refresh()->getRawOriginal())->toBe($original);
})->with([
    'RSS' => ['events.rss', '<item>'],
    'ICS' => ['events.feed', 'BEGIN:VEVENT'],
]);
