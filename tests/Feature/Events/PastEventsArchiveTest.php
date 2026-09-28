<?php

use App\Enums\EventStatus;
use App\Livewire\PastEvents;
use App\Models\Event;
use App\Models\User;
use Livewire\Livewire;

// The past-events archive at `/events/past`: everything already over, most
// recent first, paginated. The calendar only answers "upcoming" and its inline
// past list stops at twenty — this is how members browse older history.
//
// Helper names carry an `archive` prefix on purpose: Pest loads every Feature
// file into one process, and `EventsCalendarTest.php` already owns the global
// `upcomingEvent()` / `pastEvent()` names. Redefining them here is a fatal.

beforeEach(function () {
    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->moderator = User::factory()->create(['is_moderator' => true]);
});

/** An event that has already ended, published, so a guest can see it. */
function archivePastEvent(array $overrides = []): Event
{
    return Event::factory()->create(array_merge([
        'title' => 'Last week s Valorant night',
        'starts_at' => now()->subWeek(),
        'ends_at' => now()->subWeek()->addHours(2),
        'status' => EventStatus::Published,
    ], $overrides));
}

/** An event that has not happened yet. */
function archiveUpcomingEvent(array $overrides = []): Event
{
    return Event::factory()->create(array_merge([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ], $overrides));
}

it('is reachable by a guest, because history is not a signed-in privilege', function () {
    archivePastEvent();

    $this->get(route('events.past'))
        ->assertOk()
        ->assertSeeLivewire(PastEvents::class);
});

it('has a descriptive document title', function () {
    $this->get(route('events.past'))
        ->assertOk()
        ->assertSee('<title>Past events — Together We Own</title>', escape: false);
});

it('shows past events, most recent first', function () {
    archivePastEvent(['title' => 'Older night', 'starts_at' => now()->subMonth(), 'ends_at' => now()->subMonth()->addHours(2)]);
    archivePastEvent(['title' => 'Newer night', 'starts_at' => now()->subWeek(), 'ends_at' => now()->subWeek()->addHours(2)]);

    Livewire::test(PastEvents::class)
        ->assertSee('Older night')
        ->assertSee('Newer night')
        ->assertSeeInOrder(['Newer night', 'Older night']);
});

it('keeps upcoming events out of the archive', function () {
    archivePastEvent(['title' => 'Last week s Valorant night']);
    archiveUpcomingEvent(['title' => 'Friday night Helldivers']);

    Livewire::test(PastEvents::class)
        ->assertSee('Last week s Valorant night')
        ->assertDontSee('Friday night Helldivers');
});

it('keeps a double-header in a stable order instead of drifting between pages', function () {
    // The same tiebreak the JSON listing uses, flipped: without it two events
    // starting at once can swap places from page to page.
    $tiedAt = now()->subWeek();
    archivePastEvent(['title' => 'Tied A', 'starts_at' => $tiedAt, 'ends_at' => $tiedAt->copy()->addHours(2)]);
    archivePastEvent(['title' => 'Tied B', 'starts_at' => $tiedAt, 'ends_at' => $tiedAt->copy()->addHours(2)]);

    // Later-created first (id descending), and the same order every render.
    // Compared by title position, not raw HTML: the Livewire snapshot carries
    // a per-render checksum, so two identical renders never share a string.
    $order = fn () => array_map(
        fn (string $title) => strpos(Livewire::test(PastEvents::class)->html(), $title),
        ['Tied B', 'Tied A'],
    );

    expect($order())->toBe($order());
    Livewire::test(PastEvents::class)->assertSeeInOrder(['Tied B', 'Tied A']);
});

it('paginates past twenty instead of growing back into the unbounded list', function () {
    foreach (range(1, 22) as $i) {
        archivePastEvent([
            'title' => "Night {$i}",
            'starts_at' => now()->subDays($i),
            'ends_at' => now()->subDays($i)->addHours(2),
        ]);
    }

    $first = Livewire::test(PastEvents::class);
    $first->assertSee('Night 1')->assertDontSee('Night 22');

    $first->call('gotoPage', 2)
        ->assertSee('Night 22')
        ->assertDontSee('Night 1');
});

it('announces the archive page politely when pagination swaps the list', function () {
    // TOG-7332: pagination re-renders the list without reloading, so the page
    // change has to be announced — role="status", never role="alert".
    foreach (range(1, 21) as $i) {
        archivePastEvent([
            'title' => "Night {$i}",
            'starts_at' => now()->subDays($i),
            'ends_at' => now()->subDays($i)->addHours(2),
        ]);
    }

    $html = Livewire::test(PastEvents::class)->html();

    expect($html)->toContain('role="status"')
        ->toContain('data-testid="past-events-page-status"')
        ->toContain('Showing page 1 of 2.')
        ->not->toContain('role="alert"');

    Livewire::test(PastEvents::class)
        ->call('gotoPage', 2)
        ->assertSee('Showing page 2 of 2.');
});

it('hides a draft from a member and from a guest but shows it to a moderator', function () {
    archivePastEvent(['title' => 'Unannounced raid', 'status' => EventStatus::Draft]);

    Livewire::test(PastEvents::class)->assertDontSee('Unannounced raid');

    Livewire::actingAs($this->member)
        ->test(PastEvents::class)
        ->assertDontSee('Unannounced raid');

    Livewire::actingAs($this->moderator)
        ->test(PastEvents::class)
        ->assertSee('Unannounced raid');
});

it('offers no RSVP on an archive card', function () {
    archivePastEvent();

    Livewire::actingAs($this->member)
        ->test(PastEvents::class)
        ->assertSeeHtml('data-testid="past-events-list"')
        ->assertDontSeeHtml('data-testid="rsvp-going"');
});

it('says what the empty archive means instead of rendering a bare list', function () {
    Livewire::test(PastEvents::class)
        ->assertSeeHtml('data-testid="past-events-empty"')
        ->assertSee('No past events yet.')
        ->assertSee('See upcoming events');
});

it('is linked from the events page', function () {
    $this->get(route('events.index'))
        ->assertOk()
        ->assertSeeHtml('data-testid="events-past-archive-link"')
        ->assertSee(route('events.past'), escape: false);
});

it('links back to the upcoming calendar', function () {
    archivePastEvent();

    $this->get(route('events.past'))
        ->assertOk()
        ->assertSee(route('events.index'), escape: false);
});
