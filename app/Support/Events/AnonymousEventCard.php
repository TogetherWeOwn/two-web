<?php

namespace App\Support\Events;

use App\Models\Event;
use Illuminate\Support\Facades\Cache;

/**
 * The anonymous fragment cache for calendar cards (TOG-9277).
 *
 * The events calendar is the hottest page and every guest render rebuilds
 * every card from scratch. The card partial (`partials.event-card`) cannot be
 * cached verbatim: it mounts two Livewire components per card, and a cached
 * fragment would hand every guest the same `wire:id`s. So guests get a static
 * rendering instead (`partials.event-card-anon`): the same markup, the going
 * badge as a plain span, the RSVP control as the login link. A guest has
 * nothing interactive here anyway — answering requires a session — so nothing
 * is lost, and there are no component snapshots to go stale.
 *
 * Signed-in members and Discord-native transients always render the live
 * partial fresh: members need working RSVP controls and a live badge, and
 * transients are built from the bot's database, outside our invalidation
 * entirely. Transients are also rare (one recurring row), so there is nothing
 * to win by caching them.
 *
 * Keys are version-stamped, not content-hashed: `bump()` writes one counter
 * per event and the counter is part of every fragment key, so an
 * invalidation is a single write rather than enumerating the
 * (event × past/upcoming × return-to) key space. `Event::saved` and
 * `Rsvp::saved`/`deleted` bump through the model hooks, plus an explicit bump
 * on the withdraw path whose mass delete bypasses model events; the 60-second
 * TTL is the staleness bound for write paths that bypass model events
 * (notably the `events:reconcile` mass update, which flips finished rows to
 * Past without touching a model instance).
 */
final class AnonymousEventCard
{
    /** Short on purpose: freshness beats hit rate on a page moderators edit. */
    public const TTL_SECONDS = 60;

    /**
     * The guest HTML for one card: cached for persisted rows, fresh for
     * transients and for signed-in members (whose cards carry live state).
     */
    public static function render(Event $event, bool $isPast, ?string $returnTo): string
    {
        if (auth()->check() || ! $event->exists) {
            return view('partials.event-card', [
                'event' => $event,
                'isPast' => $isPast,
            ])->render();
        }

        $version = Cache::get(self::versionKey($event), 0);

        return Cache::remember(
            self::fragmentKey($event, $isPast, $returnTo, $version),
            self::TTL_SECONDS,
            fn (): string => view('partials.event-card-anon', [
                'event' => $event,
                'isPast' => $isPast,
                'returnTo' => $returnTo,
            ])->render(),
        );
    }

    /**
     * Invalidate every cached fragment for this event. Called from
     * `Event::saved` (any moderator or service edit) and from RSVP writes via
     * `bumpForEventId()` — the badge count is part of the card, so an answer
     * that did not bump would leave guests reading the old number until TTL.
     */
    public static function bump(Event $event): void
    {
        $key = $event->getAttribute('event_key');

        if (! is_string($key) || $key === '') {
            return;
        }

        self::bumpForEventKey($key);
    }

    /** Bump without a model in hand (the RSVP observer only has the id). */
    public static function bumpForEventId(int $eventId): void
    {
        $key = Event::query()->whereKey($eventId)->value('event_key');

        if (is_string($key) && $key !== '') {
            self::bumpForEventKey($key);
        }
    }

    /**
     * Drop the counter for a deleted event. The row is gone so no new
     * fragment can render; orphaned fragments from the old version expire on
     * TTL. `event_key` is a ULID that is never reused, so resetting to the
     * default is safe.
     */
    public static function purge(Event $event): void
    {
        $key = $event->getAttribute('event_key');

        if (is_string($key) && $key !== '') {
            Cache::forget(self::versionKeyFor($key));
        }
    }

    /**
     * The actual counter write, in one place so every caller shares it.
     *
     * A get+put rather than `Cache::increment`: the database store (the
     * production default) returns false instead of seeding on a missing key,
     * so an increment-only bump would silently never invalidate there. A lost
     * race between two concurrent bumps is harmless — both move the version
     * away from what cached fragments carry, which is all invalidation needs.
     */
    private static function bumpForEventKey(string $eventKey): void
    {
        $key = self::versionKeyFor($eventKey);

        Cache::forever($key, (int) Cache::get($key, 0) + 1);
    }

    private static function versionKey(Event $event): string
    {
        return self::versionKeyFor((string) $event->getAttribute('event_key'));
    }

    private static function versionKeyFor(string $eventKey): string
    {
        return "anon-event-card-ver:{$eventKey}";
    }

    private static function fragmentKey(Event $event, bool $isPast, ?string $returnTo, mixed $version): string
    {
        $variant = $isPast ? 'past' : 'up';

        return "anon-event-card:v1:{$event->getAttribute('event_key')}:{$variant}:".($returnTo ?? 'none').":{$version}";
    }
}
