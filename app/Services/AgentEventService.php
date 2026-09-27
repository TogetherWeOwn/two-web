<?php

namespace App\Services;

use App\Http\Requests\StoreEventRequest;
use App\Models\AgentEventAudit;
use App\Models\AgentEventGrant;
use App\Models\AgentEventIdempotencyKey;
use App\Models\Event;
use App\Services\Bot\EventRead;
use App\Services\Bot\Exceptions\BotNotConfiguredException;
use App\Services\Bot\Exceptions\BotTransportException;
use App\Exceptions\EventNotOpenException;
use App\Exceptions\StaleAgentVersionException;
use App\Services\Bot\InternalActionClient;
use App\Services\Bot\InternalActionFailure;
use App\Support\AgentEventRateLimit;
use App\Support\EventInput;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The scoped machine ingress for agent-originated events (TOG-5510/web, Gate 2).
 *
 * One typed endpoint, five operations, one caller. The checks run in an order
 * that spends nothing before the request has earned it:
 *
 *   1. feature enabled, body parses, credential identifies a grant;
 *   2. the operation is one of the five, the grant is live, the guild matches;
 *   3. the idempotency store answers replays for free, before rate limits;
 *   4. rate limits, then the operation under a per-event lock.
 *
 * Denials happen before any domain mutation, queueing or signing, and every
 * attempt — including denials — writes an audit row with a reason code and no
 * secrets. The credential alone identifies the grant: a caller-supplied agent
 * id is never authentication, only attribution read off the matched row.
 *
 * No User, no session, no `is_moderator`. Human routes are untouched; the only
 * shared code is the domain lifecycle (EventService), the field rules and the
 * write-back job, which is where dispatch-time rechecks live.
 */
class AgentEventService
{
    private const OPS = ['create', 'read', 'update', 'publish', 'cancel'];

