<?php

use App\Enums\EventStatus;
use App\Models\Event;

// HTTP contract for server-side event search on `GET /events?q=` (shipped in
// #364). The Livewire behavior suite in EventsCalendarTest already pins the
// component cases (fragments, casing, description, XSS echo, `%` literal,
// drafts, past matches, view/month/clear); this file pins only the route-level
// contract: a shareable `?q=` URL narrows the rendered page, an empty query is
// the full list, and a no-match query gets the search empty state — never the
// "nothing is planned" ones.

/** A published upcoming event with fixed copy, so faker text cannot flake the assertions. */
function searchableEvent(array $overrides = []): Event
{
    return Event::factory()->create(array_merge([
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ], $overrides));
}

it('narrows the events page to the query match', function () {
    searchableEvent(['title' => 'Friday night Helldivers', 'description' => 'Weekly co-op chaos.']);
    searchableEvent(['title' => 'Sunday Valorant scrims', 'description' => 'Tactical practice.']);

    $this->get(route('events.index', ['q' => 'helldiv']))
        ->assertOk()
        ->assertSee('Friday night Helldivers')
        ->assertDontSee('Sunday Valorant scrims');
});

it('treats an empty query as no search', function () {
    searchableEvent(['title' => 'Friday night Helldivers', 'description' => 'Weekly co-op chaos.']);
    searchableEvent(['title' => 'Sunday Valorant scrims', 'description' => 'Tactical practice.']);

    $this->get(route('events.index'))
        ->assertOk()
        ->assertSee('Friday night Helldivers')
        ->assertSee('Sunday Valorant scrims')
        ->assertDontSeeHtml('data-testid="events-empty-search"');
});

it('shows the search empty state when nothing matches', function () {
    searchableEvent(['title' => 'Friday night Helldivers', 'description' => 'Weekly co-op chaos.']);

    $this->get(route('events.index', ['q' => 'zzz-no-such-event-zzz']))
        ->assertOk()
        ->assertSeeHtml('data-testid="events-empty-search"')
        ->assertSee('Nothing matches that search.')
        ->assertDontSee('Friday night Helldivers')
        // Neither no-search empty state applies to a query with no matches.
        ->assertDontSeeHtml('data-testid="events-empty-never"')
        ->assertDontSeeHtml('data-testid="events-empty-gap"');
});
