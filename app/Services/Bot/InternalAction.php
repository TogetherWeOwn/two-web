<?php

namespace App\Services\Bot;

use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One call to the bot's action allowlist, validated at the point it is built.
 *
 * There are three live actions (`docs/INTERNAL_ACTIONS.md` v0.3 §3) and this
 * class has exactly three named constructors, so "which actions can the website
 * ask for" is answerable by reading the method list. `guild.add_member` is
 * deliberately absent: it is switched off pending the CEO's sign-off (TOG-57),
 * and §5 requires it to be a *synchronous* call precisely so a live member
 * credential never lands in the `jobs` table. It does not belong on the queued
 * path this class serves, and adding it here later would be the wrong shortcut.
 *
 * **Why the validation is here and not in the job.** Every rule below is one the
 * bot enforces anyway, and would answer with a typed `malformed`. But a job
 * validates on a queue worker, minutes later, where the failure is a row in
 * `failed_jobs` that nobody is looking at. Constructing the action in the web
 * request means a bad announcement is an exception in the request that wrote
 * it, with a stack trace pointing at the caller. The bot stays authoritative —
 * these limits are copied from §3 and are not permitted to drift into being a
 * second, subtly different specification.
 */
final class InternalAction
{
    public const ROLE_ASSIGN = 'role.assign';

    public const ANNOUNCEMENT_POST = 'announcement.post';

    public const EVENT_UPSERT = 'event.upsert';

    /**
     * The actions §3 marks *needs key*. A needs-key action sent without an
     * `Idempotency-Key` is a `malformed`, not a best-effort attempt — so this
     * list decides whether {@see CallInternalAction} mints a key, rather than
     * every caller having to remember.
     *
     * @var list<string>
     */
    private const NEEDS_IDEMPOTENCY_KEY = [self::ANNOUNCEMENT_POST, self::EVENT_UPSERT];

    /**
     * @param  array<string, mixed>  $payload
     */
    private function __construct(
        public readonly string $name,
        public readonly array $payload,
    ) {}

    /**
     * `role.assign` — idempotency is natural, so no key (§3).
     *
     * `$roleKey` is a key, never a Discord snowflake: the bot holds the map, and
     * the set of assignable keys starts from the roles a member can already give
     * themselves in the onboarding menu. Passing an ID here would not work and
     * is not meant to.
     */
    public static function roleAssign(string $discordId, string $roleKey): self
    {
        self::requireNonEmpty($discordId, 'discord_id');
        self::requireNonEmpty($roleKey, 'role_key');

        // A snowflake is decimal digits. Checking it here turns "the bot said
        // malformed" into a message naming the field, at the call site.
        if (preg_match('/^\d{1,20}$/', $discordId) !== 1) {
            throw new InvalidArgumentException("discord_id must be a Discord snowflake (decimal digits), got: {$discordId}");
        }

        return new self(self::ROLE_ASSIGN, [
            'action' => self::ROLE_ASSIGN,
            'discord_id' => $discordId,
            'role_key' => $roleKey,
        ]);
    }

    /**
     * `announcement.post` — *needs key* (§3).
     *
     * The 2000-character ceiling is Discord's own, enforced by the bot so we get
     * a typed error naming the field. Counted in characters and not bytes,
     * because that is what Discord counts.
     *
     * Worth knowing rather than rediscovering: the bot posts every announcement
     * with `allowed_mentions: { parse: [] }`, so an `@everyone` in this body
     * appears as typed and notifies nobody. That is deliberate and it is not a
     * bug to report.
     */
    public static function announcementPost(string $channelKey, string $body): self
    {
        self::requireNonEmpty($channelKey, 'channel_key');
        self::requireNonEmpty($body, 'body');
        self::requireAtMost($body, 2000, 'body');

        return new self(self::ANNOUNCEMENT_POST, [
            'action' => self::ANNOUNCEMENT_POST,
            'channel_key' => $channelKey,
            'body' => $body,
        ]);
    }

    /**
     * `event.upsert` — *needs key* on every call (§3).
     *
     * Two different guarantees are in play and both are needed. The
     * **idempotency key** makes a retry of one request safe. The **`event_key`**
     * makes a deliberate edit next week land on the same Discord event instead
     * of creating a second one — so editing an event means the same `$eventKey`
     * and a *fresh* job, because it is a new operation.
     *
     * Exactly one of `$channelKey` or `$location`: Discord takes either an event
     * inside a voice channel or an external one with a place written on it,
     * never both and never neither.
     *
     * @param  string|null  $channelKey  a voice-channel event, resolved through the bot's channel allowlist
     * @param  string|null  $location  an external event, free text
     */
    public static function eventUpsert(
        string $eventKey,
        string $name,
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
        ?string $channelKey = null,
        ?string $location = null,
        ?string $description = null,
    ): self {
        self::requireNonEmpty($eventKey, 'event_key');
        self::requireNonEmpty($name, 'name');
        self::requireAtMost($name, 100, 'name');

        if ($description !== null) {
            self::requireAtMost($description, 1000, 'description');
        }

        $hasChannel = $channelKey !== null && $channelKey !== '';
        $hasLocation = $location !== null && $location !== '';

        if ($hasChannel === $hasLocation) {
            throw new InvalidArgumentException(
                'event.upsert takes exactly one of channel_key or location, '
                .($hasChannel ? 'both were given' : 'neither was given').'.'
            );
        }

        if ($endsAt->getTimestamp() <= $startsAt->getTimestamp()) {
            throw new InvalidArgumentException('ends_at must be after starts_at.');
        }

        $payload = [
            'action' => self::EVENT_UPSERT,
            'event_key' => $eventKey,
            'name' => $name,
            'starts_at' => self::instant($startsAt),
            'ends_at' => self::instant($endsAt),
        ];

        if ($hasChannel) {
            $payload['channel_key'] = $channelKey;
        } else {
            $payload['location'] = $location;
        }

        // Omitted rather than sent as null: an absent optional field and a field
        // explicitly set to null are not the same thing to a typed validator.
        if ($description !== null) {
            $payload['description'] = $description;
        }

        return new self(self::EVENT_UPSERT, $payload);
    }

    public function needsIdempotencyKey(): bool
    {
        return in_array($this->name, self::NEEDS_IDEMPOTENCY_KEY, true);
    }

    /**
     * The bytes that will be signed and sent. Callers sign and send this exact
     * string — see {@see InternalActionSigner::encode()}.
     */
    public function rawBody(): string
    {
        return InternalActionSigner::encode($this->payload);
    }

    /**
     * ISO-8601 in UTC, milliseconds, `Z` — the shape the reference harness sends
     * (`new Date().toISOString()`), so the bot is receiving instants in the
     * format it has actually been exercised with.
     */
    private static function instant(DateTimeInterface $at): string
    {
        return (new \DateTimeImmutable('@'.$at->getTimestamp()))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    private static function requireNonEmpty(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("{$field} is required and must not be blank.");
        }
    }

    private static function requireAtMost(string $value, int $max, string $field): void
    {
        $length = mb_strlen($value);

        if ($length > $max) {
            throw new InvalidArgumentException("{$field} is at most {$max} characters, got {$length}.");
        }
    }
}