    public function __construct(
        private readonly EventService $events,
        private readonly InternalActionClient $bot,
    ) {}

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(array $body, ?string $credential): array
    {
        $requestId = (string) Str::ulid();

        if (! (bool) config('agent-events.enabled', false)) {
            return $this->answer(404, [
                'reason' => 'ingress_disabled',
                'message' => 'The agent event ingress is not enabled in this environment.',
                'request_id' => $requestId,
            ]);
        }

        $op = $body['op'] ?? null;
        $idempotencyKey = $body['idempotency_key'] ?? null;

        if (! is_string($op) || ! is_string($idempotencyKey) || $idempotencyKey === '' || mb_strlen($idempotencyKey) > 255) {
            return $this->answer(422, [
                'reason' => 'validation_failed',
                'message' => 'The request needs a string `op` and a non-empty string `idempotency_key` (max 255 characters).',
                'request_id' => $requestId,
            ]);
        }

        $digest = self::digest($body);

        $grant = $credential === null || $credential === ''
            ? null
            : AgentEventGrant::findByCredential($credential);

        if (! $grant instanceof AgentEventGrant) {
            $this->audit(null, $op, null, $idempotencyKey, $digest, $requestId, 'denied', 'unauthenticated', null);

            return $this->answer(401, [
                'reason' => 'unauthenticated',
                'message' => 'A valid machine credential is required.',
                'request_id' => $requestId,
            ]);
        }

        if (! in_array($op, self::OPS, true)) {
            $this->audit($grant, $op, null, $idempotencyKey, $digest, $requestId, 'denied', 'forbidden_action', null);

            return $this->answer(403, [
                'reason' => 'forbidden_action',
                'message' => 'Unknown operation. Only create, read, update, publish and cancel are admitted.',
                'request_id' => $requestId,
            ]);
        }

        if ($grant->isExpired()) {
            $this->audit($grant, $op, null, $idempotencyKey, $digest, $requestId, 'denied', 'grant_expired', null);

            return $this->answer(403, [
                'reason' => 'grant_expired',
                'message' => 'The grant has expired. Expiry rejects ingress and dispatch alike.',
                'request_id' => $requestId,
            ]);
        }

        if ($grant->isDisabled()) {
            $this->audit($grant, $op, null, $idempotencyKey, $digest, $requestId, 'denied', 'grant_disabled', null);

            return $this->answer(403, [
                'reason' => 'grant_disabled',
                'message' => 'The grant has been disabled by its provisioning owner.',
                'request_id' => $requestId,
            ]);
        }

        // The guild comes from the grant, never from the caller. A supplied
        // guild that disagrees — production included — is denied here, before
        // the rate limiter, the lock or anything that resembles work.
        $guildId = $body['guild_id'] ?? null;
        if ($guildId !== null && (string) $guildId !== $grant->guild_id) {
            $this->audit($grant, $op, null, $idempotencyKey, $digest, $requestId, 'denied', 'wrong_guild', null);

            return $this->answer(403, [
                'reason' => 'wrong_guild',
                'message' => 'This grant is bound to one guild; the supplied guild is rejected.',
                'request_id' => $requestId,
            ]);
        }

        // Replays are answered from the store without spending rate budget: a
        // transport retry is not a new operation, and charging it would let a
        // flaky network spend the caller's whole allowance.
        $replay = AgentEventIdempotencyKey::query()
            ->where('grant_id', $grant->getKey())
            ->where('key', $idempotencyKey)
            ->first();

        if ($replay instanceof AgentEventIdempotencyKey) {
            if (! hash_equals($replay->payload_digest, $digest)) {
                $this->audit($grant, $op, $replay->event_key, $idempotencyKey, $digest, $requestId, 'conflict', 'idempotency_conflict', null);

                return $this->answer(409, [
                    'reason' => 'idempotency_conflict',
                    'message' => 'This idempotency key was already used with a different payload. A key identifies one operation.',
                    'request_id' => $requestId,
                ]);
            }

            $replayedBody = $replay->body;
            $replayedBody['replayed'] = true;
            $replayedBody['request_id'] = $requestId;

            return $this->answer($replay->status, $replayedBody);
        }

        try {
            if ($op === 'read') {
                AgentEventRateLimit::hitRead($grant);
            } else {
                AgentEventRateLimit::hitMutating($grant);
            }
        } catch (ThrottleRequestsException $e) {
            $this->audit($grant, $op, null, $idempotencyKey, $digest, $requestId, 'denied', 'rate_limited', null);

            throw $e;
        }

        // Domain and validation failures are audited here and then answered in
        // their own shape: the audit is the evidence the proof gates judge on,
        // and a denial without a row would be a hole in it. The exception's
        // own rendering still decides the HTTP answer for lifecycle races, so
        // the wire shape stays identical to the human routes' for the same
        // rule; field validation is normalized to the ingress envelope with
        // the per-field errors attached.
        $auditEventKey = is_string($body['event_key'] ?? null) ? $body['event_key'] : null;

        try {
            $answer = match ($op) {
                'create' => $this->create($grant, $body, $idempotencyKey, $digest, $requestId),
                'read' => $this->read($grant, $body, $idempotencyKey, $digest, $requestId),
                default => $this->mutateOwned($grant, $op, $body, $idempotencyKey, $digest, $requestId),
            };
        } catch (ValidationException $e) {
            $this->audit($grant, $op, $auditEventKey, $idempotencyKey, $digest, $requestId, 'denied', 'validation_failed', null);

            return $this->answer(422, [
                'reason' => 'validation_failed',
                'message' => 'The event fields did not validate.',
                'errors' => $e->errors(),
                'request_id' => $requestId,
            ]);
        } catch (StaleAgentVersionException|EventNotOpenException $e) {
            $this->audit($grant, $op, $auditEventKey, $idempotencyKey, $digest, $requestId, 'denied', $e instanceof StaleAgentVersionException ? 'stale_version' : 'event_not_open', null);

            throw $e;
        } catch (LockTimeoutException $e) {
            // Contention, not a decision: another operation on the same event
            // or grant holds the lock. Retryable, and audited as an error so
            // a run of these reads as load rather than as denials.
            $this->audit($grant, $op, $auditEventKey, $idempotencyKey, $digest, $requestId, 'error', 'operation_busy', null);

            return $this->answer(503, [
                'reason' => 'operation_busy',
                'message' => 'Another operation on this event is still running. Retry with the same idempotency key.',
                'request_id' => $requestId,
            ]);
        }

        // The first execution's exact answer is what the next identical call
        // replays. Stored inside the same lock that ran it, so a concurrent
        // retry cannot execute twice and then store twice. The unique index
        // underneath is the backstop for the race the lock cannot see; the
        // loser answers from the winner's row rather than erroring.
        if ($answer['stored'] === true) {
            $raced = $this->storeReplay($grant, $op, $idempotencyKey, $digest, $answer, $requestId);

            if ($raced !== null) {
                return $raced;
            }
        }

        return $this->answer($answer['status'], $answer['body']);
    }

