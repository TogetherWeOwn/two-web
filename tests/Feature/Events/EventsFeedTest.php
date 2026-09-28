<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Support\EventIcs;
use App\Support\EventInput;
use App\Support\EventSubscribe;

// The collection as a subscribable calendar: `GET /events.ics` as
// `text/calendar`, plus the one-click `webcal://` subscribe link on the events
// page. Public like the shareable page — a calendar client has no session —
// carrying published upcoming events, so a member's calendar stays current
// without re-downloading.

beforeEach(function () {
    $this->moderator = User::factory()->create(['is_moderator' => true]);
});

/** The page's subscribe href, failing loudly when the button is missing. */
function subscribeHref(string $html): string
{
    preg_match(
        '/<a href="([^"]+)"\s+data-testid="events-subscribe"/s',
        $html,
        $matches
    );

    expect($matches[1] ?? null)->not->toBeNull('events page carries a subscribe link');

    return html_entity_decode($matches[1], ENT_QUOTES);
}

it('serves the upcoming collection as one VCALENDAR with a VEVENT per event', function () {
    // Built through EventInput::instant like production does: 19:00 London in
    // July is 18:00Z, and the feed must carry the instant, not the wall time —
    // same contract as the per-event download.
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'description' => 'Bring stims.',
        'location' => 'Voice: General',
        'starts_at' => EventInput::instant('2027-07-15 19:00', 'Europe/London'),
        'ends_at' => EventInput::instant('2027-07-15 21:00', 'Europe/London'),
        'timezone' => 'Europe/London',
        'status' => EventStatus::Published,
    ]);

    $response = $this->get(route('events.feed'))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/calendar');

    $body = $response->getContent();
    $unfolded = str_replace("\r\n ", '', $body);
    $lines = explode("\r\n", trim($unfolded));

    expect($lines)->toContain('BEGIN:VCALENDAR')
        ->and($lines)->toContain('END:VCALENDAR')
        ->and($lines)->toContain('UID:'.$event->event_key.'@localhost')
        ->and($lines)->toContain('DTSTART:20270715T180000Z')
        ->and($lines)->toContain('SUMMARY:Friday night Helldivers')
        // The feed shares the single-download's per-event contract, including
        // the 30-minute reminder — a subscription without alarms silently
        // downgrades every import, and the merge that introduced both must not
        // be allowed to drop one side.
        ->and($lines)->toContain('BEGIN:VALARM')
        ->and($lines)->toContain('TRIGGER:-PT30M')
        ->and($lines)->toContain('END:VALARM')
        ->and(substr_count($body, 'BEGIN:VEVENT'))->toBe(1);
});

it('keeps cancelled events as STATUS:CANCELLED but hides drafts and past events', function () {
    $cancelled = Event::factory()->create([
        'status' => EventStatus::Cancelled,
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addWeek()->addHours(2),
    ]);
    Event::factory()->create(['status' => EventStatus::Draft]);
    Event::factory()->create([
        'status' => EventStatus::Published,
        'starts_at' => now()->subHours(3),
        'ends_at' => now()->subHour(),
    ]);
    $upcoming = Event::factory()->create(['status' => EventStatus::Published]);

    // The reader is always a guest, so the moderator sees the same body: the
    // guest rule is the only honest one for a sessionless fetch.
    $guestBody = $this->get(route('events.feed'))->assertOk()->getContent();
    $moderatorBody = $this->actingAs($this->moderator)->get(route('events.feed'))->assertOk()->getContent();

    expect($guestBody)->toBe($moderatorBody)
        ->and($guestBody)->toContain('UID:'.$cancelled->event_key.'@localhost')
        ->and($guestBody)->toContain('STATUS:CANCELLED')
        ->and($guestBody)->toContain('UID:'.$upcoming->event_key.'@localhost')
        ->and(substr_count($guestBody, 'BEGIN:VEVENT'))->toBe(2);
});

it('serves a valid empty VCALENDAR when nothing is upcoming', function () {
    Event::factory()->create(['status' => EventStatus::Draft]);

    $body = $this->get(route('events.feed'))->assertOk()->getContent();
    $unfolded = str_replace("\r\n ", '', $body);
    $lines = explode("\r\n", trim($unfolded));

    expect($lines)->toContain('BEGIN:VCALENDAR')
        ->and($lines)->toContain('END:VCALENDAR')
        ->and($lines)->not->toContain('BEGIN:VEVENT');
});

it('serves the feed inline so a click subscribes instead of downloading', function () {
    $response = $this->get(route('events.feed'))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('inline;');
});

it('links the events page subscribe button to the webcal form of the feed', function () {
    Event::factory()->create(['status' => EventStatus::Published]);

    $html = $this->get(route('events.index'))->assertOk()->getContent();

    $href = subscribeHref($html);

    // The webcal URL is the feed URL with the scheme swapped: one feed, two
    // names, so the button can never drift from what the route serves.
    expect($href)->toBe(EventSubscribe::webcalUrl())
        ->and($href)->toStartWith('webcal://')
        ->and($href)->toEndWith('/events.ics')
        ->and(EventSubscribe::feedUrl())->toBe(route('events.feed'));
});

it('builds the collection from already-fetched models without an HTTP round trip', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $body = EventIcs::collection(Event::all());

    expect($body)->toContain('BEGIN:VCALENDAR')
        ->and($body)->toContain('UID:'.$event->event_key.'@localhost');
});
