<?php

namespace App\Models;

use App\Enums\RsvpStatus;
use Carbon\CarbonImmutable;
use Database\Factories\RsvpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $event_id
 * @property int $user_id
 * @property RsvpStatus $status
 * @property CarbonImmutable|null $synced_to_discord_at
 */
class Rsvp extends Model
{
    /** @use HasFactory<RsvpFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'event_id',
        'user_id',
        'status',
        'synced_to_discord_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RsvpStatus::class,

            // Null means "Discord does not know about this yet", and that is the
            // member-visible contract: the page says "saved here, syncing to
            // Discord" rather than lying. The write-back job stamps it.
            'synced_to_discord_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
