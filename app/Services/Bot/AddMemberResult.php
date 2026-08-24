<?php

namespace App\Services\Bot;

use App\Enums\JoinOutcome;

/**
 * What came back from one `guild.add_member` call.
 *
 * Deliberately not an exception. A failure here is an ordinary, expected branch
 * — the bot is down, or the CEO has not switched the action on yet — and the
 * site's answer is always the same: show the invite link. Exceptions would make
 * the normal path read like a disaster.
 *
 * `errorCode` and `retryable` come straight from the bot's typed envelope
 * (docs/INTERNAL_ACTIONS.md §2). The site branches on those two, never on the
 * English in `message`, which is why the message is not kept here at all.
 *
 * The join page shows one sentence for every failure, so it reads `outcome` and
 * nothing else. The other three are here because they are the envelope: they are
 * what a caller needs to tell "switched off" from "Discord is down", and
 * `requestId` is the join key between this site's logs and the bot's. Losing
 * them at this boundary would mean re-parsing the response to get them back.
 */
final readonly class AddMemberResult
{
    private function __construct(
        public ?JoinOutcome $outcome,
        public ?string $errorCode,
        public bool $retryable,
        public ?string $requestId,
    ) {}

    public static function succeeded(JoinOutcome $outcome, ?string $requestId = null): self
    {
        return new self($outcome, null, false, $requestId);
    }

    /**
     * `$retryable` is the bot's own boolean where it gave us one. It is not
     * advice we compute: if the bot says false, retrying fails the same way.
     */
    public static function failed(string $errorCode, bool $retryable, ?string $requestId = null): self
    {
        return new self(null, $errorCode, $retryable, $requestId);
    }
}
