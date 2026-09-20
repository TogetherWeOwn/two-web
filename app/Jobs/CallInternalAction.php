<?php

namespace App\Jobs;

use App\Services\Bot\Announcement;
use App\Services\Bot\AnnouncementResult;
use App\Services\Bot\Exceptions\BotNotConfiguredException;
use App\Services\Bot\Exceptions\BotTransportException;
use App\Services\Bot\Exceptions\InvalidActionRequestException;
use App\Services\Bot\InternalActionClient;
use App\Services\Bot\InternalActionFailure;
use App\Services\Bot\RoleAssignment;
use App\Services\Bot\RoleAssignResult;
use App\Services\Bot\SettingMutation;
use App\Services\Bot\SettingWriteResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Calls `role.assign`, `announcement.post` or `settings.set` on the bot, durably.
 *
 * `docs/INTERNAL_ACTIONS.md` §5 sets the convention: **the website queues a job,
 * the job calls us.** A queue gives durable retries, which is what an
 * announcement that must eventually post needs.
 *
 * `event.upsert` is deliberately not here. It has its own job — see
 * {@see SyncEventToDiscord} — because mirroring an event is not just a call: it
 * reads the row, skips drafts, writes back `discord_event_id` and stamps the
 * RSVPs it mirrored. Folding that into a generic sender would either bloat this
 * class with event knowledge or lose the write-back.
 *
 * `guild.add_member` is not here either, and cannot be: §5 requires it to be
 * *synchronous* precisely so a live member credential is never written into the
 * `jobs` table, where it would sit in the queue, in `failed_jobs` on error, and
 * in every backup taken afterwards. {@see InternalActionClient} has no method
 * for it, so this class has nothing to send.
 *
 * ## The idempotency key is minted in the constructor, on purpose
 *
 * §1 calls the nonce-versus-key distinction "the one that will bite you", and
 * this is where the website either gets it right or does not:
 *
 * - The **key** is generated here, so it is serialised into the queue payload.
 *   Every retry of *this job instance* — Laravel's, or a `release()` below —
 *   carries the same key, and the bot answers the second attempt from its
 *   idempotency store instead of repeating the announcement or settings write.
 * - The **nonce** is minted inside the client, per attempt, so it is always
 *   fresh. Reusing one is a `409 replayed` that never becomes anything else.
 *
 * The corollary is the thing that looks like a bug and is not: constructing
 * this job a second time normally creates a *new* operation with a *new* key,
 * and the bot will act on it. The one exception is an explicit reconstruction
 * with the prior key after an uncertain synchronous response.
 *
 * A `role.assign` gets no key at all. Its idempotency is natural (§3) and the
 * client refuses to send a key for it, so a retry simply asks again and comes
 * back `already_held`.
 */
class CallInternalAction implements ShouldQueue
{
    use Queueable;

    /**
     * Four retries after the first attempt. Paired with {@see self::BACKOFF},
     * the last lands a little over four minutes after the first — longer than a
     * bot deploy, and short enough that an announcement is not stale when it
     * posts.
     */
    public int $tries = 5;

    /**
     * Seconds before attempts 2, 3, 4 and 5. Consulted only when the bot sent no
     * `Retry-After` of its own: a real rate-limit answer always wins, because
     * the bot knows when its bucket refills and this schedule is guessing.
     *
     * @var list<int>
     */
    private const BACKOFF = [5, 15, 60, 180];

    /**
     * The key for this operation, fixed at construction and carried across every
     * attempt. Null for `role.assign`, which sends none.
     *
     * Public and readonly so a test can prove it does not change between
     * attempts. That is the failure worth guarding: a fresh key per attempt is
     * invisible until the day the bot times out and the announcement posts twice.
     */
    public readonly ?string $idempotencyKey;

    /**
     * The outcome of the last attempt, for callers that run {@see self::handle()}
     * inline rather than through a worker — see `bot:internal-action-smoke`. On a
     * queue this is not read: `release()` and `fail()` are how a worker learns
     * what happened.
     */
    public RoleAssignResult|AnnouncementResult|SettingWriteResult|InternalActionFailure|null $lastResult = null;

    public function __construct(
        public readonly RoleAssignment|Announcement|SettingMutation $action,
        ?string $idempotencyKey = null,
    ) {
        // Typed, not flagged: the value object decides whether §3 calls this
        // action *needs key*, so no caller has to remember which is which.
        if ($action instanceof RoleAssignment) {
            if ($idempotencyKey !== null) {
                throw new InvalidActionRequestException('A naturally idempotent role.assign must not carry an idempotency key.');
            }

            $this->idempotencyKey = null;

            return;
        }

        // A synchronous caller can preserve the operation key after an uncertain
        // response and reconstruct this job for a safe retry. The client validates
        // supplied keys before any request is sent.
        $this->idempotencyKey = $idempotencyKey ?? InternalActionClient::newIdempotencyKey();
    }

    /** The action name as it goes on the wire, for logs and `failed_jobs`. */
    public function actionName(): string
    {
        return match (true) {
            $this->action instanceof Announcement => 'announcement.post',
            $this->action instanceof SettingMutation => 'settings.set',
            default => 'role.assign',
        };
    }

    public function displayName(): string
    {
        return static::class.':'.$this->actionName();
    }

