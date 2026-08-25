<?php

use App\Jobs\CallInternalAction;
use App\Services\Bot\InternalAction;
use App\Services\Bot\InternalActionClient;
use App\Services\Bot\InternalActionFailed;
use App\Services\Bot\InternalActionMisconfigured;
use App\Services\Bot\InternalActionResult;
use Illuminate\Contracts\Queue\Job as JobContract;

/**
 * The retry rules, which are the whole reason this action goes through a queue.
 *
 * The job is driven with a mocked queue job rather than a real worker, because
 * what is being asserted is precisely the pair of calls a worker reacts to —
 * `release()` with a delay, or `fail()` with an exception. Running it through a
 * real worker would test Laravel.
 */
function fakeQueueJob(int $attempts = 1): JobContract
{
    $job = Mockery::mock(JobContract::class);
    $job->shouldReceive('attempts')->andReturn($attempts);
    $job->shouldReceive('uuid')->andReturn('job-uuid')->byDefault();
    $job->shouldReceive('getConnectionName')->andReturn('database')->byDefault();
    $job->shouldReceive('resolveName')->andReturn(CallInternalAction::class)->byDefault();

    return $job;
}

function clientReturning(InternalActionResult $result): InternalActionClient
{
    $client = Mockery::mock(InternalActionClient::class);
    $client->shouldReceive('send')->once()->andReturn($result);

    /** @var InternalActionClient $client */
    return $client;
}

it('mints an idempotency key only for the actions that need one', function () {
    // §3: a needs-key action without a key is a `malformed`, not a best-effort
    // attempt — so the caller must not have to remember.
    $announcement = new CallInternalAction(InternalAction::announcementPost('announcements', 'Hi'));
    expect($announcement->idempotencyKey)->toMatch('/^[0-9a-f-]{36}$/');

    $event = new CallInternalAction(InternalAction::eventUpsert(
        'e', 'n',
        new DateTimeImmutable('2026-09-01T18:00:00+00:00'),
        new DateTimeImmutable('2026-09-01T20:00:00+00:00'),
        location: 'x',
    ));
    expect($event->idempotencyKey)->not->toBeNull();

    // role.assign is naturally idempotent; sending a key would be noise.
    expect((new CallInternalAction(InternalAction::roleAssign('900000000000009999', 'member')))->idempotencyKey)
        ->toBeNull();
});

it('gives two dispatches of the same announcement different keys', function () {
    // The corollary that looks like a bug and is not: a second dispatch is a
    // new operation and the bot is meant to act on it. Same-operation retries
    // are the serialised job coming back, not a fresh dispatch.
    $one = new CallInternalAction(InternalAction::announcementPost('announcements', 'Hi'));
    $two = new CallInternalAction(InternalAction::announcementPost('announcements', 'Hi'));

    expect($one->idempotencyKey)->not->toBe($two->idempotencyKey);
});

it('keeps the same idempotency key across serialisation to the queue and back', function () {
    // This is the claim the whole design rests on: the key is minted in the
    // constructor so it rides in the queue payload, and every retry of this
    // instance carries it. If serialisation dropped it, every retry would post
    // a second announcement.
    $job = new CallInternalAction(InternalAction::announcementPost('announcements', 'Hi'));

    /** @var CallInternalAction $revived */
    $revived = unserialize(serialize($job));

    expect($revived->idempotencyKey)->toBe($job->idempotencyKey);
    expect($revived->action->payload)->toBe($job->action->payload);
});

it('finishes quietly on success', function () {
    $job = new CallInternalAction(InternalAction::roleAssign('900000000000009999', 'member'));
    $queueJob = fakeQueueJob();
    $queueJob->shouldNotReceive('release');
    $queueJob->shouldNotReceive('fail');
    $job->setJob($queueJob);

    $job->handle(clientReturning(
        InternalActionResult::success(200, ['outcome' => 'assigned'], '01JOK', false)
    ));

    expect($job->lastResult?->ok)->toBeTrue();
});

it('treats a replayed success as a success', function () {
    // §2: the operation happened on an earlier attempt and the result body is
    // byte-identical. A caller that ignores the header is still correct, so
    // this must not be mistaken for a failure.
    $job = new CallInternalAction(InternalAction::announcementPost('announcements', 'Hi'));
    $queueJob = fakeQueueJob();
    $queueJob->shouldNotReceive('release');
    $queueJob->shouldNotReceive('fail');
    $job->setJob($queueJob);

    $job->handle(clientReturning(
        InternalActionResult::success(200, ['outcome' => 'posted', 'message_id' => '9'], '01JOK', true)
    ));

    expect($job->lastResult?->ok)->toBeTrue();
    expect($job->lastResult?->replayed)->toBeTrue();
});

