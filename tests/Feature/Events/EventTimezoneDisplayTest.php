<?php

use App\Enums\EventStatus;
use App\Livewire\EventsCalendar;
use App\Models\Event;
use App\Models\Profile;
use App\Models\User;
use App\Support\EventGoogleCalendar;
use App\Support\EventIcs;
use App\Support\EventInput;
use Livewire\Livewire;

// TOG-5619: EventTimezoneTest pins storage — the instant is right on both sides
// of a DST boundary. This file pins display: the wall clock a member reads on
// the shareable page, the JSON, the ICS download, the Google link and the
// month grid must all agree with the host's zone, on both sides of the clock
// change and across zones. A surface that reinterprets the instant in UTC (or
// in the viewer's zone) prints the wrong day or hour, and only for half the
// year — which is why every case below exists in a before/after pair.
//
// Helper names carry a `tzDisplay` prefix on purpose: Pest loads every Feature
// file into one process, and `publishedEvent()`, `upcomingEvent()` and friends
// are already taken by neighbouring files.

beforeEach(function () {
    $this->member = User::factory()->create(['is_moderator' => false]);
});

/**
 * An event at a fixed local wall clock in the given zone, built through
 * EventInput::instant like production does — a bare factory string would be
 * parsed as UTC while the `timezone` column claims otherwise.
 */
function tzDisplayEvent(string $localWallTime, string $zone): Event
{
    $start = EventInput::instant($localWallTime, $zone);

    return Event::factory()->create([
        'title' => 'Evening games',
        'starts_at' => $start,
        'ends_at' => $start->copy()->addHours(2),
        'timezone' => $zone,
        'status' => EventStatus::Published,
    ]);
}

/** Unfold an ICS body and return the value of the single `NAME:` line, or null. */
function tzIcsValue(string $body, string $name): ?string
{
    $lines = explode("\r\n", trim(str_replace("\r\n ", '', $body)));

    foreach ($lines as $line) {
        if (str_starts_with($line, $name.':')) {
            return substr($line, strlen($name) + 1);
        }
    }

    return null;
}

/** The `dates` param of the Google template link built for the event. */
function tzGoogleDates(Event $event): string
{
    parse_str((string) parse_url(EventGoogleCalendar::url($event), PHP_URL_QUERY), $params);

    return $params['dates'];
}

// Clocks forward 2026-03-29 01:00Z, back 2026-10-25 01:00Z. "20:00 London" is
// a different instant on each side, but the page must always read 20:00.

dataset('DST wall clock, both sides', [
    // [local wall time, visible date, machine instant, ICS instant]
    'spring, GMT side' => ['2026-03-28 20:00', 'Sat 28 Mar, 20:00', '2026-03-28T20:00:00+00:00', '20260328T200000Z', '20260328T220000Z'],
    'spring, BST side' => ['2026-03-30 20:00', 'Mon 30 Mar, 20:00', '2026-03-30T19:00:00+00:00', '20260330T190000Z', '20260330T210000Z'],
    'autumn, BST side' => ['2026-10-24 20:00', 'Sat 24 Oct, 20:00', '2026-10-24T19:00:00+00:00', '20261024T190000Z', '20261024T210000Z'],
    'autumn, GMT side' => ['2026-10-26 20:00', 'Mon 26 Oct, 20:00', '2026-10-26T20:00:00+00:00', '20261026T200000Z', '20261026T220000Z'],
]);

it('prints the host wall clock on the shareable page on both sides of a DST boundary', function (string $local, string $visible, string $instant) {
    $event = tzDisplayEvent($local, 'Europe/London');

    $this->get(route('events.page', $event))->assertOk()
        ->assertSee($visible)
        ->assertSee('22:00')
        ->assertSee($instant, escape: false)
        ->assertSee('Europe/London');
})->with('DST wall clock, both sides');

it('keeps JSON local and instant readings in agreement across a DST boundary', function (string $local, string $visible, string $instant) {
    $event = tzDisplayEvent($local, 'Europe/London');

    $data = $this->actingAs($this->member)
        ->getJson(route('events.show', $event))
        ->assertOk()
        ->json('data');

    expect($data['starts_at'])->toBe($instant)
        ->and($data['starts_at_local'])->toBe(substr($local, 0, 16))
        ->and($data['ends_at_local'])->toBe(substr($local, 0, 11).'22:00')
        ->and($data['timezone'])->toBe('Europe/London');
})->with('DST wall clock, both sides');