    /**
     * Store one execution's answer, or answer from the concurrent winner.
     *
     * @param  array{status: int, body: array<string, mixed>, event_key: string|null, stored: bool|null}  $answer
     * @return array{status: int, body: array<string, mixed>}|null null when this run stored and proceeds
     */
    private function storeReplay(AgentEventGrant $grant, string $op, string $idempotencyKey, string $digest, array $answer, string $requestId): ?array
    {
        try {
            AgentEventIdempotencyKey::query()->create([
                'grant_id' => $grant->getKey(),
                'key' => $idempotencyKey,
                'payload_digest' => $digest,
                'status' => $answer['status'],
                'body' => $answer['body'],
                'event_key' => $answer['event_key'],
            ]);

            return null;
        } catch (QueryException $e) {
            // Postgres unique violation on (grant_id, key): a concurrent
            // identical call stored first. Anything else is a real failure.
            if ($e->getCode() !== '23505') {
                throw $e;
            }
        }

        $winner = AgentEventIdempotencyKey::query()
            ->where('grant_id', $grant->getKey())
            ->where('key', $idempotencyKey)
            ->first();

        if (! $winner instanceof AgentEventIdempotencyKey || ! hash_equals($winner->payload_digest, $digest)) {
            $this->audit($grant, $op, null, $idempotencyKey, $digest, $requestId, 'conflict', 'idempotency_conflict', null);

            return $this->answer(409, [
                'reason' => 'idempotency_conflict',
                'message' => 'This idempotency key was already used with a different payload. A key identifies one operation.',
                'request_id' => $requestId,
            ]);
        }

        $body = $winner->body;
        $body['replayed'] = true;
        $body['request_id'] = $requestId;

        return $this->answer($winner->status, $body);
    }

    /**
     * @return array{status: int, body: array<string, mixed>, event_key: string|null, stored: bool|null}
     */
    private function create(AgentEventGrant $grant, array $body, string $idempotencyKey, string $digest, string $requestId): array
    {
        // Serialised per grant, not per event: there is no event yet, and the
        // quota ("one proof event per grant") is a property of the grant. The
        // lock makes the check-then-insert one atomic decision; the unique
        // index underneath is the backstop, not the plan.
        /** @var array{status: int, body: array<string, mixed>, event_key: string|null, stored: bool|null} $result */
        $result = Cache::lock('agent-event-grant:'.$grant->getKey(), 10)->block(5, function () use ($grant, $body, $idempotencyKey, $digest, $requestId): array {
            return DB::transaction(function () use ($grant, $body, $idempotencyKey, $digest, $requestId): array {
                $existing = Event::query()->where('agent_grant_id', $grant->getKey())->first();

                if ($existing instanceof Event) {
                    $this->audit($grant, 'create', null, $idempotencyKey, $digest, $requestId, 'denied', 'quota_exceeded', null);

                    return ['status' => 409, 'body' => [
                        'reason' => 'quota_exceeded',
                        'message' => 'This grant already owns its one proof event. Updates reuse it.',
                        'event_key' => $existing->event_key,
                        'request_id' => $requestId,
                    ], 'event_key' => null, 'stored' => null];
                }

                $input = $this->fields($body);

                // The unique index is the quota's backstop under concurrency:
                // a second owned event for this grant fails here rather than
                // existing, and the failure is the same 409 the check above
                // returns rather than a 500.
                try {
                    $event = $this->events->createForGrant($grant, $input, 'agent-proof-'.((string) Str::ulid()));
                } catch (QueryException $e) {
                    if ($e->getCode() !== '23505') {
                        throw $e;
                    }

                    $this->audit($grant, 'create', null, $idempotencyKey, $digest, $requestId, 'denied', 'quota_exceeded', null);

                    return ['status' => 409, 'body' => [
                        'reason' => 'quota_exceeded',
                        'message' => 'This grant already owns its one proof event. Updates reuse it.',
                        'request_id' => $requestId,
                    ], 'event_key' => null, 'stored' => null];
                }

                $responseBody = [
                    'event_key' => $event->event_key,
                    'status' => $event->status->value,
                    'agent_version' => $event->agent_version,
                    'proof_marker' => $event->proof_marker,
                    'request_id' => $requestId,
                ];

                $this->audit($grant, 'create', $event->event_key, $idempotencyKey, $digest, $requestId, 'ok', null, null);

                return ['status' => 201, 'body' => $responseBody, 'event_key' => $event->event_key, 'stored' => true];
            });
        });

        return $result;
    }

