<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\User;
use App\Support\RsvpRateLimit;

// TOG-7301: PUT/DELETE /events/{event}/rsvp carry a route-level throttle in
// front of RsvpRateLimit's in-controller per-member limiter (same 12/min
// budget, shared across both verbs like the limiter). The middleware refuses a
// hammering run before validation, policy and the database run, throwing the
// same ThrottleRequestsException ("Too Many Attempts.") the limiter throws —
// so the 429 shape stays consistent per [TOG-6788](/TOG/issues/TOG-6788).
//
// TOG-8824: the throttle is the `rsvp-writes` named limiter, not bare
// `throttle:12,1` — the bare form keys by sha1(user id) alone, so RSVP writes
// shared one counter with every `throttle:10,1` route (/join/discord,
// /auth/discord/*) and hammering one side 429'd the other.

it('carries throttle middleware on both RSVP write routes', function () {
    // The envelope only holds if it is registered. If a route loses its
    // `throttle:rsvp-writes` line this fails rather than shipping an
    // unthrottled write — the behaviour test below would still pass at 13
    // hits in isolation (the controller limiter fires) but the production
    // route would be doing validation, policy and database work on every
    // hammer hit. The named limiter (not bare `throttle:12,1`) keeps RSVP
    // writes in their own bucket, away from the `throttle:10,1` join/login
    // routes that share sha1(user id) per TOG-8824.
    foreach (['events.rsvp.update', 'events.rsvp.destroy'] as $name) {
        $route = app('router')->getRoutes()->getByName($name);

        expect($route)->not->toBeNull();

        $throttle = collect($route->gatherMiddleware())
            ->first(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'));

        expect($throttle)->toBe('throttle:rsvp-writes', "route {$name} must carry throttle:rsvp-writes");
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
    // even though it is a DELETE on a different verb, with the shared
    // throttle envelope (TOG-6788) plus the limiter's rate-limit headers.
    $throttled = $this->actingAs($member)
        ->deleteJson(route('events.rsvp.destroy', $events[0]));

    assertThrottleEnvelope($throttled, RsvpRateLimit::DECAY_SECONDS);

    $throttled
        ->assertHeader('X-RateLimit-Limit', (string) RsvpRateLimit::MAX_ATTEMPTS)
        ->assertHeader('X-RateLimit-Remaining', '0');

    // A busy household or community-space IP must not make members share a
    // bucket: the throttle keys per member, so somebody else still writes.
    $otherMember = User::factory()->create(['is_moderator' => false]);

    $this->actingAs($otherMember)
        ->putJson(route('events.rsvp.update', $events[0]), ['status' => RsvpStatus::Going->value])
        ->assertSuccessful();
});

it('leaves the join redirect unthrottled after hammering RSVP writes', function () {
    // TOG-8824: the regression. Bare `throttle:12,1` keyed an authenticated
    // request by sha1(user id) alone, so 12 RSVP writes filled the same
    // counter the `throttle:10,1` join/login routes use and the next
    // GET /join/discord 429'd. The `rsvp-writes` named limiter keeps its own
    // `rsvp:{member id}` bucket, so the redirect still answers after a full
    // RSVP budget is spent.
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

    $this->actingAs($member)
        ->get(route('join.redirect'))
        ->assertRedirect();
});

it('leaves RSVP writes unthrottled after hammering the join redirect', function () {
    // TOG-8824, the reverse direction: 10 hits spend the join redirect's own
    // 10/min budget without touching the RSVP bucket, so a member who just
    // retried the join handoff can still answer an event.
    $member = User::factory()->create(['is_moderator' => false]);
    $event = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => null]);

    $this->freezeTime();

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->actingAs($member)
            ->get(route('join.redirect'))
            ->assertRedirect();
    }

    $this->actingAs($member)
        ->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
        ->assertSuccessful();
});
