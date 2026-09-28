<?php

namespace App\Support;

use App\Models\AgentEventGrant;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Two-level throttles for the agent ingress (Gate 2).
 *
 * Per grant: 10 mutating and 30 reads per minute — one grant cannot spend more
 * than its share. Service level: a ceiling across all grants, so a fleet of
 * admitted callers cannot together drown the domain the human routes share.
 * The key is the grant id, never an IP: machine callers sit behind shared
 * egress and a per-IP budget would be a budget for whoever shares the NAT.
 */
final class AgentEventRateLimit
{
    public static function hitMutating(AgentEventGrant $grant): void
    {
        self::hit(
            'agent-events-mutating:'.$grant->getKey(),
            (int) config('agent-events.mutating_per_minute', 10),
            'agent-events-service-mutating',
            (int) config('agent-events.service_mutating_per_minute', 60),
        );
    }

    public static function hitRead(AgentEventGrant $grant): void
    {
        self::hit(
            'agent-events-read:'.$grant->getKey(),
            (int) config('agent-events.reads_per_minute', 30),
            'agent-events-service-read',
            (int) config('agent-events.service_reads_per_minute', 300),
        );
    }

    private static function hit(string $grantKey, int $grantMax, string $serviceKey, int $serviceMax): void
    {
        foreach ([[$grantKey, $grantMax], [$serviceKey, $serviceMax]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new ThrottleRequestsException('Too Many Attempts.', null, [
                    'Retry-After' => RateLimiter::availableIn($key),
                    'X-RateLimit-Limit' => $max,
                    'X-RateLimit-Remaining' => 0,
                ]);
            }
        }

        RateLimiter::hit($grantKey, 60);
        RateLimiter::hit($serviceKey, 60);
    }
}
