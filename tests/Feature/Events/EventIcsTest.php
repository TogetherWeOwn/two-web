<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\Support\EventIcs;
use App\Support\EventInput;

// The per-event calendar download: `GET /events/{event_key}.ics` as
// `text/calendar`, from the same Event model the JSON API reads. Public like
// the shareable page — a calendar client has no session — with the same `view`
// policy gating drafts.

beforeEach(function () {
    $this->moderator = User::factory()->create(['is_moderator' => true]);
});

/** Parse the folded VCALENDAR body back into unfolded `NAME:value` lines. */
function icsLines(string $body): array
{
    $unfolded = str_replace("\r\n ", '', $body);

    return explode("\r\n", trim($unfolded));
}

/** The single `NAME:value` line starting with $prefix, or null. */
function icsLine(array $lines, string $prefix): ?string
{
    foreach ($lines as $line) {
        if (str_starts_with($line, $prefix)) {
            return $line;
        }
    }

    return null;
}

it('serves a published event as text/calendar with the right DTSTART, DTEND and UID', function () {
    // Built through EventInput::instant like production does, not as a naive
    // factory string: the factory string would be parsed as UTC (APP_TIMEZONE)
    // while the `timezone` column claims London, and the download must carry
    // the instant the host actually meant.
    $event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'description' => 'Bring stims.',
        'location' => 'Voice: General',
        'starts_at' => EventInput::instant('2026-07-15 19:00', 'Europe/London'),
        'ends_at' => EventInput::instant('2026-07-15 21:00', 'Europe/London'),
        'timezone' => 'Europe/London',
        'status' => EventStatus::Published,
    ]);

    $response = $this->get(route('events.ics', $event))->assertOk();

    // Exact match on the media type, not `assertHeader('Content-Type', ...)`:
    // Laravel appends `; charset=UTF-8` and a plain equality check would fail on
    // the thing every correct response carries.
    expect($response->headers->get('Content-Type'))->toStartWith('text/calendar');

    $lines = icsLines($response->getContent());

    // 19:00 London in July is 18:00Z: the download carries the instant, not the
    // wall time — a zoned or floating time would reintroduce the DST ambiguity
    // the `timezone` column exists to kill.
    expect($lines)->toContain('BEGIN:VCALENDAR')
        ->and($lines)->toContain('END:VCALENDAR')
        ->and(icsLine($lines, 'UID:'))->toBe('UID:'.$event->event_key.'@localhost')
        ->and(icsLine($lines, 'DTSTART:'))->toBe('DTSTART:20260715T180000Z')
        ->and(icsLine($lines, 'DTEND:'))->toBe('DTEND:20260715T200000Z')
        ->and(icsLine($lines, 'SUMMARY:'))->toBe('SUMMARY:Friday night Helldivers')
        ->and(icsLine($lines, 'STATUS:'))->toBe('STATUS:CONFIRMED')
        ->and(icsLine($lines, 'DESCRIPTION:'))->toBe('DESCRIPTION:Bring stims.')
        ->and(icsLine($lines, 'LOCATION:'))->toBe('LOCATION:Voice: General');
});

it('keeps the UID stable across downloads so calendars update instead of duplicating', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $first = icsLine(icsLines($this->get(route('events.ics', $event))->assertOk()->getContent()), 'UID:');
    $second = icsLine(icsLines($this->get(route('events.ics', $event))->assertOk()->getContent()), 'UID:');

    expect($first)->not->toBeNull()->and($first)->toBe($second);
});

it('maps a cancelled event to STATUS:CANCELLED', function () {
    $event = Event::factory()->create(['status' => EventStatus::Cancelled]);

    $lines = icsLines($this->get(route('events.ics', $event))->assertOk()->getContent());

    expect(icsLine($lines, 'STATUS:'))->toBe('STATUS:CANCELLED');
});

it('returns 404 for an unknown event key', function () {
    $this->get('/events/no-such-event.ics')->assertNotFound();
});

it('hides a draft from guests and members but serves it to moderators', function () {
    $draft = Event::factory()->create(['status' => EventStatus::Draft]);
    $member = User::factory()->create(['is_moderator' => false]);

    $this->get(route('events.ics', $draft))->assertForbidden();
    $this->actingAs($member)->get(route('events.ics', $draft))->assertForbidden();
    $this->actingAs($this->moderator)->get(route('events.ics', $draft))->assertOk();
});

it('escapes commas, semicolons and newlines in text fields', function () {
    $event = Event::factory()->create([
        'title' => 'Raid; night, part 1',
        'description' => "Line one\nLine two",
        'status' => EventStatus::Published,
    ]);

    $lines = icsLines($this->get(route('events.ics', $event))->assertOk()->getContent());

    expect(icsLine($lines, 'SUMMARY:'))->toBe('SUMMARY:Raid\; night\, part 1')
        ->and(icsLine($lines, 'DESCRIPTION:'))->toBe('DESCRIPTION:Line one\nLine two');
});

it('builds the download from the same model without an HTTP round trip', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $lines = icsLines(EventIcs::for($event));

    expect($lines)->toContain('BEGIN:VCALENDAR')
        ->and(icsLine($lines, 'UID:'))->toBe('UID:'.$event->event_key.'@localhost');
});
