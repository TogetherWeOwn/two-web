<?php

namespace App\Support;

use App\Models\AgentEventGrant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The throttles for the agent ingress (Gate 2).
 *
 * An outer route shield plus two-level inner budgets. The shield
 * (`throttle:agent-events`, TOG-8402) counts every hit per credential before
 * auth, the grant lookup and the audit write — so an unauthenticated flood is
 * refused by a cache check instead of spending a database row per hit. The
 * inner budgets then decide what an admitted caller may spend: per grant, 10
 * mutating and 30 reads per minute — one grant cannot spend more than its
 * share. Service level: a ceiling across all grants, so a fleet of admitted
 * callers cannot together drown the domain the human routes share.
 *
 * Every key is the grant id or the credential, never an IP: machine callers
 * sit behind shared egress and a per-IP budget would be a budget for whoever
 * shares the NAT.
 */
final class AgentEventRateLimit
{
    /**
     * The outer route shield (TOG-8402), resolved per request before auth.
     *
     * Keyed by the SHA-256 of the bearer credential — the same one-way shape
     * as the stored verifier, never the credential itself — so each grant
     * spends only its own budget. Requests with no credential fall back to
     * IP: they cannot name a grant, so the shared-egress concern does not
     * apply and something still bounds them before the database runs.
     *
     * The budget sits above the inner budgets' sum on purpose: the shield is
     * a flood guard, not the allowance, so the bot's normal burst never sees
     * it. Both layers throw the same ThrottleRequestsException, so the 429
     * envelope (TOG-6788) is one shape whichever fires.
     */
    public static function routeLimit(Request $request): Limit
    {
        $credential = $request->bearerToken();

        $key = $credential !== null && $credential !== ''
            ? 'credential:'.hash('sha256', $credential)
            : 'ip:'.(string) $request->ip();

        return Limit::perMinute((int) config('agent-events.route_per_minute', 60))->by($key);
    }

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
