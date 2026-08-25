<?php

namespace App\Services\Bot;

use RuntimeException;

/**
 * A call to the bot that will not be retried — either §2 said `retryable:
 * false`, or it said true and we ran out of attempts.
 *
 * This is what lands in `failed_jobs`, so the message is written to be read
 * from there with no other context: it names the action, the error code, and
 * the `request_id`. §2 calls `request_id` the join key between our logs and the
 * bot's, and §4 says every request has a row in `internal_action_log` keyed by
 * it — so that one string is the difference between "an announcement failed"
 * and a two-command diagnosis.
 */
class InternalActionFailed extends RuntimeException
{
    public function __construct(
        public readonly string $action,
        public readonly InternalActionResult $result,
        string $reason,
    ) {
        parent::__construct(sprintf(
            '%s %s: %s (%s)',
            $action,
            $reason,
            $result->summary(),
            $result->errorMessage ?? 'no message',
        ));
    }

    public static function notRetryable(string $action, InternalActionResult $result): self
    {
        return new self($action, $result, 'failed and is not retryable');
    }

    public static function attemptsExhausted(string $action, InternalActionResult $result, int $attempts): self
    {
        return new self($action, $result, "gave up after {$attempts} attempt(s)");
    }
}
