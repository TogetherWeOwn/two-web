<?php

namespace App\Services\Bot;

/**
 * A successful `role.assign`.
 *
 * There is no `replayed` flag here, unlike the other two results. This action
 * sends no `Idempotency-Key`, so the bot has no idempotency store to answer
 * from and never sets `Idempotent-Replay` — a repeat reaches Discord and comes
 * back `already_held`, which is the natural idempotency doing the same job.
 */
final readonly class RoleAssignResult
{
    /** @param string $requestId The join key between our logs and the bot's. */
    public function __construct(
        public string $requestId,
        public RoleAssignOutcome $outcome,
    ) {}
}