    /**
     * @return array{status: int, body: array<string, mixed>, event_key: string|null, stored: bool|null}
     */
    private function read(AgentEventGrant $grant, array $body, string $idempotencyKey, string $digest, string $requestId): array
    {
        $event = $this->ownedEvent($grant, $body);

        if (! $event instanceof Event) {
            $this->audit($grant, 'read', is_string($body['event_key'] ?? null) ? $body['event_key'] : null, $idempotencyKey, $digest, $requestId, 'denied', $event, null);

            $code = $event === 'foreign_event' ? 403 : 404;

            return ['status' => $code, 'body' => [
                'reason' => $event,
                'message' => $event === 'foreign_event'
                    ? 'That event is not owned by this grant.'
                    : 'This grant owns no such event.',
                'request_id' => $requestId,
            ], 'event_key' => null, 'stored' => null];
        }

        $responseBody = [
            'event' => $this->proofFields($event),
            // Local lifecycle and queue state, kept visibly separate from the
            // timestamped Discord observation below: a local receipt is not an
            // independent read-back, and the proof must never confuse the two.
            'local' => [
                'status' => $event->status->value,
                'synced_to_discord' => $event->discord_event_id !== null,
            ],
            'discord' => $this->observe($event),
            'owned_event_count' => Event::query()->where('agent_grant_id', $grant->getKey())->count(),
            // The proof-marker duplicate observation, local-mapping half: how
            // many rows carry this event's server-generated marker. The unique
            // index holds it at one; the count is the evidence, not the
            // enforcement. The Discord-guild half arrives with the bot slice's
            // list capability — a local count alone cannot detect an orphaned
            // Discord duplicate, and this field does not pretend otherwise.
            'proof_marker_matches' => $event->proof_marker === null
                ? 0
                : Event::query()->where('proof_marker', $event->proof_marker)->count(),
            'receipts' => $this->receipts($grant, $event),
            'request_id' => $requestId,
        ];

        $this->audit($grant, 'read', $event->event_key, $idempotencyKey, $digest, $requestId, 'ok', null, $event->discord_event_id);

        return ['status' => 200, 'body' => $responseBody, 'event_key' => $event->event_key, 'stored' => true];
    }

