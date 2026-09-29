<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Profile;
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

    expect(Event::count())->toBe(StagingCalendarSeeder::EVENT_COUNT)
        ->and(Profile::count())->toBe(13);
});

it('gives every seeded member a deterministic profile', function () {
    $this->seed(StagingCalendarSeeder::class);

    // Organiser + 12 members, one profile each.
    expect(Profile::count())->toBe(13);

    $profile = Profile::first();

    expect($profile->bio)->toBeString()->not->toBe('')
        ->and($profile->games)->toBeArray()->not->toBeEmpty()
        ->and($profile->timezone)->toBeIn([
            'Europe/London',
            'America/New_York',
            'America/Los_Angeles',
            'Australia/Sydney',
            'Asia/Tokyo',
            'UTC',
        ]);

    // Second run keeps the same 13 rows — profiles are part of the
    // idempotency contract, not an append-per-run side effect.
    $this->seed(StagingCalendarSeeder::class);

    expect(Profile::count())->toBe(13);
});

it('queues a waitlist behind full events without touching the going count', function () {
    $this->seed(StagingCalendarSeeder::class);

    $full = Event::where('status', EventStatus::Published)
        ->whereNotNull('capacity')
        ->get()
        ->filter(fn (Event $event): bool => $event->goingCount() >= $event->capacity);

    expect($full)->toHaveCount(4);

    foreach ($full as $event) {
        // Going count stays exact: the waitlist holds seats, it never takes one.
        expect($event->goingCount())->toBe($event->capacity)
            ->and($event->waitlistCount())->toBeGreaterThanOrEqual(1);
    }

    $waitlisted = $full->flatMap(
        fn (Event $event) => $event->rsvps()->where('status', RsvpStatus::Waitlisted)->pluck('user_id')
    );

    expect($waitlisted)->not->toBeEmpty();

    $first = $full->first();
    $queuedUser = $first->rsvps()->where('status', RsvpStatus::Waitlisted)->first()->user;

    expect($first->waitlistPositionFor($queuedUser))->toBeGreaterThanOrEqual(1);
});

// Direct call, not `$this->seed()`: the `db:seed` artisan wrapper asks for
// confirmation in production before the seeder's own guard ever runs, and the
// thing under test here is the guard, not the framework's prompt.
it('refuses to run in production', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    app(StagingCalendarSeeder::class)->run();
})->throws(RuntimeException::class, 'refuses to run in production');

it('refuses to run with a production APP_URL outside the production environment', function () {
    // Synthetic hosts, never a real deployment name: the hostname lint bans
    // production literals in app/config, so both sides of this comparison are
    // example values.
    config(['app.url' => 'https://seed-target.example.test']);
    config(['app.production_url' => 'https://seed-target.example.test']);

    app(StagingCalendarSeeder::class)->run();
})->throws(RuntimeException::class, 'production APP_URL');

it('runs when the production URL is known but APP_URL points elsewhere', function () {
    config(['app.url' => 'https://seed-target.example.test']);
    config(['app.production_url' => 'https://elsewhere.example.test']);

    $this->seed(StagingCalendarSeeder::class);

    expect(Event::count())->toBe(StagingCalendarSeeder::EVENT_COUNT);
});
