<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The caller-scoped replay store for the agent ingress (Gate 2).
 *
 * Unique on `(grant_id, key)`: the same caller, key and normalized payload
 * replays the stored result, and the same key with a different payload is the
 * 409 the contract requires — it means the caller reused an operation identity
 * for a different operation. Rows older than the retention window are pruned
 * daily (see prunable()), so a replay only covers the recent window — a retry
 * arriving after its row was pruned re-executes instead of replaying, and the
 * quota and optimistic-concurrency guards underneath make that safe. A worker
 * restart inside the window still replays from the database rather than
 * re-executing against Discord.
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
    /**
     * Deletes rows past the retention window with one mass query, without ever
     * loading a model. That is the only deletion path this table has, and it
     * can only ever delete by age — the same shape as MemberDataAccessLog.
     */
    use MassPrunable;

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

    /**
     * Retention. Ninety days by default, matching the member-data access log:
     * well past any retry horizon (job backoffs top out at hours), short
     * enough that one row per agent operation does not grow the table
     * forever. A retry arriving after its row was pruned re-executes; the
     * quota guard and optimistic-concurrency version underneath make that
     * a duplicate-safe re-execution rather than a double event.
     *
     * Scheduled daily in routes/console.php.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return $this->newQuery()->where(
            'created_at',
            '<',
            now()->subDays((int) config('agent-events.idempotency_retention_days')),
        );
    }
}
