<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate limit for the log-based error alert (TOG-8730).
 *
 * One alert per exception fingerprint per window: a crashing deploy produces
 * thousands of identical 500s, and the log needs one line that says "this
 * broke" rather than thousands. Distinct failures are distinct fingerprints,
 * so a second root cause still fires its own alert while the first is muted.
 *
 * The fingerprint is the exception class plus the route (or command) that
 * raised it — not the message, which carries ids and timestamps that would
 * make every alert unique and the limit a no-op.
 *
 * The guard is `availableIn`-style, never exception-shaped: RateLimiter
 * reads the cache store, and the store on a box mid-outage may be the thing
 * that is down. A limiter that throws while deciding whether to alert turns
 * every 500 into two failures. So a limiter miss (or a limiter error) reads
 * as "alert" — the noise guard degrades to unmuted, never to silent.
 */
final class ErrorAlertRateLimit
{
    public const MAX_ATTEMPTS = 1;

    public const DECAY_SECONDS = 300;

    public static function shouldAlert(string $fingerprint): bool
    {
        $key = self::key($fingerprint);

        try {
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                return false;
            }

            RateLimiter::hit($key, self::DECAY_SECONDS);
        } catch (\Throwable) {
            // The limiter store is down (or misconfigured) — alert anyway.
            // See the class docblock: the guard degrades to unmuted.
            return true;
        }

        return true;
    }

    public static function fingerprint(\Throwable $e, ?string $route = null): string
    {
        return get_class($e).'@'.($route ?? 'unknown');
    }

    private static function key(string $fingerprint): string
    {
        return 'error-alert:'.sha1($fingerprint);
    }
}
