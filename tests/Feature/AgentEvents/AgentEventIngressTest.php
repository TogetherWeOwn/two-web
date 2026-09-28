<?php

use App\Enums\EventStatus;
use App\Jobs\SyncEventToDiscord;
use App\Models\AgentEventAudit;
use App\Models\AgentEventGrant;
use App\Models\AgentEventIdempotencyKey;
use App\Models\Event;
use App\Services\Bot\InternalActionClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * The scoped machine ingress: POST /api/agent-events (TOG-5510/web, Gate 2).
 *
 * One endpoint, five typed operations, one admitted caller. What is asserted
 * here is the boundary, not the happy path through it:
 *
 *   - every denial case fires before any domain mutation, queueing or
 *     signing, and leaves an audit row with its reason code;
 *   - the same caller, key and payload replays one result; a changed payload
 *     under the same key is a 409;
 *   - the quota holds at one proof event, optimistic versions guard updates,
 *     the dispatch-time grant recheck stops queued publishes, a stale job
 *     cannot resurrect a cancel, and a draft cancel never touches Discord;
 *   - the verification read returns only proof-owned fields, and marks the
 *     Discord observation unavailable rather than inventing one while the bot
 *     slice's `event.read` is still in progress.
 */

const AGENT_BOT_ENDPOINT = 'http://bot.internal:3001/internal/actions';

const AGENT_CREDENTIAL = 'agent-test-credential-opaque-entropy-here';

const AGENT_STAGING_GUILD = '1545644954272137297';

/** @return array<string, mixed> */
function agentFields(array $overrides = []): array
{
    return array_merge([
        'title' => 'Agent proof event',
        'game' => 'Helldivers 2',
        'description' => 'One uniquely labelled staging proof.',
        'starts_at' => '2026-10-01 20:00',
        'ends_at' => '2026-10-01 22:00',
        'timezone' => 'Europe/London',
        'location' => 'Voice: General',
        'capacity' => 4,
    ], $overrides);
}

/** @return array<string, mixed> */
function agentOp(string $op, array $overrides = []): array
{
    return array_merge(['op' => $op, 'idempotency_key' => (string) Str::uuid()], $overrides);
}

function agentGrant(array $overrides = []): AgentEventGrant
{
    config()->set('agent-events.enabled', true);

    return AgentEventGrant::query()->create(array_merge([
        'agent_id' => 'c1f22b2f-d85f-41e1-9c16-9ca24ac06a11',
        'company_id' => 'ef993a7e-5ea7-445f-ba88-27a6a2690c3a',
        'guild_id' => AGENT_STAGING_GUILD,
        'verifier_hash' => AgentEventGrant::verifierFor(AGENT_CREDENTIAL),
        'max_events' => 1,
    ], $overrides));
}

function agentBotUpsert(string $eventId = '1234567890'): array
{
    return ['ok' => true, 'result' => ['outcome' => 'created', 'event_id' => $eventId], 'request_id' => '01JAGENT0123456789'];
}

function agentBotCancel(string $eventId = '1234567890'): array
{
    return ['ok' => true, 'result' => ['outcome' => 'cancelled', 'event_id' => $eventId], 'request_id' => '01JAGENTCANCEL01'];
}

beforeEach(function () {
    config()->set('services.bot.url', 'http://bot.internal:3001');
    config()->set('services.bot.secret', 'two-web-test-secret-at-least-32-characters');
    config()->set('services.bot.key_id', 'web-test');
    config()->set('services.bot.timeout', 5);
});

// ---------------------------------------------------------------------------
// The door: disabled ingress, unauthenticated, unknown ops, dead grants.
// ---------------------------------------------------------------------------

it('answers 404 when the ingress is disabled', function () {
    config()->set('agent-events.enabled', false);

    $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), ['Authorization' => 'Bearer '.AGENT_CREDENTIAL])
        ->assertNotFound()
        ->assertJsonPath('reason', 'ingress_disabled');

    expect(Event::query()->count())->toBe(0)
        ->and(AgentEventAudit::query()->where('reason_code', 'ingress_disabled')->count())->toBe(1);
});

