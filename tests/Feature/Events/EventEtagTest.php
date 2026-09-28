<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

// Conditional requests on the polled surfaces: `GET /events.json`, `GET
// /events.rss`, `GET /events.ics` and the `GET /events/{event_key}.ics`
// download all send a strong ETag over the exact bytes going out, and a repeat
// poll carrying `If-None-Match` answers 304 with no body instead of the full
// listing. (TOG-7330, TOG-8865)

beforeEach(function () {
    $this->member = User::factory()->create(['is_moderator' => false]);
});

it('answers a conditional events.json poll with 304 and no body', function () {
    Event::factory()->create(['status' => EventStatus::Published]);

    $first = $this->actingAs($this->member)->getJson(route('events.json'))->assertOk();

    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeNull()->and($etag)->not->toBe('');

    // The second poll carries the validator back: nothing changed, so no body.
    $this->actingAs($this->member)
        ->getJson(route('events.json'), ['If-None-Match' => $etag])
        ->assertStatus(304)
        ->assertNoContent(304);
});

it('serves events.json again once an event changes', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $etag = $this->actingAs($this->member)
        ->getJson(route('events.json'))->assertOk()
        ->headers->get('ETag');

    // A retitled event is different bytes: the old validator must not match.
    $event->update(['title' => 'A completely different game night']);

    $second = $this->actingAs($this->member)
        ->getJson(route('events.json'), ['If-None-Match' => $etag])
        ->assertOk();

    expect($second->headers->get('ETag'))->not->toBe($etag);
});

it('scopes the events.json validator to the viewer and the page', function () {
    Event::factory()->create(['status' => EventStatus::Published]);
    Event::factory()->create(['status' => EventStatus::Draft]);

    $memberEtag = $this->actingAs($this->member)
        ->getJson(route('events.json'))->assertOk()
        ->headers->get('ETag');

    // A moderator sees the draft row too, so the same URL is different bytes
    // for them: the member's validator must not 304 a moderator's poll.
    $moderator = User::factory()->create(['is_moderator' => true]);

    $this->actingAs($moderator)
        ->getJson(route('events.json'), ['If-None-Match' => $memberEtag])
        ->assertOk();
});

it('answers a conditional events.rss poll with 304 and no body', function () {
    Event::factory()->create(['status' => EventStatus::Published]);

    $first = $this->get(route('events.rss'))->assertOk();

    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeNull()->and($etag)->not->toBe('');

    $this->get(route('events.rss'), ['If-None-Match' => $etag])
        ->assertStatus(304)
        ->assertNoContent(304);
});

it('renders the same RSS body twice with no intervening change', function () {
    Event::factory()->create(['status' => EventStatus::Published]);

    $first = $this->get(route('events.rss'))->assertOk()->getContent();
    $second = $this->get(route('events.rss'))->assertOk()->getContent();

    // `lastBuildDate` tracks the content clock (newest `updated_at`), not the
    // render clock: two renders of unchanged content must be byte-identical,
    // or no validator could ever match. This pins that instead of the ETag.
    expect($second)->toBe($first);
});

it('serves the feed again once an event in scope changes', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $etag = $this->get(route('events.rss'))->assertOk()->headers->get('ETag');

    $event->update(['title' => 'A completely different game night']);

    $this->get(route('events.rss'), ['If-None-Match' => $etag])->assertOk();
});

it('answers a conditional events.ics poll with 304 and no body', function () {
    Event::factory()->create(['status' => EventStatus::Published]);

    $first = $this->get(route('events.feed'))->assertOk();

    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeNull()->and($etag)->not->toBe('');

    $this->get(route('events.feed'), ['If-None-Match' => $etag])
        ->assertStatus(304)
        ->assertNoContent(304);
});

it('answers a conditional events.ics poll with 304 when the collection is empty', function () {
    // No published upcoming events: the body is still byte-stable (the
    // `updated_at` clock has nothing to stamp, so nothing drifts between
    // renders), and the ETag still 304s instead of re-sending the envelope.
    $first = $this->get(route('events.feed'))->assertOk();

    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeNull()->and($etag)->not->toBe('');

    $this->get(route('events.feed'), ['If-None-Match' => $etag])
        ->assertStatus(304)
        ->assertNoContent(304);
});

it('renders the same events.ics body twice with no intervening change', function () {
    Event::factory()->create(['status' => EventStatus::Published]);

    $first = $this->get(route('events.feed'))->assertOk()->getContent();
    $second = $this->get(route('events.feed'))->assertOk()->getContent();

    // `DTSTAMP` tracks the content clock (the row's `updated_at`), not the
    // render clock: two renders of unchanged content must be byte-identical,
    // or no validator could ever match. This pins that instead of the ETag.
    expect($second)->toBe($first);
});

it('serves events.ics again once an event in scope changes', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $etag = $this->get(route('events.feed'))->assertOk()->headers->get('ETag');

    // A retitled event re-renders different bytes: the old validator must not
    // match. Same for a newly published event joining the scope.
    $event->update(['title' => 'A completely different game night']);

    $second = $this->get(route('events.feed'), ['If-None-Match' => $etag])->assertOk();

    expect($second->headers->get('ETag'))->not->toBe($etag);
});

it('answers a conditional per-event ICS download with 304 and no body', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $first = $this->get(route('events.ics', $event))->assertOk();

    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeNull()->and($etag)->not->toBe('');

    $this->get(route('events.ics', $event), ['If-None-Match' => $etag])
        ->assertStatus(304)
        ->assertNoContent(304);
});

it('renders the same per-event ICS body twice with no intervening change', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $first = $this->get(route('events.ics', $event))->assertOk()->getContent();
    $second = $this->get(route('events.ics', $event))->assertOk()->getContent();

    expect($second)->toBe($first);
});

it('serves the per-event ICS download again once the event changes', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $etag = $this->get(route('events.ics', $event))->assertOk()->headers->get('ETag');

    $event->update(['title' => 'A completely different game night']);

    $second = $this->get(route('events.ics', $event), ['If-None-Match' => $etag])->assertOk();

    expect($second->headers->get('ETag'))->not->toBe($etag);
});
