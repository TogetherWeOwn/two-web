<?php

namespace App\Rules;

use App\Support\EventInput;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A naive local wall time: a date string with no embedded UTC offset or zone.
 *
 * `starts_at`/`ends_at` are wall times read in `timezone` (EventInput::instant
 * resolves the pair to a UTC instant). CarbonImmutable::parse honors an
 * embedded offset over the explicit $timezone argument, so an offset-bearing
 * string silently wins over the timezone field: POSTing
 * `2026-07-15T20:00:00+02:00` with `Europe/London` stored 18:00Z, while the
 * 20:00 London wall the host typed is 19:00Z (TOG-6804). Reject those strings
 * with a 422 the host can fix instead of storing the wrong instant.
 *
 * The zone test lives on EventInput::carriesZone() so the API, Filament panel
 * and domain layer guard the same strings. Anything else — including unparseable
 * strings and relative phrases like `tomorrow` — is left to the `date` rule,
 * so this rule never double-reports.
 */
class NaiveWallTime implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        if (EventInput::carriesZone($value)) {
            $fail('The :attribute field must be a local wall time in the event timezone, without a UTC offset or zone (for example, 2026-07-15 20:00).');
        }
    }
}
