<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One limiter shared by the JSON routes and the Livewire control.
 *
 * The key is per member, not per IP: families and community spaces can share an
 * address without spending one another's allowance. It is also deliberately
 * shared across events and between answering and withdrawing, so changing paths
 * cannot multiply the write budget.
 */
final class RsvpRateLimit
{
    public const MAX_ATTEMPTS = 12;

    public const DECAY_SECONDS = 60;

    public static function hit(User $user): void
    {
        $key = self::key($user);

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

    private static function key(User $user): string
    {
        return 'rsvp-write:'.$user->getAuthIdentifier();
    }
}
