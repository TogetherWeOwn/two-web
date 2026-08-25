<?php

namespace App\Services\Bot;

/**
 * One attempt at `POST /internal/actions`, turned into something a caller can
 * branch on without reading English.
 *
 * `docs/INTERNAL_ACTIONS.md` v0.3 §2 is explicit that `error.retryable` is
 * authoritative and that no response requires parsing prose to handle. This
 * class exists so that promise survives contact with our code: the only retry
 * signal anything downstream sees is {@see self::$retryable}, a bool.
 */
final class InternalActionResult
{
    /**
     * @param  int  $status  HTTP status, or 0 when the request never got an answer
     * @param  array<string, mixed>  $result  the `result` object on success, empty otherwise
     * @param  string|null  $errorCode  the `error.code` from §2's table
     * @param  bool  $replayed  `Idempotent-Replay: true` — the operation happened on an earlier attempt
     * @param  int|null  $retryAfter  seconds, from `Retry-After` on a 429
     */
    private function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly array $result = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $retryable = false,
        public readonly ?string $requestId = null,
        public readonly bool $replayed = false,
        public readonly ?int $retryAfter = null,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function success(int $status, array $result, ?string $requestId, bool $replayed): self
    {
        return new self(
            ok: true,
            status: $status,
            result: $result,
            requestId: $requestId,
            replayed: $replayed,
        );
    }

    public static function failure(
        int $status,
        ?string $code,
        ?string $message,
        bool $retryable,
        ?string $requestId,
        ?int $retryAfter = null,
    ): self {
        return new self(
            ok: false,
            status: $status,
            errorCode: $code,
            errorMessage: $message,
            retryable: $retryable,
            requestId: $requestId,
            retryAfter: $retryAfter,
        );
    }

    /**
     * The request never produced an HTTP response — DNS, connection refused,
     * TLS, or our own client-side timeout.
     *
     * Retryable, and that is a judgement rather than something §2 covers: the
     * bot is on the private network and the overwhelmingly likely cause is that
     * it is restarting. The risk being accepted is a re-send of an action whose
     * response we never saw; for the two *needs key* actions the idempotency key
     * makes that harmless, and `role.assign` is naturally idempotent. So every
     * action on this path is safe to re-send, which is what makes this the right
     * default rather than a hopeful one.
     */
    public static function transportFailure(string $message): self
    {
        return new self(
            ok: false,
            status: 0,
            errorCode: 'transport_failure',
            errorMessage: $message,
            retryable: true,
        );
    }

    /**
     * A one-line summary safe to log. Never includes the request body: §4 lists
     * announcement content among the things that are not logged, and the
     * simplest way to keep that true is to have no method that returns it.
     */
    public function summary(): string
    {
        return $this->ok
            ? sprintf('ok status=%d replay=%s request_id=%s', $this->status, $this->replayed ? 'true' : 'false', $this->requestId ?? '(none)')
            : sprintf('failed status=%d code=%s retryable=%s request_id=%s', $this->status, $this->errorCode ?? '(none)', $this->retryable ? 'true' : 'false', $this->requestId ?? '(none)');
    }
}
