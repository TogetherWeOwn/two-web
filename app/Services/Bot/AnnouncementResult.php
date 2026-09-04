<?php

namespace App\Services\Bot;

/**
 * A successful `announcement.post`.
 *
 * `messageId` comes back on a replay too, which is what makes a retry safe to
 * report on: the same id twice is the proof that nothing posted twice. Store it
 * and the site can link to the message it just published.
 */
final readonly class AnnouncementResult
{
    /**
     * @param  string  $requestId  The join key between our logs and the bot's.
     * @param  bool  $replayed  True when the bot answered from its idempotency
     *                          store rather than posting again — the message
     *                          went out on an earlier attempt. The body is
     *                          byte-identical either way, so ignoring this is
     *                          still correct; it is here so a retry can say
     *                          "already posted" instead of "posted".
     */
    public function __construct(
        public string $requestId,
        public string $messageId,
        public bool $replayed,
    ) {}
}
