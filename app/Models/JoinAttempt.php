<?php

namespace App\Models;

use App\Enums\JoinOutcome;
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
}
