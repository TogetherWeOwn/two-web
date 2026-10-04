<?php

namespace App\Models;

use App\Enums\EventStatus;
use App\Enums\RecurrenceFrequency;
use App\Enums\RsvpStatus;
use App\Exceptions\ImmutableAttributeException;
use App\Support\Events\AnonymousEventCard;
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
 * @property bool $rsvp_open
 * @property string|null $discord_event_id
 * @property CarbonImmutable|null $discord_sync_failed_at
 * @property string|null $discord_sync_failure_code
 * @property int|null $created_by
 * @property string|null $agent_grant_id
 * @property string|null $proof_marker
 * @property int|null $ics_sequence
 * @property int $agent_version
 * @property RecurrenceFrequency|null $recurrence_frequency
 * @property int|null $recurrence_count
 * @property CarbonImmutable|null $recurrence_ends_on
 * @property int|null $parent_event_id
 * @property int|null $recurrence_index
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
     * log exists to make reviewable. The sync-failure stamp is excluded with
     * it: the bot writes both, and the stamp's home is the job's own error
     * log line, which already carries the code and the request id.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logExcept(['discord_event_id', 'discord_sync_failed_at', 'discord_sync_failure_code'])
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
        'rsvp_open',
        'discord_event_id',
        'discord_sync_failed_at',
        'discord_sync_failure_code',
        'created_by',
        'agent_grant_id',
        'proof_marker',
        'agent_version',
        'recurrence_frequency',
        'recurrence_count',
        'recurrence_ends_on',
        'parent_event_id',
        'recurrence_index',
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
            'discord_sync_failed_at' => 'immutable_datetime',
            'rsvp_open' => 'boolean',
            'agent_version' => 'integer',
            'ics_sequence' => 'integer',
            'recurrence_frequency' => RecurrenceFrequency::class,
            'recurrence_count' => 'integer',
            'recurrence_ends_on' => 'immutable_date',
            'recurrence_index' => 'integer',
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

        // TOG-9277: any moderator or service edit retires the cached guest
        // fragments. `saved` (not `updated`) so the create path bumps too —
        // harmless (nothing is cached yet) and one hook covers every write.
        static::saved(function (Event $event): void {
            // Trigger-generated values are not hydrated by Eloquent save(). Read
            // the whole snapshot so a racing write cannot pair old content with
            // its newer revision in a returned service model or the pure ICS builder.
            $event->setRawAttributes($event->newQuery()->whereKey($event->getKey())->firstOrFail()->getAttributes(), true);

            AnonymousEventCard::bump($event);
        });
        static::deleted(function (Event $event): void {
            AnonymousEventCard::purge($event);
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
     * Members in line for a seat. Not seats — a waitlisted row holds nothing,
     * which is why no `going_count` aggregate needed changing for the waitlist.
     */
    public function waitlistCount(): int
    {
        return $this->rsvps()->where('status', RsvpStatus::Waitlisted)->count();
    }

    /**
     * One-based place in line, earliest answer first. The `id` tiebreak is for
     * answers written in the same second, which is exactly when a full event
     * collects them. Null when the member is not on the waitlist.
     */
    public function waitlistPositionFor(User $user): ?int
    {
        $mine = $this->rsvps()
            ->where('status', RsvpStatus::Waitlisted)
            ->where('user_id', $user->getKey())
            ->first(['id', 'created_at']);

        if ($mine === null) {
            return null;
        }

        return $this->rsvps()
            ->where('status', RsvpStatus::Waitlisted)
            ->where(function ($query) use ($mine): void {
                $query->where('created_at', '<', $mine->created_at)
                    ->orWhere(function ($query) use ($mine): void {
                        $query->where('created_at', $mine->created_at)
                            ->where('id', '<=', $mine->id);
                    });
            })
            ->count();
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
    /**
     * Whether the event takes new answers (TOG-8725). A moderator pause: the
     * event stays published and visible, but the RSVP gate refuses while it
     * is closed — unpublishing to the same end would hide the event itself.
     * Withdrawals are not gated: leaving is always allowed.
     *
     * `!== false` rather than `=== true`: only an explicit pause closes. An
     * in-memory instance that never read the column (a Discord-native
     * transient on the calendar) carries null, and null must read as open.
     */
    public function isRsvpOpen(): bool
    {
        return $this->rsvp_open !== false;
    }

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

    /**
     * The machine grant that owns this event, if any. Null means a human-owned
     * event: the agent ingress may neither read nor change it, and the human
     * paths never set or clear this column.
     *
     * @return BelongsTo<AgentEventGrant, $this>
     */
    public function agentGrant(): BelongsTo
    {
        return $this->belongsTo(AgentEventGrant::class, 'agent_grant_id');
    }

    public function isAgentOwned(): bool
    {
        return $this->agent_grant_id !== null;
    }

    /**
     * Whether this event is the first meeting of a recurring series. The rule
     * lives on the parent; the children carry only the pointer and their index.
     */
    public function isSeriesParent(): bool
    {
        return $this->recurrence_frequency !== null;
    }

    /**
     * Which meeting of the series this row is: the parent is 1, the first
     * materialised child is 2. Null for a one-off.
     */
    public function isSeriesChild(): bool
    {
        return $this->parent_event_id !== null;
    }

    /** @return BelongsTo<Event, $this> */
    public function parentEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_event_id');
    }

    /** @return HasMany<Event, $this> */
    public function childEvents(): HasMany
    {
        return $this->hasMany(self::class, 'parent_event_id')->orderBy('recurrence_index');
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
