<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

// `GET /events.json` is paginated: `?page`/`?per_page` over a stable
// `starts_at` order, capped so the collection cannot grow back into the
// unbounded query this replaced. These tests pin the contract — the page
// size default, the cap, and the cross-page ordering — without re-testing
// the visibility or count rules EventQueryCountTest already owns.

beforeEach(function () {
    $this->member = User::factory()->create(['is_moderator' => false]);
});

/** Create $count published events on consecutive days, earliest first. */
function upcomingEvents(int $count): void
{
    foreach (range(0, $count - 1) as $i) {
        Event::factory()->create([
            'starts_at' => now()->addDays($i + 1),
            'ends_at' => now()->addDays($i + 1)->addHours(2),
            'status' => EventStatus::Published,
        ]);
    }
}

it('returns the default page of twenty in starts_at order', function () {
    upcomingEvents(25);

    $data = $this->actingAs($this->member)
        ->getJson(route('events.json'))
        ->assertOk()
        ->json();

    $starts = collect($data['data'])->pluck('starts_at')->all();

    expect($data['data'])->toHaveCount(20)
        ->and($starts)->toBe($sorted = collect($starts)->sort()->values()->all())
        ->and($data['meta'])->toMatchArray(['current_page' => 1, 'per_page' => 20, 'total' => 25]);
});

it('walks pages without repeating or skipping events', function () {
    upcomingEvents(7);

    $first = $this->actingAs($this->member)
        ->getJson(route('events.json', ['per_page' => 3, 'page' => 1]))
        ->assertOk()->json('data');
    $second = $this->actingAs($this->member)
        ->getJson(route('events.json', ['per_page' => 3, 'page' => 2]))
        ->assertOk()->json('data');
    $third = $this->actingAs($this->member)
        ->getJson(route('events.json', ['per_page' => 3, 'page' => 3]))
        ->assertOk()->json('data');

    $keys = array_merge(
        collect($first)->pluck('event_key')->all(),
        collect($second)->pluck('event_key')->all(),
        collect($third)->pluck('event_key')->all(),
    );

    expect($keys)->toHaveCount(7)->and($keys)->toBe(array_values(array_unique($keys)));

    $starts = array_merge(
        collect($first)->pluck('starts_at')->all(),
        collect($second)->pluck('starts_at')->all(),
        collect($third)->pluck('starts_at')->all(),
    );
    expect($starts)->toBe(collect($starts)->sort()->values()->all());
});

it('clamps per_page to the cap instead of returning the whole archive', function () {
    upcomingEvents(5);

    $data = $this->actingAs($this->member)
        ->getJson(route('events.json', ['per_page' => 500]))
        ->assertOk()
        ->json();

    expect($data['data'])->toHaveCount(5)
        ->and($data['meta']['per_page'])->toBe(100);
});
