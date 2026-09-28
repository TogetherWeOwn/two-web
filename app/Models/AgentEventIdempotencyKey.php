<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The caller-scoped replay store for the agent ingress (Gate 2).
 *
 * Unique on `(grant_id, key)`: the same caller, key and normalized payload
 * replays the stored result, and the same key with a different payload is the
 * 409 the contract requires — it means the caller reused an operation identity
 * for a different operation. Rows are never deleted, so a worker restart
 * replays from the database rather than re-executing against Discord.
 *
 * `status` and `body` are the exact HTTP answer the first execution produced,
 * so a replay is byte-identical to the original apart from the `replayed` flag
 * the service adds around it.
 *
 * @property int $id
 * @property string $grant_id
 * @property string $key
 * @property string $payload_digest
 * @property int $status
 * @property array<string, mixed> $body
 * @property string|null $event_key
 */
class AgentEventIdempotencyKey extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'grant_id',
        'key',
        'payload_digest',
        'status',
        'body',
        'event_key',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'body' => 'array',
        ];
    }

    /** @return BelongsTo<AgentEventGrant, $this> */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(AgentEventGrant::class, 'grant_id');
    }
}
