<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per agent-ingress attempt, including denials (Gate 2).
 *
 * `grant_id` is nullable because an unauthenticated denial has no grant to
 * attribute — the row still records that somebody knocked, with the reason
 * code and without anything secret. `result` is one of `ok`, `denied`,
 * `conflict` or `error`; `reason_code` names which rule fired so the staging
 * proof can show every denial case without re-running it.
 *
 * Retained through grant expiry and rollback: the audit is the evidence the
 * proof gates are judged on, and deleting it with the grant would delete the
 * proof. No credentials, payloads or unrelated event data are stored here —
 * correlation travels in `request_id` across ingress, queue, HMAC and Discord
 * result instead.
 *
 * @property int $id
 * @property string|null $grant_id
 * @property string $operation
 * @property string|null $event_key
 * @property string|null $idempotency_key
 * @property string|null $payload_digest
 * @property string $request_id
 * @property string $result
 * @property string|null $reason_code
 * @property string|null $discord_event_id
 */
class AgentEventAudit extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'grant_id',
        'operation',
        'event_key',
        'idempotency_key',
        'payload_digest',
        'request_id',
        'result',
        'reason_code',
        'discord_event_id',
    ];

    /** @return BelongsTo<AgentEventGrant, $this> */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(AgentEventGrant::class, 'grant_id');
    }
}
