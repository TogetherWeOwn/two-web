<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One rendered event search: the normalized query plus how many matches the
 * guest saw (TOG-8400).
 *
 * Normalized query and result count only — no user id, no session, no IP, no
 * raw input. Guests search, and a guest search must not mint a
 * person-shaped row. The raw query never reaches this table: normalization
 * (case, whitespace, length) happens in EventSearchLogger, so even an
 * unusual query string cannot smuggle PII past the column list.
 *
 * One row per render, not per session: debounced typing settles through
 * several rendered states and each one is a result set the guest actually
 * saw. Aggregation (group by normalized_query) is the read path — the
 * dashboard widget and the top-miss stat both read that way.
 *
 * @property int $id
 * @property string $normalized_query
 * @property int $result_count
 * @property Carbon $occurred_at
 */
class EventSearchLog extends Model
{
    /**
     * Deletes rows past the retention window with one mass query, without ever
     * loading a model — so there is no per-row hook to trip. That is the only
     * deletion path this table has, and it can only ever delete by age.
     */
    use MassPrunable;

    /**
     * There is nothing to update, so there is nothing for updated_at to record.
     * `occurred_at` is written once, by the logger, and is the only time on
     * a row.
     */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'normalized_query',
        'result_count',
        'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'result_count' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Retention. Long enough to see content gaps across a season of game
     * nights, short enough that we are not keeping a permanent index of what
     * people looked for. Ninety days matches the member-data access log
     * default; shorter is fine with a reason, and the reason belongs in the
     * CHANGELOG for whoever has to explain the gap during an incident.
     *
     * Scheduled daily in routes/console.php.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return $this->newQuery()->where(
            'occurred_at',
            '<',
            now()->subDays((int) config('event_search_log.retention_days')),
        );
    }
}
