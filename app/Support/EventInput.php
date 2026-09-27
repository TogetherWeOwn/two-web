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
     */
    public static function instant(string $localWallTime, string $timezone): CarbonImmutable
    {
        if (self::carriesZone($localWallTime)) {
            throw new \InvalidArgumentException(
                "Refusing '{$localWallTime}': it names its own zone or offset, which would silently win over '{$timezone}'. Pass a naive wall time instead.",
            );
        }

        return CarbonImmutable::parse($localWallTime, $timezone)->utc();
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

    /** @param  array<string, mixed>  $validated */
    public static function fromValidated(array $validated): self
    {
        $timezone = self::string($validated, 'timezone') ?? 'UTC';
        $capacity = $validated['capacity'] ?? null;

        return new self(
            title: self::string($validated, 'title') ?? '',
            game: self::string($validated, 'game'),
            description: self::string($validated, 'description'),
            startsAt: self::instant(self::string($validated, 'starts_at') ?? '', $timezone),
            endsAt: self::instant(self::string($validated, 'ends_at') ?? '', $timezone),
            timezone: $timezone,
            location: self::string($validated, 'location'),
            capacity: is_numeric($capacity) ? (int) $capacity : null,
        );
    }

    /** @param  array<string, mixed>  $data */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
