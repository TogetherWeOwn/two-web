<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The in-controller limiter behind the FAQ vote endpoint (TOG-8863).
 *
 * Same shape as RsvpRateLimit: a route-level `throttle:12,1` refuses a
 * hammering run before validation and the database run, and this limiter
 * agrees on the same 12/min budget underneath it, throwing the same
 * ThrottleRequestsException so the 429 envelope stays consistent (TOG-6788).
 *
 * Members are keyed by account, guests by their random `faq_voter` cookie —
 * never by IP, so a shared address never spends someone else's allowance, and
 * no IP is persisted anywhere.
 */
final class FaqVoteRateLimit
{
    public const MAX_ATTEMPTS = 12;

    public const DECAY_SECONDS = 60;

    public static function hitForMember(User $user): void
    {
        self::hit('faq-vote:member:'.$user->getAuthIdentifier());
    }

    public static function hitForGuest(string $voterKey): void
    {
        self::hit('faq-vote:guest:'.$voterKey);
    }

    private static function hit(string $key): void
    {
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $retryAfter = RateLimiter::availableIn($key);

            throw new ThrottleRequestsException('Too Many Attempts.', null, [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => self::MAX_ATTEMPTS,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);
    }
}
