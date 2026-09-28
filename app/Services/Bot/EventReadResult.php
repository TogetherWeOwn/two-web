<?php

namespace App\Services\Bot;

/**
 * A successful `event.read`: the owned mirror, and nothing else.
 *
 * Only proof-owned fields cross this boundary: the event id, name, start,
 * location and lifecycle the bot mapped for this key, plus when the bot
 * observed them. Attendees, other guild events and member data never enter
 * this object, so a caller that holds it cannot leak what it never received.
 */
final readonly class EventReadResult
{
    /**
     * @param  string  $requestId  The join key between our logs and the bot's.
     * @param  string  $discordEventId  The mapped mirror, echoed so the
     *                                  verification can be matched to the row.
     * @param  string  $name  The mirror's name, for field comparison.
     * @param  string  $startsAt  Zulu instant, for field comparison.
     * @param  string|null  $location  The mirror's location, if it has one.
     * @param  string  $status  The mirror's lifecycle, verbatim from Discord.
     * @param  string  $observedAt  When the bot read this, Zulu.
     * @param  bool  $replayed  True when answered from the bot's idempotency
     *                          store. A read changes nothing, so this is
     *                          informational only.
     */
    public function __construct(
        public string $requestId,
        public string $discordEventId,
        public string $name,
        public string $startsAt,
        public ?string $location,
        public string $status,
        public string $observedAt,
        public bool $replayed,
    ) {}
}
