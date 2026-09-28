<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

// Prev/next navigation on the shareable event page (`/e/{event_key}`): the
// adjacent published event in `starts_at` order, with the missing side omitted
// at the ends of the line.

beforeEach(function () {
    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->moderator = User::factory()->create(['is_moderator' => true]);
});

function navSequence(): array
{
    $first = Event::factory()->create([
        'title' => 'First game night',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
        'status' => EventStatus::Published,
    ]);
    $middle = Event::factory()->create([
        'title' => 'Second game night',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(2),
        'status' => EventStatus::Published,
    ]);
    $last = Event::factory()->create([
        'title' => 'Third game night',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);

    return [$first, $middle, $last];
}

it('links a middle event to both neighbors in starts_at order', function () {
    [$first, $middle, $last] = navSequence();

    $response = $this->get(route('events.page', $middle))->assertOk();

    $response->assertSeeHtml('data-testid="event-pagination"')
        ->assertSeeHtml('data-testid="event-previous"')
        ->assertSeeHtml('data-testid="event-next"')
        ->assertSeeHtml('href="'.route('events.page', $first).'"')
        ->assertSeeHtml('href="'.route('events.page', $last).'"')
        ->assertSee('First game night')
        ->assertSee('Third game night');
});

it('omits the previous link on the earliest event and the next link on the latest', function () {
    [$first, , $last] = navSequence();

    $this->get(route('events.page', $first))
        ->assertOk()
        ->assertSeeHtml('data-testid="event-next"')
        ->assertDontSeeHtml('data-testid="event-previous"');

    $this->get(route('events.page', $last))
        ->assertOk()
        ->assertSeeHtml('data-testid="event-previous"')
        ->assertDontSeeHtml('data-testid="event-next"');
});

it('renders no navigation for a single event', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $this->get(route('events.page', $event))
        ->assertOk()
        ->assertDontSeeHtml('data-testid="event-pagination"');
});

it('skips drafts for guests but links them for moderators', function () {
    $first = Event::factory()->create([
        'title' => 'First game night',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
        'status' => EventStatus::Published,
    ]);
    $draft = Event::factory()->create([
        'title' => 'Unannounced draft',
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(2),
        'status' => EventStatus::Draft,
    ]);
    $last = Event::factory()->create([
        'title' => 'Third game night',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);

    // A guest's "next" must never point at a draft that would answer 403.
    $this->get(route('events.page', $first))
        ->assertOk()
        ->assertSeeHtml('href="'.route('events.page', $last).'"')
        ->assertDontSee('Unannounced draft');

    $this->actingAs($this->moderator)
        ->get(route('events.page', $first))
        ->assertOk()
        ->assertSeeHtml('href="'.route('events.page', $draft).'"');
});

it('skips cancelled events, which answer 410 rather than a page', function () {
    $first = Event::factory()->create([
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
        'status' => EventStatus::Published,
    ]);
    Event::factory()->create([
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addHours(2),
        'status' => EventStatus::Cancelled,
    ]);
    $last = Event::factory()->create([
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);

    $this->get(route('events.page', $first))
        ->assertOk()
        ->assertSeeHtml('href="'.route('events.page', $last).'"');
});
