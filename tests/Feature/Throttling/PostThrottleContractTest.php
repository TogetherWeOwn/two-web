<?php

use App\Enums\EventStatus;
use App\Models\AgentEventGrant;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// TOG-8735: the 429 contract across the POST write surface.
//
// ThrottleEnvelopeTest pins the envelope on the GET handoffs and the RSVP
// PUT/DELETE writes, and ThrottleCoverageTest pins that every app-owned write
// route carries a `throttle:` line — but nothing hammered a POST endpoint far
// enough to see the actual 429. These tests do: each one spends a POST
// budget to the last allowed hit and asserts the next call answers 429 with
// the `Retry-After` header and the one shared envelope
// (tests/Support/ThrottleEnvelope.php), whichever limiter fired — the
// `throttle:30,1` route middleware on the human writes, or the per-grant
// AgentEventRateLimit limiter inside the machine ingress.
//
// Time is frozen so the assertion is exact: with no clock movement the next
// retry is always the full decay window (60 seconds), never "59 because the
// hammer took a second".

const CONTRACT_AGENT_CREDENTIAL = 'contract-test-credential-opaque-entropy-here';

/** @return array<string, mixed> */
function contractEventPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Friday night Helldivers',
        'game' => 'Helldivers 2',
        'description' => 'Bring stims.',
        'starts_at' => '2026-07-15 20:00',
        'ends_at' => '2026-07-15 22:00',
        'timezone' => 'Europe/London',
        'location' => 'Voice: General',
        'capacity' => 4,
    ], $overrides);
}

function contractAgentGrant(array $overrides = []): AgentEventGrant
{
    config()->set('agent-events.enabled', true);

    return AgentEventGrant::query()->create(array_merge([
        'agent_id' => 'c1f22b2f-d85f-41e1-9c16-9ca24ac06a11',
        'company_id' => 'ef993a7e-5ea7-445f-ba88-27a6a2690c3a',
        'guild_id' => '1545644954272137297',
        'verifier_hash' => AgentEventGrant::verifierFor(CONTRACT_AGENT_CREDENTIAL),
        'max_events' => 1,
    ], $overrides));
}

/** @return array<string, mixed> */
function contractAgentCreate(array $overrides = []): array
{
    // Every attempt carries a fresh key: a replayed idempotency key is
    // answered from the store without spending rate budget, which would make
    // the hammer look like it never runs out.
    return array_merge([
        'op' => 'create',
        'idempotency_key' => (string) Str::uuid(),
        'fields' => contractEventPayload(),
    ], $overrides);
}

it('answers the 429 envelope with Retry-After on the event store throttle', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);

    $this->freezeTime();

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $this->actingAs($moderator)
            ->postJson(route('events.store'), contractEventPayload())
            ->assertCreated();
    }

    $throttled = $this->actingAs($moderator)
        ->postJson(route('events.store'), contractEventPayload());

    assertThrottleEnvelope($throttled, 60);

    $throttled
        ->assertHeader('X-RateLimit-Limit', '30')
        ->assertHeader('X-RateLimit-Remaining', '0');

    // The hammer really wrote: 30 drafts, so the 429 is the 31st call
    // refusing, not validation failing thirty times in a row.
    expect(Event::query()->count())->toBe(30);
});

it('answers the 429 envelope with Retry-After on the event publish throttle', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);
    $event = Event::factory()->create(['status' => EventStatus::Draft]);

    $this->freezeTime();

    // Publishing is idempotent — repeating it on an already published event
    // is a no-op 200 — so all 30 budget hits succeed before the 31st 429s.
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $this->actingAs($moderator)
            ->postJson(route('events.publish', $event))
            ->assertOk();
    }

    assertThrottleEnvelope(
        $this->actingAs($moderator)->postJson(route('events.publish', $event)),
        60,
    );
});

it('answers the 429 envelope with Retry-After on the event cancel throttle', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $this->freezeTime();

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $status = $this->actingAs($moderator)
            ->postJson(route('events.cancel', $event))
            ->getStatusCode();

        // The first cancel lands (200); the rest are refused as already
        // cancelled (409 event_not_open). Both consume throttle budget and
        // neither is a 429, which is exactly what the loop is proving.
        expect($status)->toBeIn([200, 409]);
    }

    assertThrottleEnvelope(
        $this->actingAs($moderator)->postJson(route('events.cancel', $event)),
        60,
    );
});

it('answers the 429 envelope with Retry-After on the logout throttle', function () {
    $this->freezeTime();

    for ($attempt = 0; $attempt < 30; $attempt++) {
        $this->postJson(route('logout'))->assertRedirect();
    }

    assertThrottleEnvelope($this->postJson(route('logout')), 60);
});

it('answers the 429 envelope with Retry-After on the machine ingress throttle', function () {
    // Creates never leave the app — drafts are not mirrored to Discord — so
    // a bare fake is enough; anything attempting real HTTP fails the test.
    Http::fake();

    // Pinned, not ambient: the suite must not start passing (or throttling
    // early) because an environment file changed the production default.
    config()->set('agent-events.mutating_per_minute', 10);

    contractAgentGrant();
    $headers = ['Authorization' => 'Bearer '.CONTRACT_AGENT_CREDENTIAL];

    $this->freezeTime();

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $status = $this->postJson(route('api.agent-events'), contractAgentCreate(), $headers)
            ->getStatusCode();

        // The first create lands (201); the rest hit the one-proof-event
        // quota (409). Both spend mutating budget — the limiter fires before
        // the domain — and neither is a 429.
        expect($status)->toBeIn([201, 409]);
    }

    $throttled = $this->postJson(route('api.agent-events'), contractAgentCreate(), $headers);

    assertThrottleEnvelope($throttled, 60);

    $throttled
        ->assertHeader('X-RateLimit-Limit', '10')
        ->assertHeader('X-RateLimit-Remaining', '0');
});

it('keeps one envelope shape across the join, event-write and machine-ingress throttles', function () {
    Http::fake();
    config()->set('agent-events.mutating_per_minute', 10);

    $moderator = User::factory()->create(['is_moderator' => true]);
    contractAgentGrant();
    $headers = ['Authorization' => 'Bearer '.CONTRACT_AGENT_CREDENTIAL];

    $this->freezeTime();

    for ($i = 0; $i < 10; $i++) {
        $this->getJson(route('join.redirect'));
    }
    $join = $this->getJson(route('join.redirect'));

    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($moderator)
            ->postJson(route('events.store'), contractEventPayload())
            ->assertCreated();
    }
    $write = $this->actingAs($moderator)
        ->postJson(route('events.store'), contractEventPayload());

    for ($i = 0; $i < 10; $i++) {
        $this->postJson(route('api.agent-events'), contractAgentCreate(), $headers);
    }
    $machine = $this->postJson(route('api.agent-events'), contractAgentCreate(), $headers);

    // Three different limiters — route middleware on a GET handoff, route
    // middleware on a POST write, the in-service per-grant limiter — one
    // answer: 429, the same body keys, the same reason, the same Retry-After.
    foreach ([$join, $write, $machine] as $response) {
        assertThrottleEnvelope($response, 60);
    }

    foreach ([$join->json(), $write->json(), $machine->json()] as $body) {
        expect(array_keys($body))->toEqualCanonicalizing(['reason', 'message', 'retry_after'])
            ->and($body['reason'])->toBe('rate_limited');
    }
});
