<?php

namespace App\Support;

use App\Enums\RecurrenceFrequency;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The recurrence rule a moderator sets when creating a series, parsed from the
 * create form's state.
 *
 * Lives apart from EventInput on purpose: the agent ingress shares
 * EventInput's rules (StoreEventRequest::fieldRules), and a machine caller has
 * no business minting series. The create page calls fromFormData(); anything
 * else passes null and gets a one-off event.
 */
final readonly class RecurrenceInput
{
    public function __construct(
        public RecurrenceFrequency $frequency,
        public ?int $count,
        public ?CarbonImmutable $endsOn,
    ) {}

    /**
     * Read the rule out of the create form's data, or null for a one-off.
     *
     * Unknown frequencies, out-of-range counts, unparsable dates and a
     * repeat-until that ends before the first meeting are all field errors on
     * the form — never a stored half-rule.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromFormData(array $data): ?self
    {
        $frequency = $data['recurrence_frequency'] ?? null;

        if ($frequency === null || $frequency === '') {
            return null;
        }

        $enum = is_string($frequency) ? RecurrenceFrequency::tryFrom($frequency) : null;

        if ($enum === null) {
            throw ValidationException::withMessages([
                'recurrence_frequency' => 'Unknown repeat frequency.',
            ]);
        }

        $count = self::count($data['recurrence_count'] ?? null);
        $endsOn = self::endsOn($data['recurrence_ends_on'] ?? null);

        if ($count === null && $endsOn === null) {
            throw ValidationException::withMessages([
                'recurrence_count' => 'Give a number of occurrences or a repeat-until date.',
            ]);
        }

        $startsDate = self::startsDate($data);

        if ($startsDate !== null && $endsOn !== null && $endsOn->format('Y-m-d') < $startsDate) {
            throw ValidationException::withMessages([
                'recurrence_ends_on' => 'The repeat-until date is before the first meeting.',
            ]);
        }

        return new self($enum, $count, $endsOn);
    }

    /** @param  mixed  $value */
    private static function count($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value < 1 || (int) $value > RecurrenceSchedule::MAX_OCCURRENCES) {
            throw ValidationException::withMessages([
                'recurrence_count' => 'Occurrences must be between 1 and '.RecurrenceSchedule::MAX_OCCURRENCES.'.',
            ]);
        }

        return (int) $value;
    }

    /** @param  mixed  $value */
    private static function endsOn($value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages([
                'recurrence_ends_on' => 'The repeat-until date is not a date.',
            ]);
        }

        try {
            $date = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'recurrence_ends_on' => 'The repeat-until date is not a date.',
            ]);
        }

        return $date;
    }

    /**
     * The first meeting's host-local date, for the ends-before-start check.
     *
     * Null when the starts fields are missing or unparsable — the event rules
     * own those errors, and one bad form must not report the same field twice.
     *
     * @param  array<string, mixed>  $data
     */
    private static function startsDate(array $data): ?string
    {
        $startsAt = $data['starts_at'] ?? null;
        $timezone = $data['timezone'] ?? null;

        if (! is_string($startsAt) || ! is_string($timezone) || $startsAt === '' || $timezone === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($startsAt, $timezone)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
