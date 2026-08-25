<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One record of member data being read through the admin panel.
 *
 * Append-only. A log that can be edited by the same application that writes it
 * answers "who looked?" only for people who did not think to tidy up afterwards,
 * which is not the population it exists for.
 *
 * @property string $viewer_discord_id
 * @property list<int> $subject_user_ids
 */
class MemberDataAccessLog extends Model
{
    /**
     * Deletes rows past the retention window with one mass query, without ever
     * loading a model — so it never trips the delete guard below. That is the
     * only deletion path this table has, and it can only ever delete by age.
     */
    use MassPrunable;

    /**
     * There is nothing to update, so there is nothing for updated_at to record.
     * `occurred_at` is written once, by the recorder, and is the only time on
     * a row.
     */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'viewer_discord_id',
        'viewer_user_id',
        'resource',
        'action',
        'subject_user_ids',
        'subject_count',
        'route',
        'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subject_user_ids' => 'array',
            'subject_count' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Append-only, enforced in the one place every write goes through.
     *
     * This is a guard rail and not the boundary. Anything holding the database
     * credentials can ignore it. The boundary is the grant: the application role
     * should have INSERT and SELECT here and not UPDATE or DELETE, which makes
     * the retention job below the only thing on the box that can remove a row.
     * This guard is what turns "somebody did it by accident in a tinker session"
     * into a stack trace instead of a silent hole.
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('member_data_access_logs is append-only; a written access record is not editable.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('member_data_access_logs is append-only; records leave only through retention pruning.');
        });
    }

    /**
     * Retention. Long enough to investigate something somebody noticed late,
     * short enough that we are not keeping a permanent index of who read what.
     * Ninety days is the default and is a decision, not a constant — shorter is
     * fine if there is a reason, and the reason belongs in the CHANGELOG for
     * whoever has to explain the gap during an incident.
     *
     * Scheduled daily in routes/console.php.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return $this->newQuery()->where(
            'occurred_at',
            '<',
            now()->subDays((int) config('member_access_log.retention_days')),
        );
    }

    /** @return BelongsTo<User, $this> */
    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewer_user_id');
    }
}
