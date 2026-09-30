<?php

namespace App\Models;

use App\Enums\RsvpStatus;
use App\Support\Events\AnonymousEventCard;
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

    protected static function booted(): void
    {
        // TOG-9277: the going badge is part of the guest card, so any answer
        // write retires the cached fragments for the event. `saved` covers the
        // service's `updateOrCreate` and the waitlist promotions (both write
        // through a model instance); `deleted` covers row deletes that do.
        // The withdraw path's mass delete bypasses model events entirely and
        // bumps explicitly in `EventService::withdrawRsvp` instead.
        static::saved(function (Rsvp $rsvp): void {
            AnonymousEventCard::bumpForEventId($rsvp->event_id);
        });
        static::deleted(function (Rsvp $rsvp): void {
            AnonymousEventCard::bumpForEventId($rsvp->event_id);
        });
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
