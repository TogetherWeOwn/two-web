<?php

namespace App\Jobs;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Services\Bot\EventCancel;
use App\Services\Bot\EventUpsert;
use App\Services\Bot\Exceptions\BotNotConfiguredException;
use App\Services\Bot\Exceptions\BotTransportException;
use App\Services\Bot\Exceptions\InvalidActionRequestException;
use App\Services\Bot\InternalActionClient;
use App\Services\Bot\InternalActionFailure;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The write-back: one Discord scheduled event, kept in step with our row.
 *
 * Everything slow or failure-prone about TOG-52 is deliberately in here, because
 * this is the only part of the flow no member is waiting on. The RSVP is already
 * committed before this is dispatched — see EventService::syncAfterCommit — so
 * every outcome below is a decision about *when to try again*, never about whether
 * the member's answer was recorded.
 *
 * Three decisions, each with a wrong version that passes a happy-path test:
 *
 *  - **The idempotency key is minted in the constructor, not in handle().** It has
 *    to identify the operation across attempts. Minted per attempt, a retry after a
 *    timeout we never saw the answer to creates a *second* Discord event. The job is
 *    serialised onto the queue with the key inside it, so a release and a later
 *    replay carry the same one.
 *
 *  - **Retry is decided by the bot's `retryable` flag, never by the HTTP status.**
 *    `in_progress` and `replayed` are both 409 and their answers are opposite.
 *
 *  - **A terminal refusal fails now.** `action_not_allowed` means an action or
 *    channel is not on an allowlist; no amount of backoff adds one. Sitting in the
 *    queue for an hour first only delays the alert to whoever runs the bot.
 */
