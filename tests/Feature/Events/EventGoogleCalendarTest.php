<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Support\EventGoogleCalendar;
use App\Support\EventInput;

// The Google Calendar one-click add link on the shareable page: a template URL
// carrying title, UTC dates, details and location, next to the ICS download,
// so a member who lives in a browser gets a prefilled event in one click.

/** The page's Google link, failing loudly when it is missing. */
function googleLinkOnPage(string $html): string
{
    preg_match(
        '/<a href="([^"]+)"\s+data-testid="event-google-calendar"/s',
        $html,
        $matches
    );

    expect($matches[1] ?? null)->not->toBeNull('event page carries a Google Calendar link');

    return html_entity_decode($matches[1], ENT_QUOTES);
}

/** Query params of the Google template link as a decoded array. */
function googleParams(string $url): array
{
    expect($url)->toStartWith('https://calendar.google.com/calendar/render?action=TEMPLATE');

    $query = parse_url($url, PHP_URL_QUERY);

    expect($query)->not->toBeFalse();

    parse_str((string) $query, $params);

    return $params;
}

it('links a published event page to Google Calendar with title, UTC dates, details and location', function () {
    // Built through EventInput::instant like production does: 19:00 London in
    // July is 18:00Z, and the template link must carry the instant, not the
    // wall time — same contract as the ICS download.
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'description' => 'Bring stims.',
        'location' => 'Voice: General',
        'starts_at' => EventInput::instant('2026-07-15 19:00', 'Europe/London'),
        'ends_at' => EventInput::instant('2026-07-15 21:00', 'Europe/London'),
        'timezone' => 'Europe/London',
        'status' => EventStatus::Published,
    ]);

    $response = $this->get(route('events.page', $event))->assertOk();

    $response->assertSeeHtml('data-testid="event-calendar-links"')
        ->assertSeeHtml('data-testid="event-ics"')
        ->assertSeeHtml('data-testid="event-google-calendar"')
        ->assertSeeHtml('target="_blank"')
        ->assertSeeHtml('rel="noopener"');

    $params = googleParams(googleLinkOnPage($response->getContent()));

    expect($params)->toMatchArray([
        'action' => 'TEMPLATE',
        'text' => 'Friday night Helldivers',
        'dates' => '20260715T180000Z/20260715T200000Z',
        'details' => 'Bring stims.',
        'location' => 'Voice: General',
    ]);
});

it('encodes special characters so a forwarded link survives a second round of encoding', function () {
    $event = Event::factory()->create([
        'title' => 'Raid; night, part 1 & more',
        'description' => "Line one\nLine two",
        'location' => null,
        'status' => EventStatus::Published,
    ]);

    $url = googleLinkOnPage($this->get(route('events.page', $event))->assertOk()->getContent());

    // RFC3986: spaces as %20, not +. A literal + would turn into a space on a
    // second decode when a member forwards the link.
    expect($url)->toContain('Raid%3B%20night%2C%20part%201%20%26%20more')
        ->and($url)->not->toContain('+');

    $params = googleParams($url);

    expect($params['text'])->toBe('Raid; night, part 1 & more')
        ->and($params['details'])->toBe("Line one\nLine two")
        ->and($params)->not->toHaveKey('location');
});

it('omits details and location rather than emitting blanks when the event has none', function () {
    $event = Event::factory()->create([
        'description' => null,
        'location' => null,
        'status' => EventStatus::Published,
    ]);

    $params = googleParams(googleLinkOnPage($this->get(route('events.page', $event))->assertOk()->getContent()));

    expect($params)->not->toHaveKey('details')
        ->and($params)->not->toHaveKey('location')
        ->and($params)->toHaveKeys(['action', 'text', 'dates']);
});

it('builds the template URL from the same model without an HTTP round trip', function () {
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'status' => EventStatus::Published,
    ]);

    $params = googleParams(EventGoogleCalendar::url($event));

    expect($params['action'])->toBe('TEMPLATE')
        ->and($params['text'])->toBe('Friday night Helldivers')
        ->and($params['dates'])->toBe(
            $event->starts_at->setTimezone('UTC')->format('Ymd\THis\Z').'/'
            .$event->ends_at->setTimezone('UTC')->format('Ymd\THis\Z')
        );
});
