<?php

namespace App\Console\Commands;

use App\Enums\EventStatus;
use App\Jobs\SyncEventToDiscord;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Keep event state fresh, and clean up past events.
 *
 * This is the half of "degrades, never white-screens" that nobody sees. The queued
 * write-back can exhaust its five retries during a long Discord outage, and when it
 * does the RSVP is still committed and correct on the site while the Discord mirror
 * is stale — with nothing left on a queue to fix it. Without this command the
 * recovery depends on a member happening to change their answer again, which for a
 * quiet event is never.
 *
 * Two passes, deliberately in this order. Closing finished events first means the
 * sync pass cannot pick one up and upsert an event Discord has already dropped.
 */
class ReconcileEvents extends Command
{
    protected $signature = 'events:reconcile';

    protected $description = 'Re-sync events Discord never confirmed, and close events that have finished.';

    public function handle(): int
    {
        $closed = $this->closeFinishedEvents();
        $resynced = $this->resyncStaleEvents();

        $this->info("Closed {$closed} finished event(s); re-dispatched {$resynced} write-back(s).");

        if ($closed > 0 || $resynced > 0) {
            Log::info('Event reconcile pass completed.', ['closed' => $closed, 'resynced' => $resynced]);
        }

        return self::SUCCESS;
    }

    /**
     * An event whose end time has passed is over.
     *
     * `ends_at`, not `starts_at`: an event that started an hour ago is happening now,
     * and closing it would hide a live event from the calendar. Only Published moves
     * — a cancelled event stays cancelled, because "we called it off" and "it ran and
     * finished" are not the same story to show a member.
     */
    private function closeFinishedEvents(): int
    {
        return Event::query()
            ->where('status', EventStatus::Published)
            ->where('ends_at', '<', now())
            ->update(['status' => EventStatus::Past]);
    }

    /**
     * Events Discord may not agree with us about.
     *
     * Two shapes:
     *
     *   - never confirmed at all: published, and `discord_event_id` is still null;
     *   - confirmed, but carrying answers whose `synced_to_discord_at` is null.
     *
     * Only worth chasing while the event is still in the future — Discord manages its
     * own past, so a finished event is not a disagreement to fix. That is enforced by
     * `status = published` alone, because `closeFinishedEvents()` has already run and
     * moved every finished row to Past. An `ends_at >= now()` filter here would say the
     * same thing twice, and the cost of saying it twice is that neither copy can be
     * tested: with both in place, deleting either one leaves every test green. One
     * mechanism, pinned by `it closes finished events before it looks for stale ones`.
     *
     * The job is `ShouldBeUnique` on the event key, so a still-queued write-back
     * absorbs this dispatch rather than doubling it.
     */
    private function resyncStaleEvents(): int
    {
        $stale = Event::query()
            ->where('status', EventStatus::Published)
            ->where(function (Builder $query): void {
                $query->whereNull('discord_event_id')
                    ->orWhereHas('rsvps', fn (Builder $rsvps) => $rsvps->whereNull('synced_to_discord_at'));
            })
            ->get();

        foreach ($stale as $event) {
            SyncEventToDiscord::dispatch($event->event_key);
        }

        return $stale->count();
    }
}