class SyncEventToDiscord implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Long enough to swallow a burst of answers, short enough that nobody notices. */
    public const DEBOUNCE_SECONDS = 10;

    /**
     * How long the uniqueness lock is held if a worker dies mid-job. Comfortably
     * longer than the debounce, so a crash costs one stale window and not a
     * permanently un-syncable event.
     */
    public int $uniqueFor = 300;

    /**
     * Attempts, and the gaps between them: 10s, 1m, 5m, 15m, 1h.
     *
     * The last gap is the point. A Discord outage is measured in tens of minutes, and
     * a policy that spends every attempt inside the first two has given up before the
     * thing it is waiting for could plausibly have ended.
     *
     * @var list<int>
     */
    public array $backoff = [10, 60, 300, 900, 3600];

    public int $tries = 6;

    /**
     * The key for this operation, fixed at construction and carried across retries.
     *
     * Public and readonly so a test can prove it does not change. That is the failure
     * worth guarding: a fresh key per attempt is invisible until the day the bot times
     * out and a member sees the same event in Discord twice.
     */
    public readonly string $idempotencyKey;

    public function __construct(public readonly string $eventKey)
    {
        $this->idempotencyKey = InternalActionClient::newIdempotencyKey();
        $this->delay = now()->addSeconds(self::DEBOUNCE_SECONDS);
    }

    public function uniqueId(): string
    {
        return $this->eventKey;
    }

    public function handle(InternalActionClient $bot): void
    {
        $event = Event::query()->where('event_key', $this->eventKey)->first();

        // Deleted while this sat on the queue. Nothing to mirror and nothing wrong:
        // drop it rather than failing something nobody can act on.
        if (! $event instanceof Event) {
            $this->delete();

            return;
        }

        // A draft has never been announced, so there is no Discord event to update and
        // creating one would publish it early. Past is Discord's to forget.
        if (! $event->isMirroredInDiscord()) {
            return;
        }

        // A cancelled event must never go through event.upsert: the bot would
        // re-receive what looks like a live event and the Discord mirror would
        // stay live after the cancel (TOG-5863). Cancelling is a distinct action.
        if ($event->status === EventStatus::Cancelled) {
            $this->handleCancelled($event, $bot);

            return;
        }

        try {
            $answer = $bot->upsertEvent($this->payloadFor($event), $this->idempotencyKey);
        } catch (BotTransportException $e) {
            // We could not ask. The row is committed and correct, so this is a wait,
            // not a failure — this is the bot-is-down path and it must never fail().
            Log::warning('Event write-back could not reach the bot; will retry.', [
                'event_key' => $this->eventKey,
                'attempt' => $this->attempts(),
                'reason' => $e->getMessage(),
            ]);

            $this->release($this->nextDelay());

            return;
        } catch (BotNotConfiguredException|InvalidActionRequestException $e) {
            // Terminal by construction: a missing secret, or a payload the bot would
            // call `malformed`. A retry re-sends identical bytes to the same place.
            $this->failWith($e->getMessage(), $e);

            return;
        }

        if ($answer instanceof InternalActionFailure) {
            $this->handleRefusal($answer, 'event.upsert');

            return;
        }

        $this->recordSuccess($event, $answer->discordEventId, 'event.upsert');
    }

    /**
     * A cancelled event leaves Discord through `event.cancel`, never `event.upsert`.
     *
     * The bot moves its mapped event to CANCELED and keeps the mapping, so a
     * delayed upsert cannot resurrect it — but only `event.cancel` moves it at
     * all. Sending the cancelled row through the upsert re-receives what looks
     * like a live event, which is exactly the bug this branch exists to close.
     */
    private function handleCancelled(Event $event, InternalActionClient $bot): void
    {
        // No proven mirror, nothing to cancel. The bot only knows keys a
        // successful upsert mapped, and this row records that mapping only on
        // success — so a null here means Discord holds nothing for this key
        // (publish-then-cancel inside the debounce window, usually). Cancelling
        // it would earn a terminal `action_not_allowed` for an end state that
        // already holds: a failure nobody can act on.
        if ($event->discord_event_id === null) {
            $this->markCancelledWithoutMirror($event);

            return;
        }

        try {
            $answer = $bot->cancelEvent(new EventCancel($event->event_key), $this->idempotencyKey);
        } catch (BotTransportException $e) {
            // Same as the upsert path: we could not ask, so wait rather than fail.
            Log::warning('Event cancel could not reach the bot; will retry.', [
                'event_key' => $this->eventKey,
                'attempt' => $this->attempts(),
                'reason' => $e->getMessage(),
            ]);

            $this->release($this->nextDelay());

            return;
        } catch (BotNotConfiguredException|InvalidActionRequestException $e) {
            $this->failWith($e->getMessage(), $e);

            return;
        }

        if ($answer instanceof InternalActionFailure) {
            $this->handleRefusal($answer, 'event.cancel');

            return;
        }

        $this->recordSuccess($event, $answer->discordEventId, 'event.cancel');
    }

    /**
     * The event was called off before Discord ever saw it. There is no mirror
     * to disagree with us, so the answers are marked synced rather than left
     * reading "Syncing to Discord" forever under a "Cancelled" banner.
     */
    private function markCancelledWithoutMirror(Event $event): void
    {
        $syncedAt = now();

        $event->rsvps()
            ->whereNull('synced_to_discord_at')
            ->where('updated_at', '<=', $syncedAt)
            ->update(['synced_to_discord_at' => $syncedAt]);

        Log::info('Event cancelled before it was ever mirrored; nothing to tell Discord.', [
            'event_key' => $this->eventKey,
        ]);
    }

    /** The bot answered, and the answer was no. */
    private function handleRefusal(InternalActionFailure $failure, string $action): void
    {
        $context = [
            'event_key' => $this->eventKey,
            'action' => $action,
            // The join key between our logs and the bot's. Without it, a failure here
            // and its cause over there cannot be lined up.
            'request_id' => $failure->requestId,
            'code' => $failure->code,
            'status' => $failure->status,
            'attempt' => $this->attempts(),
        ];

        if (! $failure->retryable) {
            Log::error('Event write-back refused terminally by the bot.', $context + [
                'message' => $failure->message,
            ]);

            $this->fail(new RuntimeException(
                "The bot refused {$action} for {$this->eventKey} with `{$failure->code}`: {$failure->message}"
            ));

            return;
        }

        Log::warning('Event write-back refused; will retry.', $context);

        // The bot's number beats ours: on a 429 it knows where the ceiling is.
        $this->release($failure->retryAfterSeconds ?? $this->nextDelay());
    }

    private function recordSuccess(Event $event, string $discordEventId, string $action): void
    {
        $mirroredAt = now();

        DB::transaction(function () use ($event, $discordEventId, $mirroredAt): void {
            $event->forceFill(['discord_event_id' => $discordEventId])->save();

            // Only the answers this call actually mirrored. Anything that changed while
            // the request was in flight keeps its null and is picked up by the job that
            // change dispatched; stamping those would mark unsynced work as done and
            // the reconcile pass would never look at it again.
            $event->rsvps()
                ->whereNull('synced_to_discord_at')
                ->where('updated_at', '<=', $mirroredAt)
                ->update(['synced_to_discord_at' => $mirroredAt]);
        });

        Log::info($action === 'event.cancel' ? 'Event cancellation mirrored to Discord.' : 'Event mirrored to Discord.', [
            'event_key' => $this->eventKey,
            'action' => $action,
            'discord_event_id' => $discordEventId,
        ]);
    }

    private function payloadFor(Event $event): EventUpsert
    {
        return new EventUpsert(
            eventKey: $event->event_key,
            name: $event->title,
            startsAt: $event->starts_at,
            endsAt: $event->ends_at,
            // `location`, never `channel_key`: the bot's channel-key map starts empty,
            // so a channel-keyed event is `action_not_allowed` everywhere nobody has
            // configured one. EventUpsert only knows how to send an external event.
            location: $event->location ?? 'The TWO Discord',
            description: $event->description,
        );
    }

    private function failWith(string $message, ?Throwable $previous): void
    {
        Log::error('Event write-back cannot proceed.', [
            'event_key' => $this->eventKey,
            'reason' => $message,
        ]);

        $this->fail(new RuntimeException($message, 0, $previous));
    }

    /** The gap before the next attempt, holding at the last value once past the end. */
    private function nextDelay(): int
    {
        return $this->backoff[$this->attempts() - 1] ?? $this->backoff[count($this->backoff) - 1];
    }
}
