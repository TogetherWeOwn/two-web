<?php

use App\Enums\JoinOutcome;
use App\Models\AgentEventGrant;
use App\Models\AgentEventIdempotencyKey;
use App\Models\JoinAttempt;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
 * Retention on the join funnel and the agent replay store (TOG-8710).
 *
 * join_attempts grows by one row per join attempt and
 * agent_event_idempotency_keys by one row per agent operation; without a
 * prune both grow forever. `model:prune` runs the mass delete on each
 * model's prunable(), which can only ever match rows older than the
 * configured window — it is not a general delete anyone can point somewhere
 * else, the same shape as the member-data access log.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-10T12:00:00Z');

    config()->set('join.attempt_retention_days', 90);
    config()->set('agent-events.idempotency_retention_days', 90);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Backdate a row past the retention window without loading it. */
function backdateRow(string $model, int $id, int $daysAgo): void
{
    $model::query()->whereKey($id)->update(['created_at' => now()->subDays($daysAgo)]);
}

// Random UUIDs per grant: no real agent or company id may appear in this
// file (pre-flip scrub TOG-9031). The prune path never reads caller identity,
// so no env-only caller provisioning is needed here.
function pruneGrant(): AgentEventGrant
{
    return AgentEventGrant::query()->create([
        'agent_id' => (string) Str::uuid(),
        'company_id' => (string) Str::uuid(),
        'guild_id' => '1545644954272137297',
        'verifier_hash' => AgentEventGrant::verifierFor('prune-test-credential'),
        'max_events' => 1,
    ]);
}

function pruneKey(AgentEventGrant $grant, string $key): AgentEventIdempotencyKey
{
    return AgentEventIdempotencyKey::query()->create([
        'grant_id' => $grant->getKey(),
        'key' => $key,
        'payload_digest' => hash('sha256', $key),
        'status' => 201,
        'body' => ['event_key' => (string) Str::ulid()],
        'event_key' => null,
    ]);
}

it('prunes join attempts past the retention window and keeps the ones inside it', function () {
    $stale = JoinAttempt::query()->create(['outcome' => JoinOutcome::Added]);
    backdateRow(JoinAttempt::class, $stale->id, 91);

    $kept = JoinAttempt::query()->create(['outcome' => JoinOutcome::Added]);
    backdateRow(JoinAttempt::class, $kept->id, 89);

    $this->artisan('model:prune', ['--model' => [JoinAttempt::class]])->assertSuccessful();

    expect(JoinAttempt::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('prunes idempotency keys past the retention window and keeps the ones inside it', function () {
    $grant = pruneGrant();

    $stale = pruneKey($grant, 'stale-operation');
    backdateRow(AgentEventIdempotencyKey::class, $stale->id, 91);

    $kept = pruneKey($grant, 'fresh-operation');
    backdateRow(AgentEventIdempotencyKey::class, $kept->id, 89);

    $this->artisan('model:prune', ['--model' => [AgentEventIdempotencyKey::class]])->assertSuccessful();

    expect(AgentEventIdempotencyKey::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('has retention scheduled daily, because a retention policy nothing runs is a promise', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'model:prune')
            && str_contains($event->command ?? '', 'JoinAttempt'));

    expect($events)->not->toBeEmpty();

    $events->each(fn ($event) => expect($event->getExpression())->toBe('0 0 * * *')
        ->and($event->command)->toContain('AgentEventIdempotencyKey'));
});
