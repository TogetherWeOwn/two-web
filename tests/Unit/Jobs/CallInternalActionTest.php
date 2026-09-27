<?php

use App\Jobs\CallInternalAction;
use App\Services\Bot\Announcement;
use App\Services\Bot\AnnouncementResult;
use App\Services\Bot\InternalActionClient;
use App\Services\Bot\InternalActionFailure;
use App\Services\Bot\RoleAssignment;
use App\Services\Bot\RoleAssignResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The queued sender for `role.assign` and `announcement.post` (TOG-470/TOG-929).
 *
 * What is asserted here is the failure behaviour, because the happy path is the
 * part that cannot go quietly wrong:
 *
 *   - a retryable refusal is released, never failed, and the bot's own
 *     Retry-After beats our schedule;
 *   - an unreachable bot is released too — nothing happened at Discord;
 *   - a terminal refusal fails immediately rather than burning the backoff;
 *   - the idempotency key survives a retry, because a fresh one per attempt is
 *     how one announcement becomes two.
 */

const JOB_ENDPOINT = 'http://127.0.0.1:3001/internal/actions';

beforeEach(function () {
    config()->set('services.bot.url', 'http://127.0.0.1:3001');
    config()->set('services.bot.secret', 'two-web-test-secret-at-least-32-characters');
    config()->set('services.bot.key_id', 'web-test');
    config()->set('services.bot.timeout', 5);
});

function jobAnnouncement(): Announcement
{
    return new Announcement('qa-throwaway', 'hello');
}

function jobRole(): RoleAssignment
{
    return new RoleAssignment('900000000000009999', 'rocketleague');
}

/**
 * A job whose queue interactions are observable.
 *
 * `release()` and `fail()` on a real job need a worker behind them. This records
 * the call instead, which is the only way to assert "released, not failed" — the
 * distinction the whole class exists to get right.
 */
function spyJob(RoleAssignment|Announcement $action, int $attempts = 1): CallInternalAction
{
    return new class($action, $attempts) extends CallInternalAction
    {
        public ?int $releasedAfter = null;

        public ?Throwable $failedWith = null;

        public function __construct(RoleAssignment|Announcement $action, private int $fakeAttempts)
        {
            parent::__construct($action);
        }

        public function attempts(): int
        {
            return $this->fakeAttempts;
        }

        public function release($delay = 0): void
        {
            $this->releasedAfter = (int) $delay;
        }

        public function fail($exception = null): void
        {
            $this->failedWith = $exception;
        }
    };
}

/**
 * Prefixed, like every other helper here. Pest runs the whole suite in one
 * process, so a test file's top-level functions and constants are global:
 * SyncEventToDiscordTest already declares a `jobRefusal()`, and the second
 * declaration is a fatal error that takes down the run rather than one test.
 */
function jobRefusal(string $code, bool $retryable): array
{
    return ['ok' => false, 'error' => ['code' => $code, 'message' => 'no', 'retryable' => $retryable], 'request_id' => 'r1'];
}

it('posts an announcement and reports the message id', function () {
    Http::fake([JOB_ENDPOINT => Http::response([
        'ok' => true, 'result' => ['outcome' => 'posted', 'message_id' => '55'], 'request_id' => 'r1',
    ])]);

    $job = spyJob(jobAnnouncement());
    $job->handle(app(InternalActionClient::class));

    expect($job->lastResult)->toBeInstanceOf(AnnouncementResult::class)
        ->and($job->lastResult->messageId)->toBe('55')
        ->and($job->failedWith)->toBeNull()
        ->and($job->releasedAfter)->toBeNull();
});

it('assigns a role and reports the outcome', function () {
    Http::fake([JOB_ENDPOINT => Http::response(['ok' => true, 'result' => ['outcome' => 'already_held'], 'request_id' => 'r1'])]);

    $job = spyJob(jobRole());
    $job->handle(app(InternalActionClient::class));

    expect($job->lastResult)->toBeInstanceOf(RoleAssignResult::class)
        ->and($job->lastResult->outcome->value)->toBe('already_held')
        ->and($job->failedWith)->toBeNull();
});

it('mints an idempotency key for an announcement and none for a role assign', function () {
    // The typed decision §3 calls *needs key*, made once in the constructor so
    // no caller has to remember which action is which.
    expect((new CallInternalAction(jobAnnouncement()))->idempotencyKey)->toBeString()
        ->and((new CallInternalAction(jobRole()))->idempotencyKey)->toBeNull();
});

it('carries one idempotency key across every attempt at the same job', function () {
    // The failure this guards is invisible until the day the bot times out: a
    // key minted per attempt makes the retry a *new* operation, and the
    // announcement posts twice. Serialising and waking the job is what a real
    // release-and-retry does to it, so that is what is asserted.
    $job = new CallInternalAction(jobAnnouncement());
    $key = $job->idempotencyKey;

    $woken = unserialize(serialize($job));

    expect($woken->idempotencyKey)->toBe($key);

    Http::fake([JOB_ENDPOINT => Http::response([
        'ok' => true, 'result' => ['outcome' => 'posted', 'message_id' => '55'], 'request_id' => 'r1',
    ])]);

    $woken->handle(app(InternalActionClient::class));
    $woken->handle(app(InternalActionClient::class));

    $keys = [];
    $nonces = [];
    Http::assertSent(function (Request $request) use (&$keys, &$nonces) {
        $keys[] = $request->header('Idempotency-Key')[0];
        $nonces[] = $request->header('X-TWO-Nonce')[0];

        return true;
    });

    // The key is held still and the nonce is not. That pair is the whole rule.
    expect(array_unique($keys))->toHaveCount(1)
        ->and(array_unique($keys)[0])->toBe($key)
        ->and(array_unique($nonces))->toHaveCount(2);
});