it('audits a malformed envelope that never reaches authentication', function () {
    agentGrant();

    $this->postJson(route('api.agent-events'), ['op' => 'create'], ['Authorization' => 'Bearer '.AGENT_CREDENTIAL])
        ->assertStatus(422)
        ->assertJsonPath('reason', 'validation_failed');

    expect(Event::query()->count())->toBe(0)
        ->and(AgentEventAudit::query()->where('reason_code', 'validation_failed')->count())->toBe(1)
        ->and(AgentEventAudit::query()->firstOrFail()->grant_id)->toBeNull();
});

it('denies an unauthenticated call with no side effects', function () {
    agentGrant();

    $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]))
        ->assertUnauthorized()
        ->assertJsonPath('reason', 'unauthenticated');

    expect(Event::query()->count())->toBe(0)
        ->and(AgentEventAudit::query()->where('reason_code', 'unauthenticated')->count())->toBe(1)
        ->and(AgentEventAudit::query()->firstOrFail()->grant_id)->toBeNull();
});

it('denies a wrong-grant credential with no side effects', function () {
    agentGrant();

    $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), ['Authorization' => 'Bearer wrong-credential'])
        ->assertUnauthorized()
        ->assertJsonPath('reason', 'unauthenticated');

    expect(Event::query()->count())->toBe(0);
});

it('denies a forbidden action before any mutation', function () {
    agentGrant();

    $this->postJson(route('api.agent-events'), agentOp('rsvp'), ['Authorization' => 'Bearer '.AGENT_CREDENTIAL])
        ->assertForbidden()
        ->assertJsonPath('reason', 'forbidden_action');

    expect(Event::query()->count())->toBe(0)
        ->and(AgentEventAudit::query()->where('reason_code', 'forbidden_action')->count())->toBe(1);
});

it('denies an expired grant at ingress and at dispatch alike', function () {
    $grant = agentGrant(['expires_at' => now()->subHour()]);

    $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), ['Authorization' => 'Bearer '.AGENT_CREDENTIAL])
        ->assertForbidden()
        ->assertJsonPath('reason', 'grant_expired');

    expect(Event::query()->count())->toBe(0);

    // The second half of the same rule: expiry between enqueue and dispatch
    // must stop the write-back, not just the ingress.
    $owned = Event::factory()->create([
        'status' => EventStatus::Published,
        'title' => 'Proof',
        'location' => 'Voice: General',
        'starts_at' => Carbon::parse('2026-10-01T18:00:00Z'),
        'ends_at' => Carbon::parse('2026-10-01T20:00:00Z'),
        'timezone' => 'Europe/London',
        'agent_grant_id' => $grant->getKey(),
        'proof_marker' => 'agent-proof-dispatch-expiry',
        'agent_version' => 1,
    ]);

    Http::fake([AGENT_BOT_ENDPOINT => Http::response(agentBotUpsert())]);

    $job = (new SyncEventToDiscord($owned->event_key))->withFakeQueueInteractions();
    $job->handle(app(InternalActionClient::class));

    $job->assertFailed()->assertNotReleased();
    Http::assertNothingSent();

    expect(AgentEventAudit::query()->where('reason_code', 'grant_expired')->count())->toBe(2);
});