    /**
     * @return array{status: int, body: array<string, mixed>, event_key: string|null, stored: bool|null}
     */
    private function mutateOwned(AgentEventGrant $grant, string $op, array $body, string $idempotencyKey, string $digest, string $requestId): array
    {
        $event = $this->ownedEvent($grant, $body);

        if (! $event instanceof Event) {
            $this->audit($grant, $op, is_string($body['event_key'] ?? null) ? $body['event_key'] : null, $idempotencyKey, $digest, $requestId, 'denied', $event, null);

            $code = $event === 'foreign_event' ? 403 : 404;

            return ['status' => $code, 'body' => [
                'reason' => $event,
                'message' => $event === 'foreign_event'
                    ? 'That event is not owned by this grant.'
                    : 'This grant owns no such event.',
                'request_id' => $requestId,
            ], 'event_key' => null, 'stored' => null];
        }

        // Per-event serialisation: a delayed publish or update that arrives
        // after a cancel must find the cancelled row and fail, never resurrect
        // it. The row lock inside the domain service is the second half; this
        // lock is what orders two ingress calls against each other.
        /** @var array{status: int, body: array<string, mixed>, event_key: string|null, stored: bool|null} $result */
        $result = Cache::lock('agent-event:'.$event->event_key, 10)->block(5, function () use ($grant, $op, $body, $event, $idempotencyKey, $digest, $requestId): array {
            return DB::transaction(function () use ($grant, $op, $body, $event, $idempotencyKey, $digest, $requestId): array {
                // Re-read under the lock: the ownership and lifecycle answers
                // above are from before the wait, and the wait is the point.
                $fresh = Event::query()->where('event_key', $event->event_key)->first();

                if (! $fresh instanceof Event || (string) $fresh->agent_grant_id !== (string) $grant->getKey()) {
                    $this->audit($grant, $op, $event->event_key, $idempotencyKey, $digest, $requestId, 'denied', 'foreign_event', null);

                    return ['status' => 403, 'body' => [
                        'reason' => 'foreign_event',
                        'message' => 'That event is not owned by this grant.',
                        'request_id' => $requestId,
                    ], 'event_key' => null, 'stored' => null];
                }

                if ($op === 'update') {
                    $version = $body['version'] ?? null;

                    if (! is_int($version)) {
                        return ['status' => 422, 'body' => [
                            'reason' => 'validation_failed',
                            'message' => 'An update needs the integer `version` last seen on a read.',
                            'request_id' => $requestId,
                        ], 'event_key' => null, 'stored' => null];
                    }

                    // StaleAgentVersionException renders its own 409; let it.
                    $updated = $this->events->updateForGrant($fresh, $this->fields($body), $version);

                    $responseBody = [
                        'event_key' => $updated->event_key,
                        'status' => $updated->status->value,
                        'agent_version' => $updated->agent_version,
                        'request_id' => $requestId,
                    ];

                    $this->audit($grant, 'update', $updated->event_key, $idempotencyKey, $digest, $requestId, 'ok', null, $updated->discord_event_id);

                    return ['status' => 200, 'body' => $responseBody, 'event_key' => $updated->event_key, 'stored' => true];
                }

                // publish and cancel go through the shared transitions, so a
                // cancelled event stays terminal and a draft cancel dispatches
                // the job that finds nothing to mirror. Domain exceptions
                // (EventNotOpenException) render their own 409; let them.
                $transitioned = $op === 'publish' ? $this->events->publish($fresh) : $this->events->cancel($fresh);

                $responseBody = [
                    'event_key' => $transitioned->event_key,
                    'status' => $transitioned->status->value,
                    'request_id' => $requestId,
                ];

                $this->audit($grant, $op, $transitioned->event_key, $idempotencyKey, $digest, $requestId, 'ok', null, $transitioned->discord_event_id);

                return ['status' => 200, 'body' => $responseBody, 'event_key' => $transitioned->event_key, 'stored' => true];
            });
        });

        return $result;
    }

    /**
     * The owned event for this call, or why there isn't one.
     *
     * An explicit `event_key` addresses that event; omitted, the grant's
     * single owned event answers (there is at most one by quota). Unknown
     * keys are 404; known-but-not-mine is 403 — the distinction matches the
     * human routes' implicit binding, and neither answer leaks anything but
     * the key the caller already supplied.
     *
     * @return Event|'event_not_found'|'foreign_event'
     */
    private function ownedEvent(AgentEventGrant $grant, array $body): Event|string
    {
        $key = $body['event_key'] ?? null;

        if ($key === null) {
            $owned = Event::query()->where('agent_grant_id', $grant->getKey())->first();

            return $owned instanceof Event ? $owned : 'event_not_found';
        }

        if (! is_string($key) || $key === '') {
            return 'event_not_found';
        }

        $event = Event::query()->where('event_key', $key)->first();

        if (! $event instanceof Event) {
            return 'event_not_found';
        }

        if ((string) $event->agent_grant_id !== (string) $grant->getKey()) {
            return 'foreign_event';
        }

        return $event;
    }

