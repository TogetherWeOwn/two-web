<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
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

    /**
     * The route-level shield in front of hit() (TOG-8824).
     *
     * Registered as the `rsvp-writes` named limiter in AppServiceProvider and
     * applied to the PUT/DELETE RSVP routes. Same 12/min budget, keyed
     * `rsvp:{member id}` like hit() — but in its own bucket. The bare
     * `throttle:12,1` this replaces resolves to sha1(user id) with no prefix,
     * so RSVP writes shared one counter with every `throttle:10,1` route
     * (/join/discord, /auth/discord/*) and hammering one side could 429 the
     * other. Guests never reach it (the routes sit behind `auth`); the IP
     * fallback is only so a miswired guest hit still has a bounded key.
     */
    public static function routeLimit(Request $request): Limit
    {
        $user = $request->user();

        $key = $user instanceof User
            ? 'rsvp:'.$user->getAuthIdentifier()
            : 'ip:'.$request->ip();

        return Limit::perMinute(self::MAX_ATTEMPTS)->by($key);
    }

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