it('dispatching a second job is a second operation with its own key', function () {
    // The thing that looks like a bug and is not: two dispatches means two
    // announcements, by design. If these ever collided, a deliberate repost
    // would be silently swallowed as a duplicate.
    expect((new CallInternalAction(jobAnnouncement()))->idempotencyKey)
        ->not->toBe((new CallInternalAction(jobAnnouncement()))->idempotencyKey);
});

it('releases rather than fails when the bot refuses retryably', function () {
    Http::fake([JOB_ENDPOINT => Http::response(jobRefusal('internal', true), 500)]);

    $job = spyJob(jobAnnouncement());
    $job->handle(app(InternalActionClient::class));

    expect($job->failedWith)->toBeNull()
        ->and($job->releasedAfter)->toBe(5)
        ->and($job->lastResult)->toBeInstanceOf(InternalActionFailure::class);
});

it('honours the bot retry-after over its own backoff schedule', function () {
    // The bot knows when its token bucket refills; this schedule is guessing.
    Http::fake([JOB_ENDPOINT => Http::response(jobRefusal('rate_limited', true), 429, ['Retry-After' => '42'])]);

    $job = spyJob(jobAnnouncement());
    $job->handle(app(InternalActionClient::class));

    expect($job->releasedAfter)->toBe(42);
});

it('walks the backoff schedule as the attempts climb', function (int $attempt, int $delay) {
    Http::fake([JOB_ENDPOINT => Http::response(jobRefusal('internal', true), 500)]);

    $job = spyJob(jobAnnouncement(), $attempt);
    $job->handle(app(InternalActionClient::class));

    expect($job->releasedAfter)->toBe($delay);
})->with([[1, 5], [2, 15], [3, 60], [4, 180]]);

it('fails immediately on a terminal refusal rather than burning the backoff', function () {
    // `action_not_allowed` means a channel is not on an allowlist. No amount of
    // waiting adds one, and sitting in the queue only delays the alert.
    Http::fake([JOB_ENDPOINT => Http::response(jobRefusal('action_not_allowed', false), 403)]);

    $job = spyJob(jobAnnouncement());
    $job->handle(app(InternalActionClient::class));

    expect($job->releasedAfter)->toBeNull()
        ->and($job->failedWith?->getMessage())->toContain('action_not_allowed');
});

it('does not decide retryability from the http status', function (string $code, bool $retryable, ?int $released) {
    // Both of these are 409 and their answers are opposite: `in_progress` means
    // "ask again with the same key and a fresh nonce" and `replayed` means "I
    // have seen that nonce, and I always will". A job that branched on the
    // status would get exactly one of them wrong.
    Http::fake([JOB_ENDPOINT => Http::response(jobRefusal($code, $retryable), 409)]);

    $job = spyJob(jobAnnouncement());
    $job->handle(app(InternalActionClient::class));

    expect($job->releasedAfter)->toBe($released);

    $released === null
        ? expect($job->failedWith)->not->toBeNull()
        : expect($job->failedWith)->toBeNull();
})->with([
    'replayed is terminal' => ['replayed', false, null],
    'in_progress is retryable' => ['in_progress', true, 5],
]);

it('gives up once the retryable refusals have used the last attempt', function () {
    Http::fake([JOB_ENDPOINT => Http::response(jobRefusal('internal', true), 500)]);

    $job = spyJob(jobAnnouncement(), 5);
    $job->handle(app(InternalActionClient::class));

    expect($job->releasedAfter)->toBeNull()
        ->and($job->failedWith?->getMessage())->toContain('all 5 attempts');
});

it('releases rather than fails when the bot cannot be reached', function () {
    // Nothing happened at Discord, so this is a wait. Failing here is how a bot
    // deploy turns into a queue full of dead announcements.
    Http::fake(fn () => throw new ConnectionException('no route to host'));

    $job = spyJob(jobAnnouncement());
    $job->handle(app(InternalActionClient::class));

    expect($job->failedWith)->toBeNull()
        ->and($job->releasedAfter)->toBe(5);
});

it('fails terminally when the bot is not configured', function () {
    // Retrying a blank secret four more times produces four more identical lines
    // and no new information.
    config()->set('services.bot.secret', '');
    Http::fake();

    $job = spyJob(jobAnnouncement());
    $job->handle(app(InternalActionClient::class));

    expect($job->releasedAfter)->toBeNull()
        ->and($job->failedWith?->getMessage())->toContain('BOT_SHARED_SECRET');

    Http::assertNothingSent();
});

it('names the action in its display name for failed_jobs', function () {
    expect((new CallInternalAction(jobAnnouncement()))->displayName())->toEndWith(':announcement.post')
        ->and((new CallInternalAction(jobRole()))->displayName())->toEndWith(':role.assign');
});