it('denies a disabled grant at ingress', function () {
    agentGrant(['disabled_at' => now()]);

    $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), ['Authorization' => 'Bearer '.AGENT_CREDENTIAL])
        ->assertForbidden()
        ->assertJsonPath('reason', 'grant_disabled');

    expect(Event::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// The guild rule: production is rejected by configured value, not constant.
// ---------------------------------------------------------------------------

it('rejects a production-guild override by configured value', function () {
    config()->set('agent-events.production_guild_id', '326474832151838730');
    agentGrant();

    $this->postJson(
        route('api.agent-events'),
        agentOp('create', ['fields' => agentFields(), 'guild_id' => '326474832151838730']),
        ['Authorization' => 'Bearer '.AGENT_CREDENTIAL]
    )
        ->assertForbidden()
        ->assertJsonPath('reason', 'wrong_guild');

    expect(Event::query()->count())->toBe(0)
        ->and(AgentEventAudit::query()->where('reason_code', 'wrong_guild')->count())->toBe(1);
});

it('denies a grant that is not the admitted caller', function () {
    agentGrant(['agent_id' => '00000000-0000-4000-8000-000000000000']);

    $this->postJson(
        route('api.agent-events'),
        agentOp('create', ['fields' => agentFields()]),
        ['Authorization' => 'Bearer '.AGENT_CREDENTIAL]
    )
        ->assertForbidden()
        ->assertJsonPath('reason', 'wrong_caller');

    expect(Event::query()->count())->toBe(0)
        ->and(AgentEventAudit::query()->where('reason_code', 'wrong_caller')->count())->toBe(1);
});

it('denies a production-bound grant before any mutation', function () {
    agentGrant(['guild_id' => '326474832151838730']);

    $this->postJson(
        route('api.agent-events'),
        agentOp('create', ['fields' => agentFields()]),
        ['Authorization' => 'Bearer '.AGENT_CREDENTIAL]
    )
        ->assertForbidden()
        ->assertJsonPath('reason', 'production_guild');

    expect(Event::query()->count())->toBe(0)
        ->and(AgentEventAudit::query()->where('reason_code', 'production_guild')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// The quota: one proof event per grant, enforced by the database.
// ---------------------------------------------------------------------------

it('creates one draft owned by the grant, with machine attribution and no human', function () {
    agentGrant();

    $this->postJson(
        route('api.agent-events'),
        agentOp('create', ['fields' => agentFields()]),
        ['Authorization' => 'Bearer '.AGENT_CREDENTIAL]
    )
        ->assertCreated()
        ->assertJsonPath('agent_version', 1);

    $event = Event::query()->firstOrFail();

    expect($event->status)->toBe(EventStatus::Draft)
        ->and($event->created_by)->toBeNull()
        ->and((string) $event->agent_grant_id)->toBe(AgentEventGrant::query()->firstOrFail()->getKey())
        ->and($event->proof_marker)->toStartWith('agent-proof-');
});

it('refuses a second proof event for the same grant', function () {
    $grant = agentGrant();
    Event::factory()->create(['agent_grant_id' => $grant->getKey(), 'proof_marker' => 'agent-proof-first', 'agent_version' => 1]);

    $this->postJson(
        route('api.agent-events'),
        agentOp('create', ['fields' => agentFields()]),
        ['Authorization' => 'Bearer '.AGENT_CREDENTIAL]
    )
        ->assertConflict()
        ->assertJsonPath('reason', 'quota_exceeded');

    expect(Event::query()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Ownership: foreign events are 403, unknown keys are 404.
// ---------------------------------------------------------------------------

it('denies a foreign event and never reveals it', function () {
    agentGrant();
    $human = Event::factory()->create();

    $this->postJson(
        route('api.agent-events'),
        agentOp('update', ['event_key' => $human->event_key, 'version' => 1, 'fields' => agentFields()]),
        ['Authorization' => 'Bearer '.AGENT_CREDENTIAL]
    )
        ->assertForbidden()
        ->assertJsonPath('reason', 'foreign_event');

    $this->postJson(
        route('api.agent-events'),
        agentOp('read', ['event_key' => $human->event_key]),
        ['Authorization' => 'Bearer '.AGENT_CREDENTIAL]
    )
        ->assertForbidden()
        ->assertJsonPath('reason', 'foreign_event');
});

it('answers 404 for an unknown event key', function () {
    agentGrant();

    $this->postJson(
        route('api.agent-events'),
        agentOp('read', ['event_key' => '01JUNKNOWN0000000000000000']),
        ['Authorization' => 'Bearer '.AGENT_CREDENTIAL]
    )
        ->assertNotFound()
        ->assertJsonPath('reason', 'event_not_found');
});

// ---------------------------------------------------------------------------
// Idempotency: same caller, key and payload replays; changed payload 409s.
// ---------------------------------------------------------------------------

it('replays an identical retry and conflicts a changed payload under the same key', function () {
    agentGrant();
    $headers = ['Authorization' => 'Bearer '.AGENT_CREDENTIAL];
    $key = (string) Str::uuid();
    $fields = agentFields();

    $first = $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => $fields, 'idempotency_key' => $key]), $headers)
        ->assertCreated();

    $second = $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => $fields, 'idempotency_key' => $key]), $headers)
        ->assertCreated();

    expect($second->json('replayed'))->toBeTrue()
        ->and($second->json('event_key'))->toBe($first->json('event_key'))
        ->and(Event::query()->count())->toBe(1);

    $this->postJson(
        route('api.agent-events'),
        agentOp('create', ['fields' => agentFields(['title' => 'A different operation']), 'idempotency_key' => $key]),
        $headers
    )
        ->assertConflict()
        ->assertJsonPath('reason', 'idempotency_conflict');

    expect(Event::query()->count())->toBe(1);
});

it('keeps the replay store across a restart: no re-execution, same answer', function () {
    $grant = agentGrant();
    $headers = ['Authorization' => 'Bearer '.AGENT_CREDENTIAL];
    $fields = agentFields();

    // First execution persists its answer; the "restart" is the store
    // surviving while the process does not — simulated by replaying the same
    // request through a fresh service resolution with the rows intact.
    $key = (string) Str::uuid();

    $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => $fields, 'idempotency_key' => $key]), $headers)
        ->assertCreated();

    expect(AgentEventIdempotencyKey::query()->where('grant_id', $grant->getKey())->count())->toBe(1);

    $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => $fields, 'idempotency_key' => $key]), $headers)
        ->assertCreated()
        ->assertJsonPath('replayed', true);

    expect(Event::query()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Update: optimistic version, stale 409, human writes do not invalidate.
// ---------------------------------------------------------------------------

it('updates the owned event and bumps the version', function () {
    $grant = agentGrant();
    $headers = ['Authorization' => 'Bearer '.AGENT_CREDENTIAL];

    $created = $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), $headers)
        ->assertCreated();

    $this->postJson(
        route('api.agent-events'),
        agentOp('update', [
            'event_key' => $created->json('event_key'),
            'version' => 1,
            'fields' => agentFields(['title' => 'Agent proof event, revised']),
        ]),
        $headers
    )
        ->assertOk()
        ->assertJsonPath('agent_version', 2);

    expect(Event::query()->firstOrFail()->title)->toBe('Agent proof event, revised');
});