it('fails immediately on a non-retryable error instead of burning attempts', function () {
    $job = new CallInternalAction(InternalAction::roleAssign('900000000000009999', 'member'));
    $queueJob = fakeQueueJob(attempts: 1);
    $queueJob->shouldNotReceive('release');
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(InternalActionFailed::class));
    $job->setJob($queueJob);

    // §2 on `retryable: false` — "retrying will fail the same way".
    $job->handle(clientReturning(
        InternalActionResult::failure(409, 'replayed', 'Nonce already seen', false, '01JBAD')
    ));
});

it('puts the request id into the failure so it can be joined to the bot audit row', function () {
    $result = InternalActionResult::failure(422, 'discord_rejected', 'Discord said no', false, '01JJOIN');
    $failure = InternalActionFailed::notRetryable('announcement.post', $result);

    // §4: internal_action_log is keyed by request_id. Reading failed_jobs is
    // the only context anyone will have when they get there.
    expect($failure->getMessage())->toContain('01JJOIN');
    expect($failure->getMessage())->toContain('announcement.post');
    expect($failure->getMessage())->toContain('discord_rejected');
});

it('releases a retryable error onto the backoff schedule', function (int $attempts, int $delay) {
    $job = new CallInternalAction(InternalAction::roleAssign('900000000000009999', 'member'));
    $queueJob = fakeQueueJob(attempts: $attempts);
    $queueJob->shouldNotReceive('fail');
    $queueJob->shouldReceive('release')->once()->with($delay);
    $job->setJob($queueJob);

    $job->handle(clientReturning(
        InternalActionResult::failure(502, 'discord_unavailable', 'unreachable', true, '01JRETRY')
    ));
})->with([
    'first attempt waits 5s' => [1, 5],
    'second waits 15s' => [2, 15],
    'third waits 60s' => [3, 60],
    'fourth waits 180s' => [4, 180],
]);

it('honours the bot Retry-After over its own schedule', function () {
    $job = new CallInternalAction(InternalAction::roleAssign('900000000000009999', 'member'));
    $queueJob = fakeQueueJob(attempts: 1);
    $queueJob->shouldNotReceive('fail');
    // 5s is what the schedule would have chosen; the bot knows when its token
    // bucket refills and we are guessing, so the bot wins.
    $queueJob->shouldReceive('release')->once()->with(42);
    $job->setJob($queueJob);

    $job->handle(clientReturning(
        InternalActionResult::failure(429, 'rate_limited', 'slow down', true, '01JRL', 42)
    ));
});

it('gives up once the attempts are spent', function () {
    $job = new CallInternalAction(InternalAction::roleAssign('900000000000009999', 'member'));
    $queueJob = fakeQueueJob(attempts: 5);
    $queueJob->shouldNotReceive('release');
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(InternalActionFailed::class));
    $job->setJob($queueJob);

    $job->handle(clientReturning(
        InternalActionResult::failure(502, 'discord_unavailable', 'unreachable', true, '01JGONE')
    ));
});

it('fails at once rather than retrying a blank secret', function () {
    // The expected state of any environment where the staging credentials have
    // not landed (TWO-21, TWO-11). Four more attempts produce four more
    // identical lines and no new information.
    $client = Mockery::mock(InternalActionClient::class);
    $client->shouldReceive('send')->once()->andThrow(InternalActionMisconfigured::missing('secret'));

    $job = new CallInternalAction(InternalAction::roleAssign('900000000000009999', 'member'));
    $queueJob = fakeQueueJob(attempts: 1);
    $queueJob->shouldNotReceive('release');
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(InternalActionMisconfigured::class));
    $job->setJob($queueJob);

    /** @var InternalActionClient $client */
    $job->handle($client);
});

it('names the action in its display name', function () {
    // What shows up in Horizon and in failed_jobs. "CallInternalAction" five
    // times over tells you nothing.
    expect((new CallInternalAction(InternalAction::announcementPost('a', 'b')))->displayName())
        ->toBe(CallInternalAction::class.':announcement.post');
});
