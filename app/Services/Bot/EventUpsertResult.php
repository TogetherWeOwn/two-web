<?php

namespace App\Services\Bot;

/**
 * A successful `event.upsert`.
 *
 * `discordEventId` is the thing worth keeping: store it against the event row
 * and the site can link a member straight to the Discord event.
 */
final readonly class EventUpsertResult
{
    /**
     * @param  string  $requestId  The join key between our logs and the bot's.
     * @param  bool  $replayed  True when the bot answered from its idempotency
     *                          store rather than calling Discord — the operation
     *                          happened on an earlier attempt. The body is
     *                          byte-identical either way, so ignoring this is
     *                          still correct; it is here so a retry can say
     *                          "already scheduled" instead of "scheduled".
     */
    public function __construct(
        public string $requestId,
        public EventUpsertOutcome $outcome,
        public string $discordEventId,
        public bool $replayed,
    ) {}
}
