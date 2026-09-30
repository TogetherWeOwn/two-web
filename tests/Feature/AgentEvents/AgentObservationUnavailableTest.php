<?php

use App\Enums\EventStatus;
use App\Models\AgentEventAudit;
use App\Models\AgentEventGrant;
use App\Models\Event;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

it('marks a mirrored observation unavailable without inventing fields or changing the event', function (string $scenario, string $reason) {
    Carbon::setTestNow('2026-09-30T12:00:00Z');
    Http::preventStrayRequests();
    Queue::fake();

    $agentId = (string) Str::uuid();
    $credential = 'synthetic-observation-test-credential';
    $guildId = '111111111111111111';
    config()->set([
        'agent-events.enabled' => true,
        'agent-events.caller_agent_id' => $agentId,
        'agent-events.staging_guild_id' => $guildId,
        'agent-events.production_guild_id' => '222222222222222222',
        'services.bot.url' => 'http://bot.test:3001',
        'services.bot.secret' => 'synthetic-observation-test-signing-secret',
        'services.bot.key_id' => 'observation-test',
    ]);

    $grant = AgentEventGrant::query()->create([
        'agent_id' => $agentId,
        'company_id' => (string) Str::uuid(),
        'guild_id' => $guildId,
        'verifier_hash' => AgentEventGrant::verifierFor($credential),
        'max_events' => 1,
    ]);
    $event = Event::factory()->create([
        'created_by' => null,
        'agent_grant_id' => $grant->getKey(),
        'proof_marker' => 'agent-proof-'.Str::uuid(),
        'agent_version' => 7,
        'discord_event_id' => '333333333333333333',
        'status' => EventStatus::Published,
        'title' => 'Local proof is not a Discord observation',
        'location' => 'Local voice channel',
        'starts_at' => Carbon::parse('2026-10-01T18:00:00Z'),
        'ends_at' => Carbon::parse('2026-10-01T20:00:00Z'),
    ]);
    $before = $event->fresh()->getRawOriginal();

    if (str_starts_with($scenario, 'missing_')) {
        config()->set('services.bot.'.substr($scenario, strlen('missing_')), null);
    }

    $attempts = 0;
    Http::fake(function (Request $request) use ($scenario, $reason, $event, &$attempts) {
        $attempts++;
        expect($request->url())->toBe('http://bot.test:3001/internal/actions')
            ->and(json_decode($request->body(), true))->toBe([
                'action' => 'event.read',
                'event_key' => $event->event_key,
            ]);

        if ($scenario === 'unreachable') {
            throw new ConnectionException('Synthetic bot connection failure');
        }

        if (str_starts_with($scenario, 'refusal_')) {
            return Http::response([
                'ok' => false,
                'error' => [
                    'code' => $reason,
                    'message' => 'Synthetic bot refusal',
                    'retryable' => $scenario === 'refusal_retryable',
                ],
                'request_id' => 'synthetic-bot-refusal',
            ], $scenario === 'refusal_retryable' ? 502 : 403);
        }

        // Valid read envelope, but for another mirror. Neither these remote
        // fields nor the local proof fields may become a trusted observation.
        return Http::response([
            'ok' => true,
            'result' => [
                'outcome' => 'read',
                'event_id' => '444444444444444444',
                'name' => 'A different Discord event',
                'starts_at' => '2026-10-02T18:00:00Z',
                'location' => 'Different remote voice channel',
                'status' => 'ACTIVE',
                'observed_at' => '2026-09-30T12:00:00Z',
            ],
            'request_id' => 'synthetic-bot-mismatch',
        ]);
    });

    $response = $this->postJson(route('api.agent-events'), [
        'op' => 'read',
        'event_key' => $event->event_key,
        'idempotency_key' => (string) Str::uuid(),
    ], ['Authorization' => 'Bearer '.$credential])->assertOk();

    // Exact shape rules out event_id/name/starts_at/location/status/observed_at
    // being fabricated from the local row or copied from a mismatched mirror.
    expect($response->json('discord'))->toBe([
        'unavailable' => 'verification_unavailable',
        'reason' => $reason,
    ])
        ->and($response->json('local'))->toBe([
            'status' => 'published',
            'synced_to_discord' => true,
        ])
        ->and($response->json('event.title'))->toBe($event->title)
        ->and($response->json('event.discord_event_id'))->toBe($event->discord_event_id)
        ->and($response->json('event.agent_version'))->toBe(7)
        ->and($event->fresh()->getRawOriginal())->toBe($before)
        ->and(Event::query()->count())->toBe(1)
        ->and($attempts)->toBe(str_starts_with($scenario, 'missing_') ? 0 : 1);

    Queue::assertNothingPushed();
    expect(AgentEventAudit::query()->count())->toBe(1);
    $this->assertDatabaseHas('agent_event_audits', [
        'grant_id' => $grant->getKey(),
        'event_key' => $event->event_key,
        'operation' => 'read',
        'result' => 'ok',
    ]);
})->with([
    'unconfigured URL' => ['missing_url', 'bot_unreachable'],
    'unconfigured secret' => ['missing_secret', 'bot_unreachable'],
    'unconfigured key ID' => ['missing_key_id', 'bot_unreachable'],
    'unreachable bot' => ['unreachable', 'bot_unreachable'],
    'terminal bot refusal' => ['refusal_terminal', 'action_not_allowed'],
    'retryable bot refusal' => ['refusal_retryable', 'discord_unavailable'],
    'mismatched mirror ID' => ['mismatch', 'mirror_mismatch'],
]);