it('audits an update missing its version and answers the corrected retry', function () {
    agentGrant();
    $headers = ['Authorization' => 'Bearer '.AGENT_CREDENTIAL];

    $created = $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), $headers)
        ->assertCreated();

    $key = $created->json('event_key');

    // No version: audited, 422, and never replay-stored — so the corrected
    // call under the same key is answered rather than conflicted.
    $retryKey = (string) Str::uuid();

    $this->postJson(
        route('api.agent-events'),
        agentOp('update', ['event_key' => $key, 'fields' => agentFields(['title' => 'Fixed version']), 'idempotency_key' => $retryKey]),
        $headers
    )
        ->assertStatus(422)
        ->assertJsonPath('reason', 'validation_failed');

    $this->postJson(
        route('api.agent-events'),
        agentOp('update', ['event_key' => $key, 'version' => 1, 'fields' => agentFields(['title' => 'Fixed version']), 'idempotency_key' => $retryKey]),
        $headers
    )->assertOk();

    expect(Event::query()->firstOrFail()->title)->toBe('Fixed version')
        ->and(AgentEventAudit::query()->where('operation', 'update')->where('reason_code', 'validation_failed')->count())->toBe(1);
});

it('rejects a stale version rather than overwriting', function () {
    $grant = agentGrant();
    $headers = ['Authorization' => 'Bearer '.AGENT_CREDENTIAL];

    $created = $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), $headers)
        ->assertCreated();

    $this->postJson(
        route('api.agent-events'),
        agentOp('update', [
            'event_key' => $created->json('event_key'),
            'version' => 1,
            'fields' => agentFields(['title' => 'Winner']),
        ]),
        $headers
    )->assertOk();

    $this->postJson(
        route('api.agent-events'),
        agentOp('update', [
            'event_key' => $created->json('event_key'),
            'version' => 1,
            'fields' => agentFields(['title' => 'Loser']),
        ]),
        $headers
    )
        ->assertConflict()
        ->assertJsonPath('reason', 'stale_version');

    expect(Event::query()->firstOrFail()->title)->toBe('Winner');
});

