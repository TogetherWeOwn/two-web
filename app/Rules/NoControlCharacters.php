<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects control and invisible format characters that corrupt or poison
 * stored text (TOG-6964, TOG-9858).
 *
 * Postgres `text` silently truncates at NUL bytes (data corruption on `bio`)
 * and rejects them outright in other writes (`SQLSTATE[22P05]` 500s on
 * `games`), while C0 controls and DEL pass Blade escaping through raw into
 * member pages. Tab, LF and CR stay allowed so multiline bios keep working;
 * everything else in Unicode category Cc — plus malformed UTF-8, which
 * Postgres would refuse at write time — fails validation instead.
 *
 * TOG-9858: category Cf (format) also needs a targeted block. Bidi overrides
 * (U+202A–U+202E, U+2066–U+2069) enable display-order spoofing and zero-width
 * space/non-joiner plus BOM (U+200B, U+200C, U+FEFF) enable visually-identical
 * but distinct strings that defeat the games dedup display; U+200D outside
 * genuine emoji ZWJ sequences fails too. The block is a targeted subset —
 * not all of Cf — so legitimate marks (Arabic letter mark, variation
 * selectors which are Mn, accents, CJK) keep working; emoji/accents/CJK
 * probes pass.
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
        if (preg_match('/\p{Cc}/u', $stripped) !== 0) {
            return true;
        }

        // TOG-9858: targeted Cf block. Bidi embeddings/overrides/isolates
        // (U+202A–U+202E, U+2066–U+2069) spoof display order; zero-width
        // space/non-joiner/joiner and BOM (U+200B–U+200D, U+FEFF) make
        // visually-identical but distinct strings that defeat games dedup.
        // U+200D gets a carve-out for genuine emoji ZWJ sequences (family,
        // profession and heart-on-fire emoji join Extended_Pictographic
        // codepoints with ZWJ); every other ZWJ — in words, at string
        // edges, or gluing emoji to plain text — still fails.
        $emojiJoinerSequence = '/(?:\p{Extended_Pictographic}[\x{FE00}-\x{FE0F}\p{Mn}\p{Me}\p{Sk}\x{E0020}-\x{E007F}]*\x{200D})+\p{Extended_Pictographic}[\x{FE00}-\x{FE0F}\p{Mn}\p{Me}\p{Sk}\x{E0020}-\x{E007F}]*/u';
        $withoutEmojiJoiners = preg_replace($emojiJoinerSequence, '', $stripped);

        if (! is_string($withoutEmojiJoiners)) {
            return true;
        }

        return preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}-\x{200D}\x{FEFF}]/u', $withoutEmojiJoiners) !== 0;
    }
}
