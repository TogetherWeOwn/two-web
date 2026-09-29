<?php

use App\Enums\EventStatus;
use App\Livewire\EventsCalendar;
use App\Models\Event;
use App\Models\EventSearchLog;
use App\Models\User;
use App\Support\Events\DiscordEventsSource;
use App\Support\Events\EventSearchLogger;
use Illuminate\Console\Scheduling\Schedule;
use Livewire\Livewire;

// Event-search logging (TOG-8400): what guests searched and what each search
// found, normalized query plus result count only — no user id, no session, no
// IP, no raw input. A moderator lists the top zero-result queries on the
// admin dashboard to spot content gaps.
//
// NOTE: helper names are file-prefixed. Pest loads every test file into one
// process, so a bare `searchableEvent` (EventSearchTest) would fatal here.

beforeEach(function () {
    // The calendar renders through the Discord source on every pass; stub it
    // clean so no test touches the real bot connection.
    $source = Mockery::mock(DiscordEventsSource::class);
    $source->shouldReceive('upcoming')->andReturn([]);
    $source->shouldReceive('lastReadFailed')->andReturn(false);
    app()->instance(DiscordEventsSource::class, $source);
});

/** A published upcoming event with fixed copy, so faker text cannot flake the assertions. */
function searchLogEvent(array $overrides = []): Event
{
    return Event::factory()->create(array_merge([
        'title' => 'Friday night Helldivers',
        'description' => 'Weekly co-op chaos.',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ], $overrides));
}

it('records the normalized query and the result count when a guest searches', function () {
    searchLogEvent();

    Livewire::test(EventsCalendar::class)
        ->set('search', 'HELLDIV');

    $row = EventSearchLog::query()->sole();

    expect($row->normalized_query)->toBe('helldiv')
        ->and($row->result_count)->toBe(1);
});

it('aggregates repeat searches for the same term into countable rows', function () {
    searchLogEvent();

    Livewire::test(EventsCalendar::class)->set('search', 'helldiv');
    Livewire::test(EventsCalendar::class)->set('search', '  HELLDIV  ');

    // Casing and surrounding whitespace normalize together — two renders of
    // the same search are two rows under one key, which is what makes the
    // listing countable.
    expect(EventSearchLog::query()->where('normalized_query', 'helldiv')->count())->toBe(2)
        ->and(EventSearchLog::query()->count())->toBe(2);
});

it('records zero-result searches so content gaps are visible', function () {
    searchLogEvent();

    Livewire::test(EventsCalendar::class)
        ->set('search', 'zzz-no-such-event-zzz');

    $row = EventSearchLog::query()->sole();

    expect($row->normalized_query)->toBe('zzz-no-such-event-zzz')
        ->and($row->result_count)->toBe(0);
});

it('writes no row for a blank search, because a blank search is no search', function () {
    searchLogEvent();

    Livewire::test(EventsCalendar::class)
        ->set('search', '   ');

    expect(EventSearchLog::query()->count())->toBe(0);
});

it('writes no row for an unsearched page view', function () {
    searchLogEvent();

    Livewire::test(EventsCalendar::class);

    expect(EventSearchLog::query()->count())->toBe(0);
});

it('still renders results when the log table is down, because logging is fail-open', function () {
    searchLogEvent();
    Schema::drop('event_search_logs');

    Livewire::test(EventsCalendar::class)
        ->set('search', 'helldiv')
        ->assertSee('Friday night Helldivers');
});

it('lists top zero-result queries ordered by misses for a moderator', function () {
    $logger = app(EventSearchLogger::class);
    $logger->record('valorant', 0);
    $logger->record('valorant', 0);
    $logger->record('valorant', 0);
    $logger->record('chess-boxing', 0);
    $logger->record('helldivers', 2);

    $rows = $logger->topZeroResult();

    // The hit (helldivers, 2 results) is not a miss and stays out; misses
    // order by count, ties alphabetical.
    expect($rows->pluck('normalized_query')->all())->toBe(['valorant', 'chess-boxing'])
        ->and($rows->firstWhere('normalized_query', 'valorant')->searches)->toBe(3);
});

it('shows the missed-searches widget to a moderator on /admin', function () {
    app(EventSearchLogger::class)->record('valorant', 0);
    $moderator = User::factory()->create(['is_moderator' => true]);

    $this->actingAs($moderator)->get('/admin')
        ->assertOk()
        ->assertSee('Top searches with no results', escape: false)
        ->assertSee('valorant', escape: false);
});

it('answers a plain member on /admin with 403', function () {
    $member = User::factory()->create(['is_moderator' => false]);

    $this->actingAs($member)->get('/admin')->assertForbidden();
});

it('prunes search rows past the retention window and keeps the ones inside it', function () {
    config()->set('event_search_log.retention_days', 90);

    $stale = EventSearchLog::query()->create([
        'normalized_query' => 'stale-game',
        'result_count' => 0,
        'occurred_at' => now()->subDays(91),
    ]);
    $kept = EventSearchLog::query()->create([
        'normalized_query' => 'fresh-game',
        'result_count' => 0,
        'occurred_at' => now()->subDays(89),
    ]);

    $this->artisan('model:prune', ['--model' => [EventSearchLog::class]])->assertSuccessful();

    expect(EventSearchLog::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('has retention scheduled daily, because a retention policy nothing runs is a promise', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'model:prune')
            && str_contains($event->command ?? '', 'EventSearchLog'));

    expect($events)->not->toBeEmpty();

    $events->each(fn ($event) => expect($event->getExpression())->toBe('0 0 * * *'));
});
