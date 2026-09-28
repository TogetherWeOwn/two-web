<?php

use Illuminate\Testing\TestResponse;

/**
 * The one shared 429 assertion for every throttled route (TOG-6788).
 *
 * Whatever fired the throttle — the `throttle:10,1` / `throttle:12,1` route
 * middleware on the auth callbacks and RSVP writes, the per-member
 * RsvpRateLimit limiter, or the machine-ingress limiters — a JSON caller must
 * see the same envelope: 429, the `{reason, message, retry_after}` body with
 * retry info, the `Retry-After` header, and no stack trace.
 */
function assertThrottleEnvelope(TestResponse $response, ?int $retryAfter = null): TestResponse
{
    $response->assertStatus(429);
    $response->assertHeader('Retry-After');

    $body = $response->json();

    expect($body['reason'] ?? null)->toBe('rate_limited');
    expect($body['message'] ?? null)->toBeString()->not->toBe('');
    expect($body['retry_after'] ?? null)->toBeInt()->toBeGreaterThan(0);

    if ($retryAfter !== null) {
        expect($body['retry_after'])->toBe($retryAfter);
        $response->assertHeader('Retry-After', (string) $retryAfter);
    }

    // The framework's debug rendering dumps these; the envelope must never
    // carry them, at any debug setting.
    expect($body)->not->toHaveKeys(['exception', 'file', 'line', 'trace']);
    expect((string) $response->getContent())->not->toContain('ThrottleRequestsException');

    return $response;
}
