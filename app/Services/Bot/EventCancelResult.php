<?php

namespace App\Services\Bot;

/**
 * A successful `event.cancel`.
 *
 * `discordEventId` is the event that was moved to CANCELED, echoed back so the
 * site can prove the cancel landed on the mirror it meant rather than on
 * nothing at all.
 */
final readonly class EventCancelResult
{
    /**
     * @param  string  $requestId  The join key between our logs and the bot's.
     * @param  bool  $replayed  True when the bot answered from its idempotency
     *                          store rather than calling Discord — the cancel
     *                          happened on an earlier attempt. The body is
     *                          byte-identical either way, so ignoring this is
     *                          still correct; it is here so a retry can say
     *                          "already cancelled" instead of "cancelled".
     */
    public function __construct(
        public string $requestId,
        public EventCancelOutcome $outcome,
        public string $discordEventId,
        public bool $replayed,
    ) {}
}
