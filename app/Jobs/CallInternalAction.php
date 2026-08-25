<?php

namespace App\Jobs;

use App\Services\Bot\InternalAction;
use App\Services\Bot\InternalActionClient;
use App\Services\Bot\InternalActionFailed;
use App\Services\Bot\InternalActionMisconfigured;
use App\Services\Bot\InternalActionResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Calls one action on the bot, durably (TOG-470).
 *
 * `docs/INTERNAL_ACTIONS.md` v0.3 §5 sets the convention: **the website queues a
 * job, the job calls us.** A queue gives durable retries, which is what an
 * announcement that must eventually post needs. The one documented exception is
 * `guild.add_member`, which must stay synchronous so a live member credential is
 * never written into the `jobs` table — and which this class therefore cannot
 * send, because {@see InternalAction} has no constructor for it.
 *
 * ## The idempotency key is minted here, once, on purpose
 *
 * §1 calls the nonce-versus-key distinction "the one that will bite you", and
 * this is where the website either gets it right or does not:
 *
 *   - The **key** is generated in the constructor, so it is serialised into the
 *     queue payload. Every retry of *this job instance* — whether Laravel
 *     retried it or {@see self::handle()} released it — carries the same key,
 *     and the bot answers the second attempt from its idempotency store instead
 *     of posting a second announcement.
 *   - The **nonce** is minted inside {@see InternalActionClient::send()}, so it
 *     is fresh on every attempt. Reusing it is a `409 replayed` that never
 *     becomes anything else.
 *
 * The corollary is worth stating because it is the thing that looks like a bug
 * and is not: dispatching this job a second time for the same event is a *new*
 * operation with a *new* key, and the bot will act on it. That is correct — it
 * is how §3's "deliberate edit next week" reaches the same Discord event
 * through `event_key` without being swallowed as a duplicate.
 */
class CallInternalAction implements ShouldQueue
{
    use Queueable;

    /**
     * Four retries after the first attempt. Paired with {@see self::BACKOFF}
     * below, the last attempt lands a little over four minutes after the first,
     * which is longer than any bot deploy and short enough that an announcement
     * is not stale by the time it posts.
     */
    public int $tries = 5;

    /**
     * Seconds to wait before attempt 2, 3, 4 and 5. Consulted only when the bot
     * did not send a `Retry-After` of its own — a real rate-limit answer always
     * wins over this schedule, because the bot knows when its bucket refills and
     * we are guessing.
     *
     * @var list<int>
     */
    private const BACKOFF = [5, 15, 60, 180];

    /**
     * The outcome of the last attempt, for callers that run {@see self::handle()}
     * inline rather than through a worker — see the `bot:internal-action-smoke`
     * command. On a queue this is not read: `release()` and `fail()` are how a
     * worker learns what happened.
     */
    public ?InternalActionResult $lastResult = null;

    public readonly ?string $idempotencyKey;

    /**
     * @param  string|null  $idempotencyKey  pass one only to deliberately continue an
     *                                       earlier operation; leave it null and the
     *                                       right thing happens
     */
    public function __construct(
        public readonly InternalAction $action,
        ?string $idempotencyKey = null,
    ) {
        $this->idempotencyKey = $idempotencyKey
            ?? ($action->needsIdempotencyKey() ? (string) Str::uuid() : null);
    }

    public function handle(InternalActionClient $client): void
    {
        try {
            $result = $client->send($this->action, $this->idempotencyKey);
        } catch (InternalActionMisconfigured $e) {
            // Retrying a blank secret four more times produces four more
            // identical lines and no new information.
            $this->fail($e);

            return;
        }

        $this->lastResult = $result;

        if ($result->ok) {
            // Logged at info rather than dropped: §2 notes a replay means the
            // operation happened on an earlier attempt, which is the difference
            // between "posted" and "already posted" when someone asks later why
            // the timestamps do not line up.
            Log::info('internal action job complete', [
                'action' => $this->action->name,
                'replay' => $result->replayed,
                'request_id' => $result->requestId,
            ]);

            return;
        }

        // §2's `retryable` is authoritative: "if it is false, retrying will fail
        // the same way". So a `409 replayed` or a `422 discord_rejected` stops
        // here rather than burning four more attempts to reach the same answer.
        if (! $result->retryable) {
            $this->fail(InternalActionFailed::notRetryable($this->action->name, $result));

            return;
        }

        $attempts = max(1, $this->attempts());

        if ($attempts >= $this->tries) {
            $this->fail(InternalActionFailed::attemptsExhausted($this->action->name, $result, $attempts));

            return;
        }

        // Released rather than thrown so the bot's own `Retry-After` can set the
        // delay. Throwing would hand the timing to Laravel's backoff, which
        // cannot know when the token bucket refills.
        $this->release($result->retryAfter ?? $this->backoffFor($attempts));
    }

    /**
     * Seconds to wait before the attempt after `$attempts`.
     *
     * `$attempts` is 1-based and the guard above means it never reaches
     * `$tries`, so the index is always in range; the fallback is there so that
     * raising `$tries` without extending {@see self::BACKOFF} degrades to the
     * longest wait rather than to a division by nothing.
     */
    private function backoffFor(int $attempts): int
    {
        $backoff = self::BACKOFF;

        return $backoff[$attempts - 1] ?? $backoff[count($backoff) - 1];
    }

    /**
     * A stable description for logs and for `failed_jobs`.
     */
    public function displayName(): string
    {
        return static::class.':'.$this->action->name;
    }
}
