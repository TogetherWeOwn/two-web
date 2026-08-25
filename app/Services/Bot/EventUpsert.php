<?php

namespace App\Services\Bot;

use App\Services\Bot\Exceptions\InvalidActionRequestException;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * One `event.upsert` call, validated at construction.
 *
 * Two things about this type are deliberate and load-bearing.
 *
 * **There is no `channel_key`.** The bot takes either a voice-channel event
 * (`channel_key`) or an external one with a place written on it (`location`),
 * exactly one, never both and never neither. Modelling that as two nullable
 * fields would put the choice back in the caller's hands and every test we
 * could write would still pass with `channel_key` — and then fail on first
 * contact with a live bot, non-retryably, because the channel-key map starts
 * empty and nobody has set `TWO_INTERNAL_CHANNEL_KEYS`. So the type only knows
 * how to describe an external event, and the "exactly one" rule is arithmetic
 * rather than a check.
 *
 * **`event_key` is ours, not Discord's.** The bot keeps `event_key ->
 * discord_event_id`, so the same key creates once and updates thereafter. That
 * is a different guarantee from the idempotency key, which makes a *retry of one
 * request* safe: editing the event next week is a new operation and takes a
 * fresh idempotency key with the same event key.
 */
final readonly class EventUpsert
{
    /** Discord's own ceilings, enforced here so the error names the field. */
    private const MAX_NAME = 100;

    private const MAX_DESCRIPTION = 1000;

    public Carbon $startsAt;

    public Carbon $endsAt;

    public function __construct(
        public string $eventKey,
        public string $name,
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
        public string $location,
        public ?string $description = null,
    ) {
        $this->startsAt = Carbon::instance($startsAt)->utc();
        $this->endsAt = Carbon::instance($endsAt)->utc();

        $this->validate();
    }

    /**
     * The wire payload, in the order it will be serialised and signed.
     *
     * `description` is omitted rather than sent as null: absent and null are not
     * the same thing to a validator that checks types, and the bot's is one.
     *
     * @return array<string, string>
     */
    public function toPayload(): array
    {
        $payload = [
            'action' => 'event.upsert',
            'event_key' => $this->eventKey,
            'name' => $this->name,
            // Zulu, not an offset. The bot is a Node process and parses either,
            // but an offset is a value somebody has to reinterpret, and TOG-52
            // is the card where timezones get done properly the first time.
            'starts_at' => $this->startsAt->toIso8601ZuluString(),
            'ends_at' => $this->endsAt->toIso8601ZuluString(),
            'location' => $this->location,
        ];

        if ($this->description !== null) {
            $payload['description'] = $this->description;
        }

        return $payload;
    }

    private function validate(): void
    {
        if (trim($this->eventKey) === '') {
            throw new InvalidActionRequestException('An event.upsert needs an event_key: it is how the bot finds the event to update.');
        }

        if (trim($this->name) === '') {
            throw new InvalidActionRequestException('An event.upsert needs a name.');
        }

        // Characters, not bytes. Discord counts characters, and mb_strlen is the
        // difference between accepting and refusing a hundred-character name
        // with an accent in it.
        if (mb_strlen($this->name) > self::MAX_NAME) {
            throw new InvalidActionRequestException(
                'An event name is at most '.self::MAX_NAME.' characters; this one is '.mb_strlen($this->name).'.'
            );
        }

        if ($this->description !== null && mb_strlen($this->description) > self::MAX_DESCRIPTION) {
            throw new InvalidActionRequestException(
                'An event description is at most '.self::MAX_DESCRIPTION.' characters; this one is '.mb_strlen($this->description).'.'
            );
        }

        if (trim($this->location) === '') {
            throw new InvalidActionRequestException(
                'An event.upsert needs a location. The bot takes exactly one of channel_key or location, and this client only ever sends location.'
            );
        }

        // Strictly after. Discord refuses a zero-length event and the bot turns
        // that into a `malformed` naming both fields.
        if (! $this->endsAt->greaterThan($this->startsAt)) {
            throw new InvalidActionRequestException('An event must end after it starts.');
        }
    }
}
