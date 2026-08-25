<?php

namespace App\Services\Bot;

/**
 * The bot answered, and the answer was no.
 *
 * This is a value, not an exception, because a failure here is an ordinary
 * outcome that the caller has to make a decision about — and the decision is
 * always the same one: `retryable`. Everything else on this object is for logs
 * and for the message a member eventually reads.
 *
 * A failure to *reach* the bot is not this: that is a BotTransportException,
 * because there is no request_id to log and no authoritative flag to trust.
 */
final readonly class InternalActionFailure
{
    /**
     * @param  string  $code  The raw wire value, kept verbatim so a code we have
     *                        never heard of still reaches the logs intact.
     * @param  bool  $retryable  Authoritative from the bot where it sent one,
     *                           otherwise the published table for a known code,
     *                           otherwise false.
     * @param  string  $requestId  The join key between our logs and the bot's.
     * @param  int|null  $retryAfterSeconds  Only ever set on a 429.
     */
    public function __construct(
        public string $code,
        public bool $retryable,
        public string $message,
        public string $requestId,
        public int $status,
        public ?int $retryAfterSeconds = null,
    ) {}

    /**
     * The enum case for this code, or null for one this release does not know.
     *
     * Callers that want to branch on a specific failure — showing "that channel
     * is not set up" for `action_not_allowed`, say — match on this. Callers that
     * only want to know whether to try again read `retryable` and ignore it.
     */
    public function knownCode(): ?InternalActionErrorCode
    {
        return InternalActionErrorCode::tryFrom($this->code);
    }
}