    /**
     * The independent Discord observation for a read, fail-closed.
     *
     * Only proof-owned mirror fields ever cross this boundary. When the bot
     * has no `event.read` yet (its slice is still in progress), when the
     * event was never mirrored, or when the bot cannot be reached, the answer
     * is `verification_unavailable` — a marked absence, never an error and
     * never a local receipt dressed up as an observation.
     *
     * @return array<string, mixed>
     */
    private function observe(Event $event): array
    {
        if ($event->discord_event_id === null) {
            return ['unavailable' => 'verification_unavailable', 'reason' => 'never_mirrored'];
        }

        try {
            $answer = $this->bot->readEvent(new EventRead($event->event_key), InternalActionClient::newIdempotencyKey());
        } catch (BotNotConfiguredException|BotTransportException $e) {
            return ['unavailable' => 'verification_unavailable', 'reason' => 'bot_unreachable'];
        }

        if ($answer instanceof InternalActionFailure) {
            return ['unavailable' => 'verification_unavailable', 'reason' => $answer->code];
        }

        if ($answer->discordEventId !== $event->discord_event_id) {
            return ['unavailable' => 'verification_unavailable', 'reason' => 'mirror_mismatch'];
        }

        return [
            'event_id' => $answer->discordEventId,
            'name' => $answer->name,
            'starts_at' => $answer->startsAt,
            'location' => $answer->location,
            'status' => $answer->status,
            'observed_at' => $answer->observedAt,
        ];
    }

    /**
     * Only proof-owned fields. The autoincrement id, `created_by` and anything
     * the grant did not create never leave through this ingress.
     *
     * @return array<string, mixed>
     */
    private function proofFields(Event $event): array
    {
        return [
            'event_key' => $event->event_key,
            'title' => $event->title,
            'game' => $event->game,
            'description' => $event->description,
            'starts_at' => $event->starts_at->toIso8601String(),
            'ends_at' => $event->ends_at->toIso8601String(),
            'timezone' => $event->timezone,
            'location' => $event->location,
            'capacity' => $event->capacity,
            'status' => $event->status->value,
            'agent_version' => $event->agent_version,
            'proof_marker' => $event->proof_marker,
            'discord_event_id' => $event->discord_event_id,
        ];
    }

    /**
     * Recent operation receipts for this grant and event, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function receipts(AgentEventGrant $grant, Event $event): array
    {
        return AgentEventAudit::query()
            ->where('grant_id', $grant->getKey())
            ->where('event_key', $event->event_key)
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (AgentEventAudit $audit): array => [
                'operation' => $audit->operation,
                'result' => $audit->result,
                'reason_code' => $audit->reason_code,
                'request_id' => $audit->request_id,
                'at' => $audit->created_at->toIso8601String(),
            ])
            ->all();
    }

    /** Event fields, validated against the same rules the human form answers to. */
    private function fields(array $body): EventInput
    {
        $fields = $body['fields'] ?? null;

        if (! is_array($fields)) {
            throw ValidationException::withMessages(['fields' => 'An object of event `fields` is required.']);
        }

        $validator = Validator::make($fields, StoreEventRequest::fieldRules());

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return EventInput::fromValidated($validator->validated());
    }

    private function audit(
        ?AgentEventGrant $grant,
        string $operation,
        ?string $eventKey,
        ?string $idempotencyKey,
        ?string $digest,
        string $requestId,
        string $result,
        ?string $reason,
        ?string $discordEventId,
    ): void {
        AgentEventAudit::query()->create([
            'grant_id' => $grant?->getKey(),
            'operation' => mb_substr($operation, 0, 32),
            'event_key' => $eventKey,
            'idempotency_key' => $idempotencyKey,
            'payload_digest' => $digest,
            'request_id' => $requestId,
            'result' => $result,
            'reason_code' => $reason,
            'discord_event_id' => $discordEventId,
        ]);
    }

    /**
     * The identity of a request payload: recursive key-sorted JSON, hashed.
     *
     * Sorting means key order never distinguishes two payloads; the hash means
     * the store carries equality, not content. Hash comparison uses
     * hash_equals at the call site.
     */
    public static function digest(array $body): string
    {
        return hash('sha256', (string) json_encode(
            self::sortRecursive($body),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    /** @param  array<string, mixed>  $value @return array<string, mixed> */
    private static function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortRecursive($item);
            }
        }

        ksort($value);

        return $value;
    }

    /** @param  array<string, mixed>  $body @return array{status: int, body: array<string, mixed>} */
    private function answer(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
