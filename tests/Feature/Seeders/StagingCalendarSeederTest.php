<?php

use App\Enums\EventStatus;
use App\Models\Event;
use Database\Seeders\StagingCalendarSeeder;

/*
 * A standing demo calendar for staging reviewers: 50 events, always the same
 * shape, safe to re-run. The two properties that matter are the count (a
 * reviewer opens the calendar and sees a full month, not three rows) and the
 * production refusal (fabricated members in production would be a data
 * integrity incident, not a demo).
 */

it('seeds exactly fifty demo events with the documented mix', function () {
    $this->seed(StagingCalendarSeeder::class);

    expect(Event::count())->toBe(50)
        ->and(Event::where('status', EventStatus::Published)->count())->toBe(30)
        ->and(Event::where('status', EventStatus::Draft)->count())->toBe(6)
        ->and(Event::where('status', EventStatus::Cancelled)->count())->toBe(5)
        ->and(Event::where('status', EventStatus::Past)->count())->toBe(9);
});

it('covers several timezones so the calendar renders more than one zone', function () {
    $this->seed(StagingCalendarSeeder::class);

    expect(Event::distinct()->pluck('timezone')->count())->toBeGreaterThanOrEqual(4);
});

it('includes full events whose going count meets capacity', function () {
    $this->seed(StagingCalendarSeeder::class);

    $full = Event::where('status', EventStatus::Published)
        ->whereNotNull('capacity')
        ->get()
        ->filter(fn (Event $event): bool => $event->goingCount() >= $event->capacity);

    // The "event is full" state must exist without staging it by hand.
    expect($full)->toHaveCount(4);
});

it('is idempotent: a second run updates the same rows, never duplicates them', function () {
    $this->seed(StagingCalendarSeeder::class);
    $this->seed(StagingCalendarSeeder::class);

    expect(Event::count())->toBe(StagingCalendarSeeder::EVENT_COUNT);
});

// Direct call, not `$this->seed()`: the `db:seed` artisan wrapper asks for
// confirmation in production before the seeder's own guard ever runs, and the
// thing under test here is the guard, not the framework's prompt.
it('refuses to run in production', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    app(StagingCalendarSeeder::class)->run();
})->throws(RuntimeException::class, 'refuses to run in production');
