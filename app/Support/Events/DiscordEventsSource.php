<?php

namespace App\Support\Events;

use App\Models\Event;

/**
 * Something that supplies the guild's Discord-native events for the calendar.
 *
 * The page depends on the interface rather than on the reader, so it depends
 * on "something that supplies events" and not on the bot's database being
 * reachable — which is also the seam the degraded state is tested through.
 */
interface DiscordEventsSource
{
    /**
     * @return list<Event> Transient models: safe to render, never to save.
     */
    public function upcoming(): array;

    /**
     * Whether the most recent `upcoming()` read failed (TOG-5318).
     *
     * The empty-vs-failure branch the error empty state hangs on: `[]` from
     * `upcoming()` means "no events" only when this is false. A failed read
     * must render the error state, never the never-scheduled one.
     */
    public function lastReadFailed(): bool;
}
