<?php

namespace App\Models;

use App\Enums\JoinOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One terminal outcome of the one-click join journey (TOG-5617).
 *
 * Written by JoinController alongside its existing log calls — the logs stay
 * the human-readable trail, this row is the queryable one the admin funnel
 * widget counts. Deliberately narrow: an outcome, where the attempt came
 * from, and the two ids that join it back to the bot's logs. Anything secret
 * (tokens), diagnostic (exception messages) or attacker-shaped
 * (error_description) must never reach this table.
 *
 * @property int $id
 * @property JoinOutcome $outcome
 * @property string|null $source
 * @property string|null $request_id
 * @property string|null $discord_id
 */
class JoinAttempt extends Model
{
    /**
     * Deletes rows past the retention window with one mass query, without ever
     * loading a model. That is the only deletion path this table has, and it
     * can only ever delete by age — the same shape as MemberDataAccessLog.
     */
    use MassPrunable;

    /** @var list<string> */
    protected $fillable = [
        'outcome',
        'source',
        'request_id',
        'discord_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'outcome' => JoinOutcome::class,
        ];
    }

    /**
     * Retention. Ninety days by default, matching the member-data access log:
     * long enough to see a funnel break that started weeks ago, short enough
     * that a row per join attempt does not grow the table forever. The admin
     * funnel widget counts this table, so pruning narrows what it sees — the
     * widget shows the retention window, not all time.
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
            now()->subDays((int) config('join.attempt_retention_days')),
        );
    }
}
