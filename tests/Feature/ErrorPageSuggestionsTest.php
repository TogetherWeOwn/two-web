<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/*
 * The 404 page is a signpost, not a dead end (TOG-6929). On top of the branded
 * page from TOG-5626 it suggests up to 3 upcoming events — each linked to its
 * shareable page — plus a search form that lands on /events with `?q=`, the
 * same query param the calendar binds to the URL.
 *
 * The suggestions come from a view composer, not a controller: Laravel renders
 * `errors/404.blade.php` straight from the exception handler, so no controller
 * ever runs for it. The composer swallows database failures — a 404 must never
 * become a 500 — and it enforces the same draft rule as the listing: drafts
 * are invisible to guests, visible to moderators.
 */

function upcomingSuggestionEvent(string $title, int $daysOut, array $overrides = []): Event
{
    return Event::factory()->create(array_merge([
        'title' => $title,
        'starts_at' => now()->addDays($daysOut),
        'ends_at' => now()->addDays($daysOut)->addHours(2),
        'status' => EventStatus::Published,
    ], $overrides));
}

it('suggests the 3 soonest upcoming events on an unknown URL', function () {
    upcomingSuggestionEvent('Soonest game night', 1);
    upcomingSuggestionEvent('Second game night', 2);
    upcomingSuggestionEvent('Third game night', 3);
    upcomingSuggestionEvent('Fourth game night', 4);

    $response = $this->get('/nx-9x7q2-zzz')->assertStatus(404);

    $html = $response->getContent();

    expect(substr_count($html, 'data-testid="error-event-suggestion"'))->toBe(3);

    $response->assertSee('Soonest game night')
        ->assertSee('Second game night')
        ->assertSee('Third game night')
        ->assertDontSee('Fourth game night');
});

it('links each suggestion to its shareable event page', function () {
    $event = upcomingSuggestionEvent('Linked game night', 1);

    $this->get('/nx-9x7q2-zzz')
        ->assertStatus(404)
        ->assertSee(route('events.page', $event), escape: false);
});

it('points at the events search and the full listing instead of a dead end', function () {
    upcomingSuggestionEvent('Searchable game night', 1);

    $this->get('/nx-9x7q2-zzz')
        ->assertStatus(404)
        // The all-events link and the search form action both land on /events.
        ->assertSee(route('events.index'), escape: false)
        // The form speaks the calendar's own search language: GET with `q`.
        ->assertSeeHtml('data-testid="error-events-search"')
        ->assertSeeHtml('name="q"')
        ->assertSeeHtml('data-testid="error-all-events"');
});

it('shows the empty state when nothing is upcoming', function () {
    // No events at all: the suggestions section says so plainly rather than
    // rendering an empty list.
    $this->get('/nx-9x7q2-zzz')
        ->assertStatus(404)
        ->assertSeeHtml('data-testid="error-events-empty"')
        ->assertSee(__('errors.not_found_empty'), escape: false)
        ->assertDontSeeHtml('data-testid="error-event-suggestion"');
});

it('never leaks drafts or past events to a guest', function () {
    upcomingSuggestionEvent('Secret draft night', 1, ['status' => EventStatus::Draft]);
    upcomingSuggestionEvent('Last week night', -8, [
        'starts_at' => now()->subDays(8),
        'ends_at' => now()->subDays(8)->addHours(2),
    ]);

    $this->get('/nx-9x7q2-zzz')
        ->assertStatus(404)
        ->assertDontSee('Secret draft night')
        ->assertSeeHtml('data-testid="error-events-empty"');
});

it('shows drafts to a moderator, like the listing does', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);
    upcomingSuggestionEvent('Moderator draft night', 1, ['status' => EventStatus::Draft]);

    $this->actingAs($moderator)
        ->get('/nx-9x7q2-zzz')
        ->assertStatus(404)
        ->assertSee('Moderator draft night')
        ->assertSeeHtml('data-testid="error-event-suggestion"');
});

it('still answers 404 when the events table is gone', function () {
    // The degraded state, proved for real rather than stubbed: dropping the
    // table makes the composer's query throw, and the page must answer 404
    // with its copy and CTA intact instead of escalating to a 500. The table
    // comes back with the test transaction's rollback.
    Schema::drop('events');

    $this->get('/nx-9x7q2-zzz')
        ->assertStatus(404)
        ->assertSee(__('errors.not_found_title'), escape: false)
        ->assertSeeHtml('data-testid="discord-join"')
        ->assertSeeHtml('data-testid="error-events-empty"');
});
