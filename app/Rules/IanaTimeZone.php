<?php

namespace App\Rules;

use Closure;
use DateTimeZone;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An IANA identifier, checked against the list PHP actually has.
 *
 * Abbreviations are the trap this exists to catch. "BST" and "EST" look like time
 * zones and are not: they name one side of a DST boundary, so an event stored
 * against one is wrong for half the year and right for the other half.
 */
class IanaTimeZone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! in_array($value, DateTimeZone::listIdentifiers(), true)) {
            $fail('The :attribute field must be an IANA time zone identifier, such as Europe/London.');
        }
    }
}
