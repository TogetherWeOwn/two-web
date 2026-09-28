<?php

namespace App\Models;

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use Database\Factories\DataRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One member ask about their own data: an export question or a deletion
 * request (TOG-8705, runbook docs/moderator-export-deletion.md).
 *
 * This is identifiers plus decision metadata, never a copy of the data —
 * the export JSON is generated on demand at download time and stored
 * nowhere. Retention: 90 days after close, same window as the access log.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $discord_id
 * @property DataRequestType $type
 * @property DataRequestStatus $status
 * @property int|null $decided_by
 */
class DataRequest extends Model
{
    /** @use HasFactory<DataRequestFactory> */
    use HasFactory;

    use LogsActivity;

    /**
     * Deletes closed rows past the retention window with one mass query,
     * without ever loading a model — so it never needs a policy gate. That
     * is the only deletion path this table has, and `prunable()` can only
     * ever match closed rows older than the configured window.
     */
    use MassPrunable;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'discord_id',
        'type',
        'status',
        'decided_by',
        'decided_at',
        'decision_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DataRequestType::class,
            'status' => DataRequestStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * The moderator queue only ever asks one question: what is still open.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', DataRequestStatus::Pending)->orderBy('created_at');
    }

    /**
     * Retention. Ninety days after close (approved/rejected), same window as
     * the access log — the queue row is the audit trail of the decision and
     * lives as long as the log that says who looked. Pending rows are never
     * matched: an open ask is live work, not history.
     *
     * Scheduled daily in routes/console.php.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return $this->newQuery()
            ->where('status', '!=', DataRequestStatus::Pending)
            ->where(
                'updated_at',
                '<',
                now()->subDays((int) config('data-requests.retention_days')),
            );
    }

    /**
     * Who changed what, when — same audit trail as events and featured
     * content (TOG-54). The trait hangs off model events, so the approve /
     * reject decisions are recorded whichever path writes them.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
