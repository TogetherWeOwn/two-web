<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\User;
use App\Support\RsvpRateLimit;

// TOG-7301: PUT/DELETE /events/{event}/rsvp carry a route-level `throttle:12,1`
// in front of RsvpRateLimit's in-controller per-member limiter (same 12/min
// budget, shared across both verbs like the limiter). The middleware refuses a
// hammering run before validation, policy and the database run, throwing the
// same ThrottleRequestsException ("Too Many Attempts.") the limiter throws —
// so the 429 shape stays consistent per [TOG-6788](/TOG/issues/TOG-6788).

it('carries throttle middleware on both RSVP write routes', function () {
    // The envelope only holds if it is registered. If a route loses its
    // `throttle:12,1` line this fails rather than shipping an unthrottled
    // write — the behaviour test below would still pass at 13 hits in
    // isolation (the controller limiter fires) but the production route would
    // be doing validation, policy and database work on every hammer hit.
    foreach (['events.rsvp.update', 'events.rsvp.destroy'] as $name) {
        $route = app('router')->getRoutes()->getByName($name);

        expect($route)->not->toBeNull();

        $throttle = collect($route->gatherMiddleware())
            ->first(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'));

        expect($throttle)->toBe('throttle:12,1', "route {$name} must carry throttle:12,1");
    }
});

it('returns a 429 envelope when the RSVP write budget is hammered', function () {
    $member = User::factory()->create(['is_moderator' => false]);
    $events = Event::factory()->count(2)->create(['status' => EventStatus::Published, 'capacity' => null]);

    $this->freezeTime();

    for ($attempt = 0; $attempt < RsvpRateLimit::MAX_ATTEMPTS; $attempt++) {
        $event = $events[$attempt % 2];
        $status = $attempt % 2 === 0 ? RsvpStatus::Going : RsvpStatus::Maybe;

        $this->actingAs($member)
            ->putJson(route('events.rsvp.update', $event), ['status' => $status->value])
            ->assertSuccessful();
    }

    // Alternating verbs must not multiply the budget: the 13th write 429s
    // even though it is a DELETE on a different verb, with the limiter's
    // envelope (Retry-After plus the rate-limit headers, no stack).
    $this->actingAs($member)
        ->deleteJson(route('events.rsvp.destroy', $events[0]))
        ->assertStatus(429)
        ->assertHeader('Retry-After', RsvpRateLimit::DECAY_SECONDS)
        ->assertHeader('X-RateLimit-Limit', (string) RsvpRateLimit::MAX_ATTEMPTS)
        ->assertHeader('X-RateLimit-Remaining', '0');

    // A busy household or community-space IP must not make members share a
    // bucket: the throttle keys per member, so somebody else still writes.
    $otherMember = User::factory()->create(['is_moderator' => false]);

    $this->actingAs($otherMember)
        ->putJson(route('events.rsvp.update', $events[0]), ['status' => RsvpStatus::Going->value])
        ->assertSuccessful();
});
