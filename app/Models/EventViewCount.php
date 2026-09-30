<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per event per day holding that day's page-view count (TOG-8408).
 *
 * This is the write pattern, not a raw log: the counter increments the row for
 * (event, today) rather than inserting one row per visitor, so there is no
 * rollup job to schedule and no retention problem to manage — a popular event
 * collects 365 rows a year, not a row per view. The lifetime total is a SUM
 * over these rows (see Event::viewCount()).
 */
class EventViewCount extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'viewed_on',
        'views',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'viewed_on' => 'immutable_date',
            'views' => 'integer',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
