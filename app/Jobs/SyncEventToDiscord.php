<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The seam. TOG-52c fills in the body; this exists so the domain has something
 * real to dispatch and so the shape is settled before two people build against it.
 *
 * Two things about it are decisions, not placeholders:
 *
 *  - It carries the `event_key`, not the model and not the id. The job is the last
 *    thing standing between us and the bot, and the bot only knows this string.
 *    Passing the model would also mean a serialised row that is already stale by
 *    the time the worker picks it up; re-read it in handle() instead.
 *
 *  - ShouldBeUnique, keyed on that same string, with a delay. There is no per-member
 *    RSVP verb in the bot's allowlist and there will not be one, so an RSVP change
 *    is a re-upsert of the whole event. Twenty members answering in a minute must
 *    be one Discord edit, not twenty: the bot's budget is 60 requests a minute for
 *    the entire site and one popular event would spend a third of it.
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

    public function __construct(public readonly string $eventKey)
    {
        $this->delay = now()->addSeconds(self::DEBOUNCE_SECONDS);
    }

    public function uniqueId(): string
    {
        return $this->eventKey;
    }

    public function handle(): void
    {
        // TOG-52c: read the event by $this->eventKey, build the event.upsert body
        // (location, never channel_key), send it through the InternalActionClient
        // that TOG-52b is building, and stamp synced_to_discord_at on success.
        //
        // Deliberately does nothing until then. An RSVP is committed and visible
        // with synced_to_discord_at still null, which is exactly what a member sees
        // while the bot is down, so shipping this empty leaves the site correct —
        // just not yet mirrored.
    }
}
