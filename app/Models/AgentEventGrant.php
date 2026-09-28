<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The admitted machine-actor grant for the agent event ingress (Gate 2).
 *
 * One row names one caller (the Community Manager agent), one company, one
 * guild and a bounded purpose. It deliberately carries no User id, no session
 * and no moderator flag: borrowing any of those would make the agent's rights
 * follow a human's instead of the scope the CISO admitted.
 *
 * Authentication is possession of the opaque credential whose SHA-256 hex is
 * `verifier_hash`. The credential itself is never stored, never logged and
 * never returned — only the verifier, which is unique so the credential alone
 * identifies the grant. A caller-supplied agent id is attribution read off the
 * matched row, never authentication.
 *
 * @property string $id
 * @property string $agent_id
 * @property string $company_id
 * @property string $guild_id
 * @property string $verifier_hash
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $disabled_at
 * @property int $max_events
 */
class AgentEventGrant extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'agent_id',
        'company_id',
        'guild_id',
        'verifier_hash',
        'expires_at',
        'disabled_at',
        'max_events',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'max_events' => 'integer',
        ];
    }

    /** The one-way verifier stored for a credential. SHA-256, hex. */
    public static function verifierFor(string $credential): string
    {
        return hash('sha256', $credential);
    }

    /** Find the grant a credential belongs to, or null. No exceptions: an
     * unknown credential is an unauthenticated request, not an error. */
    public static function findByCredential(string $credential): ?self
    {
        if ($credential === '') {
            return null;
        }

        return static::query()->where('verifier_hash', static::verifierFor($credential))->first();
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Live means neither disabled nor expired. Checked at ingress and again
     * at worker dispatch, so expiry stops queued work as well as new calls. */
    public function isActive(): bool
    {
        return ! $this->isDisabled() && ! $this->isExpired();
    }

    /** @return HasMany<AgentEventAudit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(AgentEventAudit::class, 'grant_id');
    }

    /** @return HasMany<AgentEventIdempotencyKey, $this> */
    public function idempotencyKeys(): HasMany
    {
        return $this->hasMany(AgentEventIdempotencyKey::class, 'grant_id');
    }
}