// ---------------------------------------------------------------------------
// Lifecycle: publish, cancel, and the no-resurrection rule.
// ---------------------------------------------------------------------------

it('publishes and cancels the owned event through the shared transitions', function () {
    Http::fake([AGENT_BOT_ENDPOINT => Http::response(agentBotUpsert())]);
    agentGrant();
    $headers = ['Authorization' => 'Bearer '.AGENT_CREDENTIAL];

    $created = $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), $headers)
        ->assertCreated();

    $this->postJson(route('api.agent-events'), agentOp('publish', ['event_key' => $created->json('event_key')]), $headers)
        ->assertOk()
        ->assertJsonPath('status', 'published');

    // A cancelled event is terminal: the shared transition refuses to move
    // it anywhere afterwards, on the agent path exactly as on the human one.
    $this->postJson(route('api.agent-events'), agentOp('cancel', ['event_key' => $created->json('event_key')]), $headers)
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    $this->postJson(route('api.agent-events'), agentOp('publish', ['event_key' => $created->json('event_key')]), $headers)
        ->assertConflict()
        ->assertJsonPath('reason', 'event_not_open');
});

it('cancels a mirrored event through event.cancel, never upsert', function () {
    Http::fake([AGENT_BOT_ENDPOINT => Http::response(agentBotCancel())]);
    agentGrant();
    $headers = ['Authorization' => 'Bearer '.AGENT_CREDENTIAL];

    $created = $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), $headers)
        ->assertCreated();

    $event = Event::query()->where('event_key', $created->json('event_key'))->firstOrFail();
    $event->forceFill(['status' => EventStatus::Published, 'discord_event_id' => '1234567890'])->save();

    $this->postJson(route('api.agent-events'), agentOp('cancel', ['event_key' => $event->event_key]), $headers)
        ->assertOk();

    (new SyncEventToDiscord($event->event_key))->handle(app(InternalActionClient::class));

    Http::assertSent(function ($request) use ($event) {
        $body = json_decode($request->body(), true);

        return $body['action'] === 'event.cancel' && ($body['event_key'] ?? null) === $event->event_key;
    });
});

it('never creates a Discord event when cancelling a draft', function () {
    Http::fake();
    agentGrant();
    $headers = ['Authorization' => 'Bearer '.AGENT_CREDENTIAL];

    $created = $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), $headers)
        ->assertCreated();

    $this->postJson(route('api.agent-events'), agentOp('cancel', ['event_key' => $created->json('event_key')]), $headers)
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    (new SyncEventToDiscord($created->json('event_key')))->handle(app(InternalActionClient::class));

    Http::assertNothingSent();
});

it('stops a queued publish at dispatch once the event is cancelled', function () {
    agentGrant();

    $event = Event::factory()->create([
        'status' => EventStatus::Cancelled,
        'title' => 'Proof',
        'location' => 'Voice: General',
        'starts_at' => Carbon::parse('2026-10-01T18:00:00Z'),
        'ends_at' => Carbon::parse('2026-10-01T20:00:00Z'),
        'timezone' => 'Europe/London',
        'agent_grant_id' => AgentEventGrant::query()->firstOrFail()->getKey(),
        'proof_marker' => 'agent-proof-stale-publish',
        'agent_version' => 1,
        // Previously mirrored: the job must send event.cancel, not upsert.
        'discord_event_id' => '1234567890',
    ]);

    Http::fake([AGENT_BOT_ENDPOINT => Http::response(agentBotCancel())]);

    (new SyncEventToDiscord($event->event_key))->handle(app(InternalActionClient::class));

    // The stale queued publish became a cancel at dispatch: one call, and it
    // was the cancel. A resurrecting upsert would be the bug.
    $sent = Http::recorded();
    expect($sent)->toHaveCount(1)
        ->and(json_decode($sent[0][0]->body(), true)['action'])->toBe('event.cancel');
});

// ---------------------------------------------------------------------------
// Verification: proof-owned fields only, observation marked when unavailable.
// ---------------------------------------------------------------------------

