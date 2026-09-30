<?php

use App\Enums\EventStatus;
use App\Models\AgentEventAudit;
use App\Models\AgentEventGrant;
use App\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

it('returns the latest matching prior receipts in chronological order', function (int $priorCount) {
    Http::preventStrayRequests();
    Http::fake();
    config()->set('agent-events.enabled', true);

    $credential = 'receipt-window-test-credential';
    $agentId = (string) Str::uuid();
    config()->set('agent-events.caller_agent_id', $agentId);

    $grant = AgentEventGrant::query()->create([
        'agent_id' => $agentId,
        'company_id' => (string) Str::uuid(),
        'guild_id' => config('agent-events.staging_guild_id'),
        'verifier_hash' => AgentEventGrant::verifierFor($credential),
    ]);
    $foreignGrant = AgentEventGrant::query()->create([
        'agent_id' => (string) Str::uuid(),
        'company_id' => (string) Str::uuid(),
        'guild_id' => config('agent-events.staging_guild_id'),
        'verifier_hash' => AgentEventGrant::verifierFor('foreign-receipt-window-test-credential'),
    ]);
    $event = Event::factory()->create([
        'status' => EventStatus::Draft,
        'agent_grant_id' => $grant->getKey(),
        'proof_marker' => 'agent-proof-receipt-window',
        'agent_version' => 1,
        'discord_event_id' => null,
    ]);

    $priorReceipts = [];
    $start = Carbon::parse('2026-09-01T00:00:00Z');

    for ($i = 0; $i < $priorCount; $i++) {
        $at = $start->copy()->addMinutes($i);
        $audit = AgentEventAudit::query()->make([
            'grant_id' => $grant->getKey(),
            'event_key' => $event->event_key,
            'operation' => 'update',
            'result' => 'ok',
            'reason_code' => null,
            'request_id' => 'prior-receipt-'.$i,
        ]);
        $audit->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        $priorReceipts[] = [
            'operation' => 'update',
            'result' => 'ok',
            'reason_code' => null,
            'request_id' => 'prior-receipt-'.$i,
            'at' => $at->toIso8601String(),
        ];
    }

    // Newer foreign rows must not occupy the bounded window. Each matches
    // only one of the two scope filters, so neither filter can be dropped.
    foreach ([
        [$foreignGrant->getKey(), $event->event_key],
        [$grant->getKey(), (string) Str::ulid()],
    ] as [$grantId, $eventKey]) {
        AgentEventAudit::query()->create([
            'grant_id' => $grantId,
            'event_key' => $eventKey,
            'operation' => 'cancel',
            'result' => 'denied',
            'reason_code' => 'foreign_event',
            'request_id' => (string) Str::uuid(),
        ]);
    }

    $response = $this->postJson(route('api.agent-events'), [
        'op' => 'read',
        'event_key' => $event->event_key,
        'idempotency_key' => (string) Str::uuid(),
    ], ['Authorization' => 'Bearer '.$credential])->assertOk();

    expect($response->json('receipts'))->toBe(array_slice($priorReceipts, -50));

    // The current read is audited after the snapshot, not returned in it.
    expect(AgentEventAudit::query()
        ->where('grant_id', $grant->getKey())
        ->where('event_key', $event->event_key)
        ->count())->toBe($priorCount + 1);
    $this->assertDatabaseHas('agent_event_audits', [
        'grant_id' => $grant->getKey(),
        'event_key' => $event->event_key,
        'operation' => 'read',
        'request_id' => $response->json('request_id'),
        'result' => 'ok',
    ]);
    Http::assertNothingSent();
})->with([
    'empty history' => 0,
    'below the limit' => 3,
    'exactly the limit' => 50,
    'latest 50 of 55' => 55,
]);
