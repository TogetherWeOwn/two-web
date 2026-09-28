<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects control characters that corrupt or poison stored text (TOG-6964).
 *
 * Postgres `text` silently truncates at NUL bytes (data corruption on `bio`)
 * and rejects them outright in other writes (`SQLSTATE[22P05]` 500s on
 * `games`), while C0 controls and DEL pass Blade escaping through raw into
 * member pages. Tab, LF and CR stay allowed so multiline bios keep working;
 * everything else in Unicode category Cc — plus malformed UTF-8, which
 * Postgres would refuse at write time — fails validation instead.
 */
class NoControlCharacters implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (self::containsControlCharacters($value)) {
            $fail('The :attribute field must not contain control characters.');
        }
    }

    public static function containsControlCharacters(string $value): bool
    {
        $stripped = str_replace(["\t", "\n", "\r"], '', $value);

        // preg_match returns false on malformed UTF-8; fail closed so a bad
        // payload gets a 422 instead of a Postgres write error.
        return preg_match('/\p{Cc}/u', $stripped) !== 0;
    }
}