it('exports the instant, not the wall time, to ICS on both sides of a DST boundary', function (string $local, string $visible, string $instant, string $dtstart, string $dtend) {
    $event = tzDisplayEvent($local, 'Europe/London');

    $body = EventIcs::for($event);

    // The route serves this same builder; one route assertion pins the wiring.
    $this->get(route('events.ics', $event))->assertOk();

    expect(tzIcsValue($body, 'DTSTART'))->toBe($dtstart)
        ->and(tzIcsValue($body, 'DTEND'))->toBe($dtend);
})->with('DST wall clock, both sides');

it('links Google Calendar to the instant, not the wall time, across the spring boundary', function () {
    $before = tzDisplayEvent('2026-03-28 20:00', 'Europe/London');
    $after = tzDisplayEvent('2026-03-30 20:00', 'Europe/London');

    // Same 20:00 wall clock twice, an hour apart as instants.
    expect(tzGoogleDates($before))->toBe('20260328T200000Z/20260328T220000Z')
        ->and(tzGoogleDates($after))->toBe('20260330T190000Z/20260330T210000Z');
});

it('buckets a DST-boundary event on its host date with a host-zone chip time', function () {
    // 20:00 BST on the 24th is 19:00Z: a UTC-bucketed grid would file it under
    // the right day here but the chip must still read the host's 20:00, and the
    // GMT-side event a day after the fallback must read 20:00 too.
    tzDisplayEvent('2026-10-24 20:00', 'Europe/London');
    tzDisplayEvent('2026-10-26 20:00', 'Europe/London');

    $html = Livewire::test(EventsCalendar::class)
        ->set('month', '2026-10')
        ->call('setView', 'calendar')
        ->html();

    expect($html)->toMatch('/<td[^>]*>\s*<span[^>]*>\s*24\s*<\/span>[\s\S]*?20:00[\s\S]*?Evening games[\s\S]*?<\/td>/')
        ->and($html)->toMatch('/<td[^>]*>\s*<span[^>]*>\s*26\s*<\/span>[\s\S]*?20:00[\s\S]*?Evening games[\s\S]*?<\/td>/');
});

it('keeps one instant coherent across every surface for a cross-zone event', function () {
    // 20:00 in New York is the next UTC day. Every surface must carry the same
    // instant while printing the host's 15th, never a UTC-derived 16th.
    $event = tzDisplayEvent('2026-07-15 20:00', 'America/New_York');

    $data = $this->actingAs($this->member)
        ->getJson(route('events.show', $event))
        ->assertOk()
        ->json('data');

    expect($data['starts_at'])->toBe('2026-07-16T00:00:00+00:00')
        ->and($data['starts_at_local'])->toBe('2026-07-15 20:00')
        ->and($data['timezone'])->toBe('America/New_York');

    $this->get(route('events.page', $event))->assertOk()
        ->assertSee('Wed 15 Jul, 20:00')
        ->assertSee('2026-07-16T00:00:00+00:00', escape: false)
        ->assertSee('America/New_York');

    expect(tzIcsValue(EventIcs::for($event), 'DTSTART'))->toBe('20260716T000000Z')
        ->and(tzIcsValue(EventIcs::for($event), 'DTEND'))->toBe('20260716T020000Z')
        ->and(tzGoogleDates($event))->toBe('20260716T000000Z/20260716T020000Z');
});

it('renders the host zone in the grid for a viewer a day ahead', function () {
    // The viewer is in Auckland, where the New York event's instant is already
    // tomorrow noon. The grid must still bucket it on the host's 15th and the
    // chip must read the host's 20:00 — per-viewer rendering would print 12:00
    // in the 16th cell.
    Profile::factory()->for($this->member)->create(['timezone' => 'Pacific/Auckland']);
    tzDisplayEvent('2026-07-15 20:00', 'America/New_York');

    $html = Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->set('month', '2026-07')
        ->call('setView', 'calendar')
        ->html();

    expect($html)->toMatch('/<td[^>]*>\s*<span[^>]*>\s*15\s*<\/span>[\s\S]*?20:00[\s\S]*?Evening games[\s\S]*?<\/td>/')
        ->and($html)->not->toMatch('/<td[^>]*>\s*<span[^>]*>\s*16\s*<\/span>[\s\S]*?Evening games[\s\S]*?<\/td>/')
        ->and($html)->not->toContain('12:00');
});
