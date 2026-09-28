<?php

namespace App\Rules;

use App\Support\EventInput;
use Closure;
use DateTimeZone;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/**
 * A fold-ambiguous wall time must name which occurrence it means.
 *
 * `starts_at`/`ends_at` are wall times read in `timezone` (EventInput::instant
 * resolves the pair to a UTC instant). A wall time inside an autumn-fallback
 * DST fold occurs twice, an hour apart, and Carbon resolves it to the
 * GMT-side (second) occurrence — so the BST-side first occurrence is
 * unreachable through a bare wall time: two hosts typing the same time get
 * two different events, or one host cannot create the one they mean
 * (TOG-6806). A bare fold-ambiguous wall time is a 422 naming the fold and
 * the `*_occurrence` field that disambiguates it, never a silently stored
 * maybe-wrong instant.
 *
 * The zone comes from the `timezone` field under validation, so this rule is
 * data-aware. Sibling lookup is keyed off the attribute under validation —
 * the Filament panel validates nested (`data.starts_at`) while HTTP requests
 * validate flat — so the rule fires on both paths (the first version read
 * only flat keys and silently passed in the panel). A missing or non-IANA
 * timezone is left to the IanaTimeZone rule, and anything else uncheckable
 * (blank, unparseable, relative like `tomorrow`, offset-bearing) is left to
 * the `date` rule or the NaiveWallTime rule, so this rule never
 * double-reports.
 *
 * Each `*_occurrence` field is validated by the plain `in:first,second`
 * rule on the request, not here: this rule only demands its presence when
 * the wall time is ambiguous. An occurrence on an unambiguous wall time is
 * accepted and ignored by EventInput (it collapses to the one instant), so
 * a client that always sends it is not punished.
 *
 * The fold test lives on EventInput::isAmbiguousWallTime() so the domain
 * layer guards the same strings this rule guards (the Filament panel calls
 * fromValidated() directly and never sees this rule).
 */
class FoldDisambiguation implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    protected array $data = [];

    public function __construct(private readonly string $occurrenceField) {}

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

        // The panel validates under a nested key (`data.starts_at`), so the
        // sibling fields live under the same prefix — while HTTP requests
        // validate flat. Read both, keyed off the attribute under validation.
        $prefix = str_contains($attribute, '.')
            ? substr($attribute, 0, strrpos($attribute, '.')).'.'
            : '';

        $timezone = Arr::get($this->data, $prefix.'timezone');

        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            return;
        }

        $occurrence = Arr::get($this->data, $prefix.$this->occurrenceField);

        if (is_string($occurrence) && in_array(strtolower(trim($occurrence)), ['first', 'second'], true)) {
            return;
        }

        if (EventInput::isAmbiguousWallTime($value, $timezone)) {
            $fail("The {$value} occurs twice in {$timezone}: clocks fell back over it, so it names two times an hour apart (TOG-6806). Send {$this->occurrenceField} as 'first' for the earlier occurrence or 'second' for the later one.");
        }
    }
}
