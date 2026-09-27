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
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

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

    use LogsActivity;

    /**
     * The audit trail TOG-54 asks for: who changed what, when — including
     * changes made outside the panel, since the trait hangs off model events.
     * Only dirty attributes are stored; a save that changed nothing writes no
     * row. `discord_event_id` is excluded because the bot writes it, not a
     * person, and a trail of bot bookkeeping buries the moderator actions the
     * log exists to make reviewable.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['discord_event_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

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

    /**
     * Whether the event is over for display purposes.
     *
     * The clock, not just the status: `events:reconcile` flips finished rows to
     * Past every ten minutes, so a recently finished event is still Published on
     * the clock's terms. A share page (or RSVP control) that reads status alone
     * offers a live button for an event that has already happened.
     */
    public function hasEnded(): bool
    {
        if ($this->status === EventStatus::Past) {
            return true;
        }

        // `ends_at` is non-nullable on the model, and every other reader
        // (`endsAtLocal()`, the ICS export, the reconcile query) treats it
        // that way — no null guard here either.
        return $this->ends_at->isPast();
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

    /**
     * The signed-in member's own answer, as an eager-loadable relation.
     *
     * This exists for the events page. Every card carries its own RSVP control, and
     * each control asking the database for its own row is a query per card; eager
     * loading this turns that back into one query for the whole page.
     *
     * It reads `auth()` deliberately — the question is "mine", and a relation that
     * has to be handed a user cannot be named in `with()`. For a guest the
     * constraint matches nothing, which is the right answer rather than an error.
     *
     * @return HasMany<Rsvp, $this>
     */
    public function viewerRsvps(): HasMany
    {
        return $this->rsvps()->where('user_id', auth()->id());
    }
}
