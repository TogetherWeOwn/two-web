<?php

use App\Enums\RecurrenceFrequency;
use App\Support\RecurrenceSchedule;
use Carbon\CarbonImmutable;

/*
 * The date math for recurring series (TOG-8399), kept pure so it is
 * unit-testable without a database.
 *
 * The load-bearing case is the October clocks-change weekend: "20:00 London
 * every Sunday" must stay 20:00 London when BST becomes GMT, and seven days of
 * absolute UTC time would move it by an hour. Weeks step in the host's zone.
 */

function sundayEvening(): array
{
    return [
        CarbonImmutable::parse('2026-10-04 20:00', 'Europe/London')->utc(),
        CarbonImmutable::parse('2026-10-04 21:00', 'Europe/London')->utc(),
    ];
}

it('names four weekly occurrences, parent first', function () {
    [$startsAt, $endsAt] = sundayEvening();

    $occurrences = RecurrenceSchedule::occurrences($startsAt, $endsAt, 'Europe/London', RecurrenceFrequency::Weekly, 4, null);

    expect($occurrences)->toHaveCount(4)
        ->and(array_keys($occurrences))->toBe([1, 2, 3, 4]);
});

it('holds 20:00 London wall time across the clocks-change weekend', function () {
    [$startsAt, $endsAt] = sundayEvening();

    $occurrences = RecurrenceSchedule::occurrences($startsAt, $endsAt, 'Europe/London', RecurrenceFrequency::Weekly, 4, null);

    $locals = array_map(
        fn (array $pair): string => $pair[0]->setTimezone('Europe/London')->format('Y-m-d H:i'),
        array_values($occurrences),
    );

    expect($locals)->toBe([
        '2026-10-04 20:00',
        '2026-10-11 20:00',
        '2026-10-18 20:00',
        // 2026-10-25 is GMT: the UTC instant moved an hour, the wall time did not.
        '2026-10-25 20:00',
    ]);
});

it('keeps the meeting length across the clocks change too', function () {
    [$startsAt, $endsAt] = sundayEvening();

    $occurrences = RecurrenceSchedule::occurrences($startsAt, $endsAt, 'Europe/London', RecurrenceFrequency::Weekly, 4, null);

    foreach ($occurrences as [$start, $end]) {
        expect($end->getTimestamp() - $start->getTimestamp())->toBe(3600);
    }
});

it('lets the repeat-until date end the series before the count does', function () {
    [$startsAt, $endsAt] = sundayEvening();

    $occurrences = RecurrenceSchedule::occurrences(
        $startsAt, $endsAt, 'Europe/London', RecurrenceFrequency::Weekly, 52, CarbonImmutable::parse('2026-10-11'),
    );

    expect($occurrences)->toHaveCount(2);
});

it('lets the count end the series before the repeat-until date does', function () {
    [$startsAt, $endsAt] = sundayEvening();

    $occurrences = RecurrenceSchedule::occurrences(
        $startsAt, $endsAt, 'Europe/London', RecurrenceFrequency::Weekly, 2, CarbonImmutable::parse('2027-01-01'),
    );

    expect($occurrences)->toHaveCount(2);
});

it('caps a runaway rule at a year of weeklies', function () {
    [$startsAt, $endsAt] = sundayEvening();

    $occurrences = RecurrenceSchedule::occurrences($startsAt, $endsAt, 'Europe/London', RecurrenceFrequency::Weekly, null, null);

    expect($occurrences)->toHaveCount(RecurrenceSchedule::MAX_OCCURRENCES)
        ->and(RecurrenceSchedule::MAX_OCCURRENCES)->toBe(52);
});
