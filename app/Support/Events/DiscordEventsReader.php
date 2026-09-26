<?php

namespace App\Support\Events;

use App\Enums\EventStatus;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The guild's scheduled events, read from the bot's `web_v1` views.
 *
 * This is the Discord→web half the events page was missing: the page read
 * only its own `events` table while the guild's recurring event lived in the
 * bot's database, so production rendered the never-scheduled empty state with
 * a live Sunday Squad sitting in Discord.
 *
 * Read-only by construction. These rows are display-only: they are never
 * persisted, never published, and never handed to the Discord write-back, so
 * this cannot create a second Discord event. An RSVP has nowhere to go on
 * this side — the card points at the Discord invite instead.
 *
 * Follows the CountsReader contract: one cached `select` against a versioned
 * view, and never a throw. An unreachable bot database degrades to no rows,
 * not to a broken page.
 */
final readonly class DiscordEventsReader implements DiscordEventsSource
{
    /**
     * The bot's scheduled-events collector runs every ten minutes, so a
     * shorter cache is load with no fresher answer.
     */
    private const CACHE_SECONDS = 600;

    private const UPCOMING_KEY = 'events.discord-upcoming';

    /**
     * Discord's collector stores no end time, so a display row assumes the
     * Sunday Squad shape: about an hour. This only feeds the card's end time
     * and the upcoming/past split — it is not published anywhere as the
     * event's real length.
     */
    private const ASSUMED_DURATION_HOURS = 1;

    public function __construct(
        private DatabaseManager $db,
        private CacheRepository $cache,
    ) {}

    /**
     * @return list<Event> Transient models (`exists === false`): safe to render,
     *                     never to save or hand to the write-back.
     */
    public function upcoming(): array
    {
        try {
            return $this->cache->remember(self::UPCOMING_KEY, self::CACHE_SECONDS, function (): array {
                $rows = $this->db->connection('bot')
                    ->select('select event_id, name, starts_at, channel_id, description from web_v1.upcoming_events order by starts_at');

                return array_values(array_filter(array_map(
                    fn (object $row): ?Event => $this->toEvent($row),
                    $rows,
                )));
            });
        } catch (Throwable $e) {
            // Never the exception message: a PDO failure can carry the DSN.
            // The class name says which kind of failure it was.
            Log::warning('Discord events unavailable; rendering the calendar without them.', [
                'exception' => $e::class,
            ]);

            return [];
        }
    }

    private function toEvent(object $row): ?Event
    {
        $startsAt = $this->timestampOrNull($row->starts_at ?? null);
        $name = is_string($row->name ?? null) ? trim((string) $row->name) : '';
        $eventId = is_string($row->event_id ?? null) ? (string) $row->event_id : '';

        if ($startsAt === null || $name === '' || $eventId === '') {
            // A row we cannot place on the calendar or name is not an event
            // we can honestly show. Drop it rather than render a dateless card.
            return null;
        }

        $event = new Event([
            'title' => $name,
            'game' => null,
            'description' => is_string($row->description ?? null) ? (string) $row->description : null,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHours(self::ASSUMED_DURATION_HOURS),
            'timezone' => 'UTC',
            'location' => 'Discord',
            'capacity' => null,
            'status' => EventStatus::Published,
            'discord_event_id' => $eventId,
        ]);

        // Synthetic and namespaced: it can never collide with a ULID the model
        // generates, and the `discord:` prefix says where the row came from.
        // Set directly — the key is fillable-guarded on purpose, and this is
        // the one caller allowed to mint one.
        $event->setAttribute('event_key', "discord:{$eventId}");
        // No local answers exist for a Discord-native row. Null (not zero) so
        // the card omits the count badge rather than publishing "0 going".
        $event->setAttribute('going_count', null);
        $event->exists = false;

        return $event;
    }

    /**
     * The contract returns every timestamp as ISO-8601 UTC text, so this parses
     * rather than trusting the driver to have made a date of it. A value we
     * cannot parse is treated as absent: an event we cannot place in time is
     * one we must not show.
     */
    private function timestampOrNull(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
