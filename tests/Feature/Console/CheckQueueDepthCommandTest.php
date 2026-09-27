<?php

use App\Jobs\SyncEventToDiscord;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * The queue-depth health signal for the Postgres queue.
 *
 * Sessions, cache and queue all run on Postgres, and the RSVP write-back to
 * Discord is async: the row is committed, the job is dispatched after the
 * commit, and the member sees `synced_to_discord_at` still null until a worker
 * picks it up. A silent queue backlog is therefore silent RSVP delay — nothing
 * 500s, nothing logs, the mirror just stops catching up. `queue:check-depth`
 * is the box-side probe for that failure, in the same tradition as
 * `discord:check-moderators`: something a tired person would otherwise read as
 * "looks fine" belongs in a script.
 *
 * What is asserted here is the counting, not the happy path of any one job:
 *
 *   - seeded write-back jobs show up in the reported depth;
 *   - jobs past their debounce window count as pending, with the oldest wait
 *     reported (that age *is* the RSVP lag);
 *   - the warn/critical thresholds move the exit code, using small explicit
 *     option values so no test has to seed fifty jobs.
 */

beforeEach(function () {
    // phpunit.xml runs the suite on QUEUE_CONNECTION=sync, where depth is
    // always zero by construction. The probe only means something against the
    // database driver, so these tests point the default connection at it. The
    // rows land inside the test's own transaction and roll back with it.
    config()->set('queue.default', 'database');
});

/** Run the probe, assert the exit code, and hand back the decoded payload. */
function probeDepth(int $expectedExit, array $args = []): array
{
    $code = Artisan::call('queue:check-depth', ['--json' => true] + $args);

    expect($code)->toBe($expectedExit);

    return json_decode(Artisan::output(), true);
}

/** Seed $count write-back jobs, each with a distinct idempotency scope. */
function seedWriteBacks(int $count): void
{
    foreach (range(1, $count) as $i) {
        SyncEventToDiscord::dispatch('depth-probe-event-'.$i);
    }
}

/**
 * Age every queued job past the debounce window, simulating a backlog.
 *
 * Both stamps move: `available_at` so the jobs read as pending rather than
 * delayed, and `created_at` because a real backlog is jobs dispatched minutes
 * ago that no worker has picked up — and the reported age is measured from
 * dispatch, which is when the member's answer started waiting.
 */
function ageQueuedJobs(): void
{
    $aged = now()->subMinutes(5)->timestamp;
    DB::table('jobs')->update(['available_at' => $aged, 'created_at' => $aged]);
}

it('reports an empty queue as ok', function () {
    $payload = probeDepth(0);

    expect($payload['status'])->toBe('ok')
        ->and($payload['pending'])->toBe(0)
        ->and($payload['failed'])->toBe(0);
});

it('counts seeded write-back jobs in the reported depth', function () {
    seedWriteBacks(3);

    $payload = probeDepth(0);

    // The job carries a 10-second debounce delay, so fresh dispatches sit in
    // the delayed bucket, not pending: nothing is late yet. That distinction
    // is the point — pending means waiting on a worker, not merely queued.
    expect($payload['total'])->toBe(3)
        ->and($payload['delayed'])->toBe(3)
        ->and($payload['pending'])->toBe(0);
});

it('counts aged jobs as pending and reports the oldest wait', function () {
    seedWriteBacks(3);
    ageQueuedJobs();

    $payload = probeDepth(0);

    expect($payload['pending'])->toBe(3)
        ->and($payload['oldest_pending_age_seconds'])->toBeGreaterThanOrEqual(300);
});

it('exits nonzero with a warn status past the warn threshold', function () {
    seedWriteBacks(3);
    ageQueuedJobs();

    $payload = probeDepth(1, ['--warn' => '2', '--critical' => '10']);

    expect($payload['status'])->toBe('warn');
});

it('exits nonzero with a critical status past the critical threshold', function () {
    seedWriteBacks(3);
    ageQueuedJobs();

    $payload = probeDepth(1, ['--warn' => '2', '--critical' => '3']);

    expect($payload['status'])->toBe('critical');
});

it('rejects an inverted threshold configuration instead of guessing', function () {
    $payload = probeDepth(1, ['--warn' => '10', '--critical' => '2']);

    expect($payload['status'])->toBe('error');
});
