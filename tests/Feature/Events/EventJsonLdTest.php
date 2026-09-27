<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Support\EventJsonLd;

// schema.org `Event` JSON-LD on the shareable page: one `application/ld+json`
// block per page carrying name, startDate, location and status, so crawlers
// see the event even though the page is public HTML for Discord links.

/** Decode the page's JSON-LD block, failing loudly when it is missing. */
function jsonLdOnPage(string $html): array
{
    preg_match(
        '/<script type="application\/ld\+json" data-testid="event-jsonld">(.*?)<\/script>/s',
        $html,
        $matches
    );

    expect($matches[1] ?? null)->not->toBeNull('event page carries a JSON-LD block');

    $decoded = json_decode(trim($matches[1]), true);

    expect(json_last_error_msg())->toBe('No error');

    return $decoded;
}

it('embeds valid Event JSON-LD with name, startDate and location on a published event page', function () {
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'description' => 'Bring a friend, bring spare ammo.',
        'location' => 'Voice: General',
        'status' => EventStatus::Published,
    ]);

    $response = $this->get(route('events.page', $event))->assertOk();

    $data = jsonLdOnPage($response->getContent());

    expect($data)->toMatchArray([
        '@context' => 'https://schema.org',
        '@type' => 'Event',
        'name' => 'Friday night Helldivers',
        'description' => 'Bring a friend, bring spare ammo.',
        'startDate' => $event->starts_at->toIso8601String(),
        'endDate' => $event->ends_at->toIso8601String(),
        'url' => route('events.page', $event),
        'eventStatus' => 'https://schema.org/EventScheduled',
    ])->and($data['location'])->toBe([
        '@type' => 'Place',
        'name' => 'Voice: General',
    ]);
});

it('maps a cancelled event to EventCancelled', function () {
    $event = Event::factory()->create(['status' => EventStatus::Cancelled]);

    $data = jsonLdOnPage($this->get(route('events.page', $event))->assertOk()->getContent());

    expect($data['eventStatus'])->toBe('https://schema.org/EventCancelled');
});

it('omits location rather than emitting null when the event has none', function () {
    $event = Event::factory()->create([
        'location' => null,
        'status' => EventStatus::Published,
    ]);

    $data = jsonLdOnPage($this->get(route('events.page', $event))->assertOk()->getContent());

    expect($data)->not->toHaveKey('location');
});

it('keeps a hostile title inside the JSON-LD block instead of breaking out of the script tag', function () {
    // Titles are free text, so a literal `</script>` in the JSON would close
    // this block and let the rest parse as HTML. `<`, `>`, `&` and quotes go
    // out hex-escaped (`<` etc.), which is still valid JSON-LD: the raw
    // block holds no literal closing tag and decodes back to the exact title.
    $title = 'Raid night </script><script>alert(1)</script>';
    $event = Event::factory()->create([
        'title' => $title,
        'status' => EventStatus::Published,
    ]);

    $html = $this->get(route('events.page', $event))->assertOk()->getContent();

    preg_match(
        '/<script type="application\/ld\+json" data-testid="event-jsonld">(.*?)<\/script>/s',
        $html,
        $matches
    );

    expect($matches[1] ?? null)->not->toBeNull()
        ->and($matches[1])->not->toContain('</script>')
        ->and(jsonLdOnPage($html)['name'])->toBe($title);
});

it('builds the JSON-LD from the same model without an HTTP round trip', function () {
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'status' => EventStatus::Published,
    ]);

    $data = EventJsonLd::for($event);

    expect($data['@type'])->toBe('Event')
        ->and($data['name'])->toBe('Friday night Helldivers')
        ->and($data['startDate'])->toBe($event->starts_at->toIso8601String());
});
