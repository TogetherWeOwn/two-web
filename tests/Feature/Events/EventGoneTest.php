<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

// Cancelled is gone, not missing (TOG-6781). A share link passed around Discord
// must keep answering after the cancellation so crawlers and members can tell
// "called off" apart from "never existed": 410 on the shareable page and the
// JSON show, with a body that says so. Drafts stay 200-for-moderators but carry
// `noindex` so a preview URL never enters the index; published pages send no
// robots signal at all.

beforeEach(function () {
    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->moderator = User::factory()->create(['is_moderator' => true]);
});

function goneEvent(array $overrides = []): Event
{
    return Event::factory()->create(array_merge([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
    ], $overrides));
}

it('answers a cancelled event page with 410 and says so in words', function () {
    $event = goneEvent(['status' => EventStatus::Cancelled]);

    $response = $this->get(route('events.page', $event));

    $response->assertStatus(410)
        ->assertSee($event->title)
        ->assertSee('This event was cancelled.', false)
        ->assertSeeHtml('data-testid="event-gone"');

    // The machine-readable status travels on the 410 page (TOG-6941's
    // EventCancelled mapping survives the gone rewrite): same block the live
    // page carries, so crawlers read the cancellation off the gone URL.
    preg_match(
        '/<script type="application\/ld\+json" data-testid="event-jsonld">(.*?)<\/script>/s',
        $response->getContent(),
        $matches
    );

    expect($matches[1] ?? null)->not->toBeNull('gone page carries a JSON-LD block')
        ->and(json_decode(trim($matches[1]), true)['eventStatus'])
        ->toBe('https://schema.org/EventCancelled');
});

it('keeps an unknown event key a 404, not a 410', function () {
    // The whole point of 410 is the distinction: unknown stays 404 so a
    // crawler drops the link instead of treating it as a called-off event.
    $this->get('/e/no-such-event')->assertNotFound();
});

it('answers a cancelled event on the JSON show with 410 and a reason', function () {
    $event = goneEvent(['status' => EventStatus::Cancelled]);

    $this->actingAs($this->member)
        ->getJson(route('events.show', $event))
        ->assertStatus(410)
        ->assertJsonPath('reason', 'event_cancelled')
        ->assertJsonPath('event_key', $event->event_key)
        ->assertJsonPath('status', EventStatus::Cancelled->value);
});

it('sends noindex for a draft on the page and the JSON show, and only there', function () {
    $draft = goneEvent(['status' => EventStatus::Draft]);

    $this->actingAs($this->moderator)
        ->get(route('events.page', $draft))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $this->actingAs($this->moderator)
        ->getJson(route('events.show', $draft))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('leaves published and past pages without any robots signal', function () {
    $published = goneEvent(['status' => EventStatus::Published]);
    $past = goneEvent(['status' => EventStatus::Past]);

    foreach ([$published, $past] as $event) {
        $page = $this->get(route('events.page', $event))->assertOk();
        expect($page->headers->has('X-Robots-Tag'))->toBeFalse()
            ->and($page->getContent())->not->toContain('name="robots"');

        $json = $this->actingAs($this->member)
            ->getJson(route('events.show', $event))->assertOk();
        expect($json->headers->has('X-Robots-Tag'))->toBeFalse();
    }
});
