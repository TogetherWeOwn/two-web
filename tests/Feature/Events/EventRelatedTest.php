<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

// The related-events block on the shareable event page (`/e/{event_key}`):
// up to 3 upcoming siblings, same game first, with a join pitch for guests.
// Hidden entirely when there is nothing to show — never an empty heading.

beforeEach(function () {
    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->moderator = User::factory()->create(['is_moderator' => true]);
});

function relatedEvent(array $overrides = []): Event
{
    return Event::factory()->create(array_merge([
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ], $overrides));
}

function relatedLinkCount($response): int
{
    return substr_count($response->getContent(), 'data-testid="event-related-link"');
}

it('shows up to 3 related links for an event with siblings, same game first', function () {
    $event = relatedEvent(['title' => 'Friday Helldivers', 'game' => 'Helldivers 2']);
    relatedEvent(['title' => 'Saturday Helldivers', 'game' => 'Helldivers 2', 'starts_at' => now()->addDays(4), 'ends_at' => now()->addDays(4)->addHours(2)]);
    relatedEvent(['title' => 'Sunday Helldivers', 'game' => 'Helldivers 2', 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addHours(2)]);
    relatedEvent(['title' => 'Monday Helldivers', 'game' => 'Helldivers 2', 'starts_at' => now()->addDays(6), 'ends_at' => now()->addDays(6)->addHours(2)]);
    relatedEvent(['title' => 'Tuesday Helldivers', 'game' => 'Helldivers 2', 'starts_at' => now()->addDays(7), 'ends_at' => now()->addDays(7)->addHours(2)]);
    relatedEvent(['title' => 'Valorant night', 'game' => 'Valorant', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2)]);

    $response = $this->get(route('events.page', $event))->assertOk();

    // Five siblings, four same-game: capped at 3, all same-game.
    $response->assertSeeHtml('data-testid="event-related"');
    expect(relatedLinkCount($response))->toBe(3);
    $response->assertSee('Saturday Helldivers')
        ->assertSee('Sunday Helldivers')
        ->assertSee('Monday Helldivers')
        ->assertDontSee('Tuesday Helldivers')
        ->assertDontSee('Valorant night');
});

it('fills the block with other upcoming events when same-game siblings run out', function () {
    $event = relatedEvent(['title' => 'Friday Helldivers', 'game' => 'Helldivers 2']);
    $same = relatedEvent(['title' => 'Saturday Helldivers', 'game' => 'Helldivers 2', 'starts_at' => now()->addDays(4), 'ends_at' => now()->addDays(4)->addHours(2)]);
    $other = relatedEvent(['title' => 'Valorant night', 'game' => 'Valorant', 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addHours(2)]);

    $response = $this->get(route('events.page', $event))->assertOk();

    expect(relatedLinkCount($response))->toBe(2);
    $response->assertSeeHtml('href="'.route('events.page', $same).'"')
        ->assertSeeHtml('href="'.route('events.page', $other).'"');
});

it('hides the block entirely when an event has no siblings, never empty', function () {
    $event = relatedEvent();

    $this->get(route('events.page', $event))
        ->assertOk()
        ->assertDontSeeHtml('data-testid="event-related"')
        ->assertDontSee('More events you might like');
});

it('excludes itself, cancelled and past events from the block', function () {
    $event = relatedEvent(['title' => 'Friday Helldivers', 'game' => 'Helldivers 2']);
    $sibling = relatedEvent(['title' => 'Saturday Helldivers', 'game' => 'Helldivers 2', 'starts_at' => now()->addDays(4), 'ends_at' => now()->addDays(4)->addHours(2)]);
    relatedEvent(['title' => 'Called off', 'game' => 'Helldivers 2', 'status' => EventStatus::Cancelled]);
    relatedEvent([
        'title' => 'Last week Helldivers',
        'game' => 'Helldivers 2',
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->subDays(2)->addHours(2),
    ]);

    $response = $this->get(route('events.page', $event))->assertOk();

    // One live sibling only: the current event must not count itself.
    expect(relatedLinkCount($response))->toBe(1);
    $response->assertSeeHtml('href="'.route('events.page', $sibling).'"')
        ->assertDontSee('Called off')
        ->assertDontSee('Last week Helldivers');
});

it('skips drafts for guests but shows them to moderators', function () {
    $event = relatedEvent(['title' => 'Friday Helldivers', 'game' => 'Helldivers 2']);
    relatedEvent([
        'title' => 'Unannounced draft',
        'game' => 'Helldivers 2',
        'starts_at' => now()->addDays(4),
        'ends_at' => now()->addDays(4)->addHours(2),
        'status' => EventStatus::Draft,
    ]);

    // The draft is the only sibling, so a guest sees no block at all.
    $this->get(route('events.page', $event))
        ->assertOk()
        ->assertDontSeeHtml('data-testid="event-related"')
        ->assertDontSee('Unannounced draft');

    $this->actingAs($this->moderator)
        ->get(route('events.page', $event))
        ->assertOk()
        ->assertSeeHtml('data-testid="event-related"')
        ->assertSee('Unannounced draft');
});

it('pitches joining to a guest inside the block, not to a signed-in member', function () {
    $event = relatedEvent(['game' => 'Helldivers 2']);
    relatedEvent(['game' => 'Helldivers 2', 'starts_at' => now()->addDays(4), 'ends_at' => now()->addDays(4)->addHours(2)]);

    $this->get(route('events.page', $event))
        ->assertOk()
        ->assertSeeHtml('data-testid="event-related-join"');

    $this->actingAs($this->member)
        ->get(route('events.page', $event))
        ->assertOk()
        ->assertSeeHtml('data-testid="event-related"')
        ->assertDontSeeHtml('data-testid="event-related-join"');
});
