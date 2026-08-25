<?php

namespace App\Services\Bot;

/**
 * The bot's error codes, from `docs/INTERNAL_ACTIONS.md` §2 at v0.3.
 *
 * The `retryable` boolean on the wire is authoritative and this enum does not
 * override it — see InternalActionFailure. What this table is for is the case
 * where the flag is missing: an older bot, a proxy that mangled the body. The
 * fallback has to be the published table rather than the HTTP status, because
 * the status is exactly what gets this wrong: `in_progress` and `replayed` are
 * both 409 and their answers are opposite.
 */
enum InternalActionErrorCode: string
{
    /** 400 — body did not parse, or a field is missing or the wrong type. */
    case Malformed = 'malformed';

    /** 401 — bad signature, unknown key id, or missing auth headers. */
    case Unauthorized = 'unauthorized';

    /** 401 — timestamp outside the ±120s skew window. Check NTP on both sides. */
    case StaleRequest = 'stale_request';

    /** 403 — well-formed, but that action or channel key is not on an allowlist. */
    case ActionNotAllowed = 'action_not_allowed';

    /** 409 — nonce already seen. Never becomes anything else; send a fresh nonce. */
    case Replayed = 'replayed';

    /** 409 — an earlier attempt at this idempotency key has not finished yet. */
    case InProgress = 'in_progress';

    /** 422 — Discord answered, and said no. `message` carries its reason. */
    case DiscordRejected = 'discord_rejected';

    /** 429 — ours or Discord's. Honour Retry-After. */
    case RateLimited = 'rate_limited';

    /** 500 — the bot's own bug. It is in its logs under this request_id. */
    case Internal = 'internal';

    /** 502 — Discord errored or was unreachable. */
    case DiscordUnavailable = 'discord_unavailable';

    /** 504 — Discord did not answer inside the action's budget. */
    case UpstreamTimeout = 'upstream_timeout';

    /**
     * Whether the published table says a retry can succeed.
     *
     * Note the two 409s. `in_progress` means "your earlier attempt is still
     * running, ask again with the same idempotency key and a fresh nonce" and
     * `replayed` means "I have seen that exact nonce, and I always will".
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::InProgress,
            self::RateLimited,
            self::Internal,
            self::DiscordUnavailable,
            self::UpstreamTimeout => true,

            self::Malformed,
            self::Unauthorized,
            self::StaleRequest,
            self::ActionNotAllowed,
            self::Replayed,
            self::DiscordRejected => false,
        };
    }
}
