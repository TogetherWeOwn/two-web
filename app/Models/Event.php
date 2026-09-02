<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Exceptions\ImmutableAttributeException;
use Carbon\CarbonImmutable;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $event_key
 * @property string $title
 * @property string|null $game
 * @property string|null $description
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $timezone
 * @property string|null $location
 * @property int|null $capacity
 * @property EventStatus $status
 * @property string|null $discord_event_id
 * @property int|null $created_by
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /**
     * `event_key` is deliberately absent: it is generated on create and refused on
     * update, so there is never a legitimate reason to mass-assign it.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'game',
        'description',
        'starts_at',
        'ends_at',
        'timezone',
        'location',
        'capacity',
        'status',
        'discord_event_id',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Immutable, because `$event->starts_at->addHour()` quietly mutating the
            // model's own attribute is a bug that only surfaces in the row you save.
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'capacity' => 'integer',
            'status' => EventStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Event $event): void {
            if (! is_string($event->getAttribute('event_key'))) {
                $event->setAttribute('event_key', (string) Str::ulid());
            }
        });

        static::updating(function (Event $event): void {
            // The bot's `event_key -> discord_event_id` map never forgets. Changing
            // the key here would orphan the Discord event rather than update it, and
            // the failure would be silent on both sides.
            if ($event->isDirty('event_key')) {
                throw ImmutableAttributeException::for($event, 'event_key');
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'event_key';
    }

    /** The start, rendered in the zone the host chose rather than in UTC. */
    public function startsAtLocal(): CarbonImmutable
    {
        return $this->starts_at->setTimezone($this->timezone);
    }

    public function endsAtLocal(): CarbonImmutable
    {
        return $this->ends_at->setTimezone($this->timezone);
    }

    /**
     * Seats taken. "maybe" is not a seat — a member who is unsure should not hold
     * one that somebody who is sure could have.
     */
    public function goingCount(): int
    {
        return $this->rsvps()->where('status', RsvpStatus::Going)->count();
    }

    /**
     * Whether Discord has been shown this event, and so whether a write-back means
     * anything. The rule lives on the enum so adding a state has one place to answer
     * for itself — this was `!== Draft` until Past existed, which would have kept
     * upserting events Discord had already dropped.
     */
    public function isMirroredInDiscord(): bool
    {
        return $this->status->isMirroredInDiscord();
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Rsvp, $this> */
    public function rsvps(): HasMany
    {
        return $this->hasMany(Rsvp::class);
    }
}
