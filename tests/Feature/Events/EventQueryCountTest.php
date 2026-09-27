<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\FeaturedContent;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\Counts\CountsSource;
use App\Support\Counts\LiveCounts;
use Illuminate\Support\Facades\DB;

// Query-count guards for the two hot paths: the landing page (counts +
// featured) and the events JSON collection. Each test loads the page with
// enough rows that a per-row query would blow the bound, then asserts the
// total stays flat. Bounds are deliberately loose (a handful of queries, not
// exactly one): the thing being pinned is "does not grow with the row count",
// not today's exact query plan.

it('renders home with a bounded number of queries no matter how many rows are featured', function () {
    // Counts are mocked so the bot database is not part of this measurement:
    // what is under test is our own queries, not the collector's.
    $this->mock(CountsSource::class, function ($mock) {
        $mock->shouldReceive('liveCounts')->andReturn(LiveCounts::unavailable());
        $mock->shouldReceive('ranks')->andReturn([]);
    });
    FeaturedContent::factory()->count(10)->published()->create();

    DB::enableQueryLog();
    $this->get('/')->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Ten featured rows. A per-row query would put this past ten.
    expect(count($queries))->toBeLessThan(5);
});

it('serves the events collection with a bounded number of queries no matter how many events there are', function () {
    $member = User::factory()->create(['is_moderator' => false]);
    $events = Event::factory()->count(10)->create([
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);
    foreach ($events as $event) {
        Rsvp::factory()->count(3)->create(['event_id' => $event->id, 'status' => RsvpStatus::Going]);
    }

    DB::enableQueryLog();
    $response = $this->actingAs($member)->getJson(route('events.json'))->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Ten events. A per-event `select count(*)` would put this past ten.
    expect(count($queries))->toBeLessThan(5);

    // The eager aggregate must still be the right number per row, not just cheap.
    expect($response->json('data'))->toHaveCount(10)
        ->and(collect($response->json('data'))->pluck('going_count')->all())
        ->each->toBe(3);
});

it('still counts going on a single event without the listing aggregate', function () {
    // The show/store/update responses build the resource from one row with no
    // `withCount`, so the resource must fall back to a count rather than
    // printing null. This pins that fallback rather than the query plan.
    $moderator = User::factory()->create(['is_moderator' => true]);
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    Rsvp::factory()->count(2)->create(['event_id' => $event->id, 'status' => RsvpStatus::Going]);
    Rsvp::factory()->create(['event_id' => $event->id, 'status' => RsvpStatus::Maybe]);

    $this->actingAs($moderator)
        ->getJson(route('events.show', $event))
        ->assertOk()
        ->assertJsonPath('data.going_count', 2);
});
