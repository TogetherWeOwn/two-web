<?php

namespace App\Rules;

use App\Support\EventInput;
use Closure;
use DateTimeZone;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * A wall time that actually occurred in the event's time zone.
 *
 * `starts_at`/`ends_at` are wall times read in `timezone` (EventInput::instant
 * resolves the pair to a UTC instant). A wall time inside a spring-forward DST
 * gap never occurred, and Carbon resolves it to the same instant as a
 * different, real wall time: 01:30 and 02:30 Europe/London on 2026-03-29 both
 * stored 01:30Z (TOG-6803). Two hosts typing different times get the same
 * event and one of them is wrong, with no error — so a gap time is a 422 the
 * host can fix, never a silently stored wrong instant.
 *
 * The zone comes from the `timezone` field under validation, so this rule is
 * data-aware. A missing or non-IANA timezone is left to the IanaTimeZone rule,
 * and anything else uncheckable (blank, unparseable, relative like `tomorrow`,
 * offset-bearing) is left to the `date` rule or the NaiveWallTime rule, so
 * this rule never double-reports.
 *
 * The gap test lives on EventInput::isNonexistentWallTime() so the API,
 * Filament panel and domain layer guard the same strings. The timezone is a
 * sibling of the date field, including inside Filament's nested `data` state.
 */
class RealWallTime implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    protected array $data = [];

    /** @param array<string, mixed> $data */
    public function setData($data)
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $timezoneKey = str_contains($attribute, '.')
            ? Str::beforeLast($attribute, '.').'.timezone'
            : 'timezone';
        $timezone = Arr::get($this->data, $timezoneKey);

        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return;
        }

        if (EventInput::isNonexistentWallTime($value, $timezone)) {
            $fail("The :attribute {$value} never occurred in {$timezone}: clocks skipped forward over it in the spring daylight-saving change. Pick a time outside the gap.");
        }
    }
}
