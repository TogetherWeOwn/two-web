<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\EventService;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as Events;

/*
 * When the Discord write-back runs, relative to the commit.
 *
 * This lives in the committing suite because the property cannot be observed
 * anywhere else: RefreshDatabase holds every test inside a transaction that is
 * rolled back at the end, so a job dispatched `afterCommit` is handed to the
 * transaction manager and waits for a commit that never comes. A test written over
 * there can prove the job did *not* run early and can never prove it ran at all.
 *
 * Queue::fake() cannot stand in for this either. The fake records a dispatch the
 * instant it is made and knows nothing about transactions, so it reports the job as
 * pushed whether or not `afterCommit` was ever asked for — it goes green against
 * the bug. The `sync` driver the suite already runs on is transaction aware, so
 * watching when the job actually executes is the real measurement.
 */

/**
 * Watch for a queued job executing. Returns a closure answering "has one run yet?",
 * so a test can ask at more than one moment.
 *
 * @return Closure(): bool
 */
function writeBackHasRun(): Closure
{
    $ran = false;

    Events::listen(JobProcessing::class, function () use (&$ran): void {
        $ran = true;
    });

    // By reference, and not an arrow function. `fn () => $ran` captures by value at
    // the moment it is written, so it would answer "no" forever and both tests
    // below would pass against any implementation at all.
    return function () use (&$ran): bool {
        return $ran;
    };
}

it('runs the Discord write-back after the commit, and not before', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    $member = User::factory()->create();
    $hasRun = writeBackHasRun();

    DB::transaction(function () use ($event, $member, $hasRun): void {
        app(EventService::class)->rsvp($event, $member, RsvpStatus::Going);

        // A write-back that starts here can read a row that is not committed yet —
        // or one that is about to be rolled back — and tell Discord either way.
        expect($hasRun())->toBeFalse('the write-back ran inside the transaction');
    });

    expect($hasRun())->toBeTrue('the write-back never ran after the commit')
        ->and(Rsvp::query()->count())->toBe(1);
});

it('never runs the write-back for an RSVP that was rolled back', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    $member = User::factory()->create();
    $hasRun = writeBackHasRun();

    try {
        DB::transaction(function () use ($event, $member): void {
            app(EventService::class)->rsvp($event, $member, RsvpStatus::Going);

            throw new RuntimeException('something later in the request failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($hasRun())->toBeFalse('Discord was told about an RSVP that was rolled back')
        ->and(Rsvp::query()->count())->toBe(0);
});