it('returns only proof-owned fields and marks the Discord observation unavailable without event.read', function () {
    // No HTTP fake: the bot slice's `event.read` is still in progress, so the
    // client throws BotNotConfiguredException against the blank test env —
    // and the read must mark that absence, never invent an observation.
    config()->set('services.bot.url', null);
    config()->set('services.bot.secret', null);
    config()->set('services.bot.key_id', null);
    agentGrant();
    $headers = ['Authorization' => 'Bearer '.AGENT_CREDENTIAL];

    $created = $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), $headers)
        ->assertCreated();

    $response = $this->postJson(
        route('api.agent-events'),
        agentOp('read', ['event_key' => $created->json('event_key')]),
        $headers
    )->assertOk();

    $event = $response->json('event');

    expect(array_keys($event))->toEqualCanonicalizing([
        'event_key', 'title', 'game', 'description', 'starts_at', 'ends_at',
        'timezone', 'location', 'capacity', 'status', 'agent_version',
        'proof_marker', 'discord_event_id',
    ])
        ->and($response->json('discord.unavailable'))->toBe('verification_unavailable')
        ->and($response->json('proof_marker_matches'))->toBe(1)
        ->and($response->json('owned_event_count'))->toBe(1);
});

it('reports the mapped mirror fields when event.read answers', function () {
    Http::fake([AGENT_BOT_ENDPOINT => Http::response([
        'ok' => true,
        'result' => [
            'outcome' => 'read',
            'event_id' => '1234567890',
            'name' => 'Agent proof event',
            'starts_at' => '2026-10-01T19:00:00Z',
            'location' => 'Voice: General',
            'status' => 'SCHEDULED',
            'observed_at' => '2026-09-27T18:30:00Z',
        ],
        'request_id' => '01JREAD0123456789',
    ])]);

    agentGrant();
    $headers = ['Authorization' => 'Bearer '.AGENT_CREDENTIAL];

    $created = $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), $headers)
        ->assertCreated();

    Event::query()->where('event_key', $created->json('event_key'))->update(['discord_event_id' => '1234567890']);

    $this->postJson(route('api.agent-events'), agentOp('read', ['event_key' => $created->json('event_key')]), $headers)
        ->assertOk()
        ->assertJsonPath('discord.event_id', '1234567890')
        ->assertJsonPath('discord.name', 'Agent proof event')
        ->assertJsonPath('discord.status', 'SCHEDULED');

    Http::assertSent(function ($request) {
        return json_decode($request->body(), true)['action'] === 'event.read';
    });
});

it('throttles mutating calls per grant without spending reads', function () {
    // Faked so the read below never attempts a real bot connection.
    Http::fake([AGENT_BOT_ENDPOINT => Http::response([
        'ok' => true,
        'result' => [
            'outcome' => 'read',
            'event_id' => '1234567890',
            'name' => 'Agent proof event',
            'starts_at' => '2026-10-01T19:00:00Z',
            'location' => 'Voice: General',
            'status' => 'SCHEDULED',
            'observed_at' => '2026-09-27T18:30:00Z',
        ],
        'request_id' => '01JREAD0123456789',
    ])]);

    config()->set('agent-events.mutating_per_minute', 2);
    agentGrant();
    $headers = ['Authorization' => 'Bearer '.AGENT_CREDENTIAL];

    $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), $headers)->assertCreated();
    $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), $headers)->assertConflict();

    // Two mutating attempts spent the budget of 2; the third is throttled
    // rather than answered, with the same shared envelope (TOG-6788) as the
    // human throttles. A read on the separate budget still passes.
    assertThrottleEnvelope($this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), $headers));
    $this->postJson(route('api.agent-events'), agentOp('read'), $headers)->assertOk();
});

// ---------------------------------------------------------------------------
// Route shield: POST /api/agent-events carries throttle:agent-events (TOG-8402).
// ---------------------------------------------------------------------------

