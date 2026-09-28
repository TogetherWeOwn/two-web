<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * What a host supplies to create or update an event, after validation and after
 * the local wall time has been resolved to an instant.
 *
 * The conversion happens here rather than in the controller because it is the one
 * place a mistake would be invisible: "2026-07-15 20:00" is a perfectly valid
 * string in either reading, and the wrong one is only wrong for half the year.
 */
final readonly class EventInput
{
    public function __construct(
        public string $title,
        public ?string $game,
        public ?string $description,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $timezone,
        public ?string $location,
        public ?int $capacity,
    ) {}

    /**
     * Resolve a local wall time in an IANA zone to the UTC instant it names.
     *
     * "20:00 Europe/London" is 19:00Z in July and 20:00Z in December. Storing the
     * reading without the zone gets one of those two wrong every year.
     *
     * The input must be naive: CarbonImmutable::parse honors an embedded offset
     * over the explicit $timezone argument, so an offset-bearing string would
     * silently resolve against the wrong zone (TOG-6804). Refuse it loudly
     * rather than store an instant nobody typed.
     *
     * The input must also have occurred: a wall time inside a spring-forward
     * gap never existed, and Carbon resolves it to the same instant as a
     * different, real wall time (TOG-6803). Refuse it loudly rather than store
     * an instant another wall time already names.
     *
     * The input must name exactly one instant: a wall time inside an
     * autumn-fallback fold occurs twice, an hour apart, and Carbon resolves it
     * to the GMT-side (second) occurrence — so 00:30Z on 2026-10-25
     * Europe/London is unreachable through a bare wall time (TOG-6806).
     * Refuse the bare form unless $occurrence disambiguates it: 'first'
     * names the clocks-back side (earlier UTC instant), 'second' the
     * post-transition side (later UTC instant). Outside a fold the parameter
     * is a no-op that still returns the instant the wall time names.
     */
    public static function instant(string $localWallTime, string $timezone, ?string $occurrence = null): CarbonImmutable
    {
        if (self::carriesZone($localWallTime)) {
            throw new \InvalidArgumentException(
                "Refusing '{$localWallTime}': it names its own zone or offset, which would silently win over '{$timezone}'. Pass a naive wall time instead.",
            );
        }

        if (self::isNonexistentWallTime($localWallTime, $timezone)) {
            throw new \InvalidArgumentException(
                "Refusing '{$localWallTime}': that wall time never occurred in '{$timezone}' — clocks skipped forward over it — so there is no instant to store. Pick a time outside the gap.",
            );
        }

        if ($occurrence !== null) {
            $normalized = strtolower(trim($occurrence));

            if (! in_array($normalized, ['first', 'second'], true)) {
                throw new \InvalidArgumentException(
                    "Refusing occurrence '{$occurrence}': expected 'first' or 'second'.",
                );
            }

            $chosen = self::foldOccurrence($localWallTime, $timezone, $normalized);

            if ($chosen === null) {
                throw new \InvalidArgumentException(
                    "Refusing '{$localWallTime}' with occurrence '{$normalized}': it does not occur twice in '{$timezone}', so there is nothing to pick between.",
                );
            }

            return $chosen;
        }

        if (self::isAmbiguousWallTime($localWallTime, $timezone)) {
            throw new \InvalidArgumentException(
                "Refusing '{$localWallTime}': that wall time occurs twice in '{$timezone}' — clocks fell back over it, so it names two instants an hour apart ".
                "(TOG-6806). Pass occurrence 'first' for the earlier one or 'second' for the later one.",
            );
        }

        return CarbonImmutable::parse($localWallTime, $timezone)->utc();
    }

    /**
     * Whether a naive wall time occurs twice in the zone because clocks fell
     * back over it (an autumn DST fold).
     *
     * Relative phrases (`tomorrow`), unparseable strings and zone-carrying
     * strings return false here and are left to the `date` rule (or to the
     * carriesZone refusal in instant()), so callers never double-report the
     * same bad input.
     */
    public static function isAmbiguousWallTime(string $value, string $timezone): bool
    {
        if (trim($value) === '' || self::carriesZone($value)) {
            return false;
        }

        $expected = self::normalizedWallTime($value);

        if ($expected === null) {
            return false;
        }

        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            return false;
        }

        // Two candidate UTC instants an hour apart, built the naive way: parse
        // the wall time as if it were UTC, then step one back. In a fold hour
        // both render back to the same wall text in the zone; outside it at
        // most one does.
        $naive = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expected, new \DateTimeZone('UTC'));

        if ($naive === false) {
            return false;
        }

        $matches = 0;

        foreach ([$naive, $naive->modify('-1 hour')] as $candidate) {
            if ($candidate->setTimezone($zone)->format('Y-m-d H:i:s') === $expected) {
                $matches++;
            }
        }

        return $matches === 2;
    }

    /**
     * The UTC instant for one named side of an autumn fold: 'first' is the
     * clocks-back side (earlier UTC instant), 'second' the post-transition
     * side (later UTC instant). Outside a fold both sides collapse to the
     * instant the wall time names. Null when the wall time has no fixed
     * calendar shape or cannot be parsed.
     */
    public static function foldOccurrence(string $localWallTime, string $timezone, string $occurrence): ?CarbonImmutable
    {
        $expected = self::normalizedWallTime($localWallTime);

        if ($expected === null) {
            return null;
        }

        try {
            $zone = new \DateTimeZone($timezone);
            $naive = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expected, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }

        if ($naive === false) {
            return null;
        }

        $matching = [];

        foreach ([$naive, $naive->modify('-1 hour')] as $candidate) {
            if ($candidate->setTimezone($zone)->format('Y-m-d H:i:s') === $expected) {
                $matching[] = CarbonImmutable::instance($candidate)->utc();
            }
        }

        if ($matching === []) {
            return null;
        }

        usort($matching, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a->timestamp <=> $b->timestamp);

        return $occurrence === 'first' ? $matching[0] : $matching[count($matching) - 1];
    }

    /**
     * Whether a date string names its own zone or offset.
     *
     * `date_parse` reports zone_type 1 for a numeric offset (+02:00), 2 for an
     * abbreviation (Z), and 3 for an identifier (Europe/London); a naive wall
     * time has no zone_type key at all. Unparseable strings return false here
     * and are left to the `date` validation rule, so callers never
     * double-report the same bad input.
     */
    public static function carriesZone(string $value): bool
    {
        $parsed = date_parse($value);

        if (($parsed['error_count'] ?? 0) > 0) {
            return false;
        }

        return isset($parsed['zone_type']) && in_array($parsed['zone_type'], [1, 2, 3], true);
    }

    /**
     * Whether a naive wall time never occurred in the zone because clocks
     * skipped forward over it (a spring-forward DST gap).
     *
     * Carbon resolves a gap time to the same instant as a different, real wall
     * time (TOG-6803: 01:30 and 02:30 Europe/London on 2026-03-29 both stored
     * 01:30Z), so two hosts typing different times get the same event and one
     * of them is wrong. The check renders the parsed instant back in the zone
     * and compares it to what was typed; a gap time never round-trips, while
     * real times — including autumn-overlap ambiguities — always do.
     *
     * Relative phrases (`tomorrow`), unparseable strings and zone-carrying
     * strings return false here and are left to the `date` rule (or to the
     * carriesZone refusal in instant()), so callers never double-report the
     * same bad input.
     */
    public static function isNonexistentWallTime(string $value, string $timezone): bool
    {
        if (trim($value) === '' || self::carriesZone($value)) {
            return false;
        }

        $expected = self::normalizedWallTime($value);

        if ($expected === null) {
            return false;
        }

        try {
            $instant = CarbonImmutable::parse($value, $timezone);
        } catch (\Throwable) {
            return false;
        }

        return $instant->setTimezone($timezone)->format('Y-m-d H:i:s') !== $expected;
    }

    /**
     * The wall time as a comparable string, or null when it has no fixed
     * calendar shape. `date_parse` resolves year/month/day/hour/minute/second
     * for absolute inputs ('2026-03-29 01:30', '2026-03-29T01:30'); relative
     * phrases like `tomorrow` and unparseable strings return false components
     * and are left to the `date` validation rule instead of being checked here.
     *
     * @return ?string 'Y-m-d H:i:s' or null
     */
    private static function normalizedWallTime(string $value): ?string
    {
        $parsed = date_parse($value);

        if (($parsed['error_count'] ?? 0) > 0) {
            return null;
        }

        foreach (['year', 'month', 'day', 'hour', 'minute'] as $part) {
            if (! is_int($parsed[$part] ?? null)) {
                return null;
            }
        }

        $second = is_int($parsed['second'] ?? null) ? (int) $parsed['second'] : 0;

        return sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            $parsed['year'],
            $parsed['month'],
            $parsed['day'],
            $parsed['hour'],
            $parsed['minute'],
            $second,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * `starts_occurrence`/`ends_occurrence` (each 'first'|'second'|absent)
     * disambiguate autumn-fold wall times (TOG-6806): 'first' names the
     * clocks-back side, 'second' the post-transition side. Absent is the
     * historical behaviour — and a 422 on a bare fold-ambiguous wall time.
     */
    public static function fromValidated(array $validated): self
    {
        $timezone = self::string($validated, 'timezone') ?? 'UTC';
        $capacity = $validated['capacity'] ?? null;

        return new self(
            title: self::string($validated, 'title') ?? '',
            game: self::string($validated, 'game'),
            description: self::string($validated, 'description'),
            startsAt: self::instant(
                self::string($validated, 'starts_at') ?? '',
                $timezone,
                self::occurrence($validated, 'starts_occurrence'),
            ),
            endsAt: self::instant(
                self::string($validated, 'ends_at') ?? '',
                $timezone,
                self::occurrence($validated, 'ends_occurrence'),
            ),
            timezone: $timezone,
            location: self::string($validated, 'location'),
            capacity: is_numeric($capacity) ? (int) $capacity : null,
        );
    }

    /** @param  array<string, mixed>  $data */
    private static function occurrence(array $data, string $key): ?string
    {
        $value = self::string($data, $key);

        if ($value === null || trim($value) === '') {
            return null;
        }

        return strtolower(trim($value));
    }

    /** @param  array<string, mixed>  $data */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
