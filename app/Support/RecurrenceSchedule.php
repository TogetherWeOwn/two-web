<?php

namespace App\Support;

use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;

/**
 * The date math for recurring series (TOG-8399), kept pure so it is
 * unit-testable without a database.
 *
 * A series is ordinary event rows: the parent holds the rule, every occurrence
 * (parent included, index 1) is a row. This class answers "which indexes exist
 * and when are they"; the reconcile command tops up whatever is missing. An
 * index that already exists is never touched, so a moderator can cancel a
 * single instance to skip a week without the next pass resurrecting it.
 *
 * Weeks step in the host's zone, not in UTC: "20:00 London every Sunday" must
 * stay 20:00 London across the clocks-change weekend, and seven days of
 * absolute time would move it by an hour twice a year.
 */
final class RecurrenceSchedule
{
    /**
     * A series that never ends is a runaway reconcile pass. 52 weeklies is a
     * full year of Sunday Squads — anything longer is a new decision, not a
     * longer form field.
     */
    public const MAX_OCCURRENCES = 52;

    /**
     * Every occurrence the rule names, parent first.
     *
     * Both bounds apply and the tighter wins: `count` caps the total including
     * the parent, `endsOn` keeps occurrences whose host-local date is on or
     * before it. `endsOn` is a calendar date, so it is compared as Y-m-d
     * without a zone shift — parsing it at midnight UTC and converting to the
     * host zone first would move it back a day for zones ahead of UTC.
     *
     * @return array<int, array{CarbonImmutable, CarbonImmutable}> 1-based index to [startsAtUtc, endsAtUtc]
     */
    public static function occurrences(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        string $timezone,
        RecurrenceFrequency $frequency,
        ?int $count = null,
        ?CarbonImmutable $endsOn = null,
        int $max = self::MAX_OCCURRENCES,
    ): array {
        $limit = min(max($count ?? $max, 1), $max);

        $localStart = $startsAt->setTimezone($timezone);
        $localEnd = $endsAt->setTimezone($timezone);
        $endDate = $endsOn?->format('Y-m-d');

        $occurrences = [];

        for ($index = 1; $index <= $limit; $index++) {
            $step = $index - 1;
            $start = match ($frequency) {
                RecurrenceFrequency::Weekly => $localStart->addWeeks($step),
            };
            $end = match ($frequency) {
                RecurrenceFrequency::Weekly => $localEnd->addWeeks($step),
            };

            if ($endDate !== null && $start->format('Y-m-d') > $endDate) {
                break;
            }

            $occurrences[$index] = [$start->utc(), $end->utc()];
        }

        return $occurrences;
    }
}