it('carries the agent-events throttle on the machine ingress route', function () {
    // The envelope only holds if the shield is registered. If the route loses
    // its `throttle:agent-events` line, unauthenticated floods reach the
    // database — the behaviour tests below would still pass in isolation (the
    // service limiter fires for admitted callers) but a hammer hit without a
    // credential would run the grant lookup and audit write first.
    $route = app('router')->getRoutes()->getByName('api.agent-events');

    expect($route)->not->toBeNull();

    $throttle = collect($route->gatherMiddleware())
        ->first(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'throttle:'));

    expect($throttle)->toBe('throttle:agent-events', 'route api.agent-events must carry throttle:agent-events');
});

it('answers 429 JSON past the outer shield while the normal burst stays under it', function () {
    // Faked like the publish tests: lifecycle moves dispatch the write-back
    // job, and a synchronous driver would take it to the bot.
    Http::fake([AGENT_BOT_ENDPOINT => Http::response(agentBotUpsert())]);

    config()->set('agent-events.route_per_minute', 8);
    agentGrant();
    $headers = ['Authorization' => 'Bearer '.AGENT_CREDENTIAL];

    // The bot's normal burst reconcile: create, update, publish, cancel, and
    // the reads between them — well under the shield, all answered.
    $created = $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), $headers)
        ->assertCreated();

    $this->postJson(
        route('api.agent-events'),
        agentOp('update', [
            'event_key' => $created->json('event_key'),
            'version' => 1,
            'fields' => agentFields(['title' => 'Agent proof event, reconciled']),
        ]),
        $headers
    )->assertOk();

    // Three more distinct mutating attempts on the quota (all 409s, all
    // counted by both layers): the burst is five hits, still under the
    // shield of 8.
    for ($i = 0; $i < 3; $i++) {
        $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), $headers)
            ->assertConflict();
    }

    // Three more hits spend the shield of 8; the ninth is refused by the
    // outer layer with the same shared envelope (TOG-6788) as the human
    // throttles — 429 JSON, never a stack.
    for ($i = 0; $i < 3; $i++) {
        $this->postJson(route('api.agent-events'), agentOp('publish', ['event_key' => $created->json('event_key')]), $headers);
    }

    assertThrottleEnvelope($this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]), $headers));
});

it('spends no shared bucket across credentials on the outer shield', function () {
    config()->set('agent-events.route_per_minute', 2);

    // A second grant needs a second credential: the verifier is unique, so
    // each credential hashes to its own verifier row.
    $otherCredential = 'agent-test-credential-second-grant-entropy';
    AgentEventGrant::query()->create([
        'agent_id' => 'c1f22b2f-d85f-41e1-9c16-9ca24ac06a11',
        'company_id' => 'ef993a7e-5ea7-445f-ba88-27a6a2690c3a',
        'guild_id' => AGENT_STAGING_GUILD,
        'verifier_hash' => AgentEventGrant::verifierFor($otherCredential),
        'max_events' => 1,
    ]);
    config()->set('agent-events.enabled', true);

    $first = ['Authorization' => 'Bearer '.AGENT_CREDENTIAL];
    agentGrant();

    // The first credential spends its shield of 2; the third hit 429s.
    $this->postJson(route('api.agent-events'), agentOp('read'), $first)->assertOk();
    $this->postJson(route('api.agent-events'), agentOp('read'), $first)->assertOk();
    assertThrottleEnvelope($this->postJson(route('api.agent-events'), agentOp('read'), $first));

    // The second credential hashes to its own bucket: still answered while
    // the first is throttled — one crowded egress cannot spend another
    // grant's allowance.
    $this->postJson(route('api.agent-events'), agentOp('read'), ['Authorization' => 'Bearer '.$otherCredential])
        ->assertOk();
});

it('refuses an unauthenticated flood at the shield before the database runs', function () {
    config()->set('agent-events.route_per_minute', 2);

    // No grant, no credential rows touched: the shield counts anonymous hits
    // per IP and refuses before the grant lookup and audit write.
    $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]))
        ->assertUnauthorized();
    $this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()]))
        ->assertUnauthorized();

    assertThrottleEnvelope($this->postJson(route('api.agent-events'), agentOp('create', ['fields' => agentFields()])));

    expect(AgentEventGrant::query()->count())->toBe(0)
        ->and(AgentEventAudit::query()->count())->toBe(0);
});
