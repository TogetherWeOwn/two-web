<?php

use App\Enums\EventStatus;
use App\Livewire\EventsCalendar;
use App\Models\Event;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

// TOG-6808: the calendar buckets days by each event's host-zone date, but the
// today highlight used a server-zone Y-m-d, so it lit the wrong cell whenever
// the two zones disagreed about what day it is. The highlight now follows the
// hosts' zone; rendering stays host-zone (see EventsCalendar::calendarZone)
// rather than per-viewer, so a profile timezone never moves an event.
//
// Helper names carry a `calTz` prefix on purpose: Pest loads every Feature file
// into one process, and `EventsCalendarTest.php` already owns the global
// `upcomingEvent()` / `pastEvent()` names. Redefining them here is a fatal.

beforeEach(function () {
    $this->member = User::factory()->create(['is_moderator' => false]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A published London event on the given local wall clock. The title stays
 * under 18 characters on purpose: the calendar grid truncates with
 * `Str::limit($event->title, 18)`, so a longer title would never appear whole
 * there and a grid assertion on it would fail for the wrong reason.
 */
function calTzLondonEvent(string $localWallTime): Event
{
    $start = Carbon::parse($localWallTime, 'Europe/London');

    return Event::factory()->create([
        'title' => 'Midnight games',
        'starts_at' => $start,
        'ends_at' => $start->copy()->addHours(2),
        'timezone' => 'Europe/London',
        'status' => EventStatus::Published,
    ]);
}

it('highlights the host-zone today, not the server-zone one', function () {
    // 00:30 BST on the 16th is still the 15th in UTC. The old server-zone
    // highlight lit the 15th; the hosts, and the bucket the event sits in,
    // both say the 16th.
    Carbon::setTestNow('2026-07-15T23:30:00Z');
    calTzLondonEvent('2026-07-16 00:30');

    $html = Livewire::test(EventsCalendar::class)
        ->call('setView', 'calendar')
        ->html();

    // The 16th cell carries the marker…
    expect($html)->toMatch('/<td[^>]*aria-current="date"[^>]*>\s*<span[^>]*>\s*16\s*</');
    // …and the server-zone 15th does not.
    expect($html)->not->toMatch('/<td[^>]*aria-current="date"[^>]*>\s*<span[^>]*>\s*15\s*</');
});

it('buckets an after-midnight host-zone event on the host date', function () {
    Carbon::setTestNow('2026-07-15T23:30:00Z');
    calTzLondonEvent('2026-07-16 00:30');

    $html = Livewire::test(EventsCalendar::class)
        ->call('setView', 'calendar')
        ->html();

    // The event link sits inside the 16th cell, the same date the card prints.
    expect($html)->toMatch('/<td[^>]*>\s*<span[^>]*>\s*16\s*<\/span>[\s\S]*?Midnight games[\s\S]*?<\/td>/');
});

it('keeps host-zone wall times for a viewer in another timezone', function () {
    // Per-viewer rendering was the alternative; the decision is documented
    // host-zone display, so a New York profile must not move London times.
    // The clock is frozen so the event counts as upcoming and gets a card.
    Carbon::setTestNow('2026-07-16T12:00:00Z');
    Profile::factory()->for($this->member)->create(['timezone' => 'America/New_York']);
    calTzLondonEvent('2026-07-16 20:00');

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        // 20:00 BST is 15:00 in New York: if the profile zone leaked into
        // rendering, the card would say 15:00 instead of 20:00.
        ->assertSee('20:00')
        ->assertDontSee('15:00')
        ->assertSee('Europe/London');
});

it('still highlights a day when there are no events to take a zone from', function () {
    Carbon::setTestNow('2026-07-15T23:30:00Z');

    $html = Livewire::test(EventsCalendar::class)
        ->call('setView', 'calendar')
        ->html();

    // Falls back to the app zone rather than highlighting nothing: an empty
    // grid with no today marker reads as a broken calendar.
    expect($html)->toContain('aria-current="date"');
});
