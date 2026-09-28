<?php

namespace App\Support;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One 429 shape for every throttle in the app.
 *
 * The throttles fire from three places — the `throttle:10,1` / `throttle:12,1`
 * route middleware on the auth callbacks and RSVP writes, the per-member
 * RsvpRateLimit limiter (shared by the JSON routes and the Livewire control),
 * and the AgentEventRateLimit limiters on the machine ingress — but the answer
 * must not depend on which one fired ([TOG-6788](/TOG/issues/TOG-6788)):
 *
 *  - JSON callers get `{reason, message, retry_after}` with the limiter's
 *    headers (`Retry-After`, `X-RateLimit-*`) preserved, and never a stack
 *    trace — the framework's default JSON rendering dumps `exception`, `file`,
 *    `line` and `trace` whenever `app.debug` is on.
 *  - Browser callers get the branded `errors/429` page instead of Symfony's
 *    default "Too Many Requests" page, with the same `Retry-After` header.
 */
final class ThrottleEnvelope
{
    public static function render(Request $request, ThrottleRequestsException $exception): Response
    {
        $headers = $exception->getHeaders();
        $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 60));

        if ($request->expectsJson()) {
            return response()->json([
                'reason' => 'rate_limited',
                'message' => "Too many requests. Try again in {$retryAfter} seconds.",
                'retry_after' => $retryAfter,
            ], 429, $headers);
        }

        return response()->view('errors.429', [
            'retryAfter' => $retryAfter,
        ], 429, $headers);
    }
}