    public function handle(InternalActionClient $bot): void
    {
        try {
            $answer = $this->runInline($bot);
        } catch (BotTransportException $e) {
            // We could not ask. Nothing has happened at Discord, so this is a
            // wait rather than a failure — the bot-is-down path, which must
            // never fail().
            Log::warning('Internal action could not reach the bot; will retry.', [
                'action' => $this->actionName(),
                'attempt' => $this->attempts(),
                'reason' => $e->getMessage(),
            ]);

            $this->release($this->backoffFor($this->attempts()));

            return;
        } catch (BotNotConfiguredException|InvalidActionRequestException $e) {
            // Terminal by construction: a missing secret, or a payload the bot
            // would call `malformed`. Retrying re-sends identical bytes to the
            // same place, and four more attempts produce four more identical
            // lines and no new information.
            $this->failWith($e->getMessage(), $e);

            return;
        }

        if ($answer instanceof InternalActionFailure) {
            $this->handleRefusal($answer);
        }
    }

    /**
     * Make the job's single attempt in a request that needs the typed answer.
     *
     * This keeps synchronous admin writes on the same idempotency-owning path as
     * queued work. It deliberately does not retry: the caller must present the
     * refusal or transport failure rather than hiding a second request inside the
     * browser response.
     */
    public function runInline(InternalActionClient $bot): RoleAssignResult|AnnouncementResult|SettingWriteResult|InternalActionFailure
    {
        $answer = $this->call($bot);
        $this->lastResult = $answer;

        if (! $answer instanceof InternalActionFailure) {
            $this->recordSuccess($answer);
        }

        return $answer;
    }

    private function call(InternalActionClient $bot): RoleAssignResult|AnnouncementResult|SettingWriteResult|InternalActionFailure
    {
        if ($this->action instanceof Announcement) {
            return $bot->postAnnouncement($this->action, (string) $this->idempotencyKey);
        }

        if ($this->action instanceof SettingMutation) {
            return $bot->setSetting($this->action, (string) $this->idempotencyKey);
        }

        return $bot->assignRole($this->action);
    }

    private function recordSuccess(RoleAssignResult|AnnouncementResult|SettingWriteResult $result): void
    {
        $context = [
            'action' => $this->actionName(),
            // The join key between our logs and the bot's `internal_action_log`.
            'request_id' => $result->requestId,
        ];

        if ($result instanceof AnnouncementResult) {
            // §4's rules apply to us: the announcement body is never logged.
            // Whether it replayed is, because it is the difference between
            // "posted" and "already posted" when somebody later asks why the
            // timestamps do not line up.
            $context += ['message_id' => $result->messageId, 'replayed' => $result->replayed];
        } elseif ($result instanceof SettingWriteResult) {
            // Values can contain moderation vocabulary or channel configuration;
            // neither belongs in logs. The key, outcome and replay state are
            // enough to join this line to the bot's append-only audit row.
            $context += [
                'setting_key' => $result->key,
                'outcome' => $result->outcome->value,
                'replayed' => $result->replayed,
            ];
        } else {
            $context += ['outcome' => $result->outcome->value];
        }

        Log::info('Internal action complete.', $context);
    }

    /** The bot answered, and the answer was no. */
    private function handleRefusal(InternalActionFailure $failure): void
    {
        $context = [
            'action' => $this->actionName(),
            'request_id' => $failure->requestId,
            'code' => $failure->code,
            'status' => $failure->status,
            'attempt' => $this->attempts(),
        ];

        // §2's `retryable` is authoritative, and the status is exactly what gets
        // this wrong: `in_progress` and `replayed` are both 409 and their answers
        // are opposite. A terminal refusal fails now rather than burning the
        // whole backoff to reach the same answer.
        if (! $failure->retryable) {
            Log::error('Internal action refused terminally by the bot.', $context + ['message' => $failure->message]);

            $this->fail(new RuntimeException(
                "The bot refused {$this->actionName()} with `{$failure->code}`: {$failure->message}"
            ));

            return;
        }

        $attempts = max(1, $this->attempts());

        if ($attempts >= $this->tries) {
            Log::error('Internal action gave up after the last attempt.', $context);

            $this->fail(new RuntimeException(
                "The bot refused {$this->actionName()} with a retryable `{$failure->code}` on all {$attempts} attempts."
            ));

            return;
        }

        Log::warning('Internal action refused; will retry.', $context);

        // Released rather than thrown so the bot's own `Retry-After` can set the
        // delay. Throwing hands the timing to Laravel's backoff, which cannot
        // know when the token bucket refills.
        $this->release($failure->retryAfterSeconds ?? $this->backoffFor($attempts));
    }

    private function failWith(string $message, Throwable $previous): void
    {
        Log::error('Internal action cannot proceed.', [
            'action' => $this->actionName(),
            'reason' => $message,
        ]);

        $this->fail(new RuntimeException($message, 0, $previous));
    }

    /**
     * The gap before the attempt after `$attempts`, 1-based, holding at the last
     * value once past the end — so raising `$tries` without extending
     * {@see self::BACKOFF} degrades to the longest wait rather than to nothing.
     */
    private function backoffFor(int $attempts): int
    {
        return self::BACKOFF[max(1, $attempts) - 1] ?? self::BACKOFF[count(self::BACKOFF) - 1];
    }
}
