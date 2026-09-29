<?php

namespace App\Support;

/**
 * The honeypot + minimum-fill-time trap on member write forms (TOG-8715).
 *
 * One cheap, dependency-free layer against form-filling bots: a decoy field no
 * real form renders visibly (bots fill every input; humans never see it), plus
 * a floor on how fast a human can plausibly open a form and save it. Either
 * signal fires and the write is swallowed — the caller gets the exact success
 * shape a real write would have produced, with no row, no error and no log
 * line. Any difference (a 422, a missing confirmation, a 429) would be an
 * oracle a bot operator could probe, so the swallow must be byte-identical to
 * success. Nothing attacker-shaped is ever logged: the honeypot content is
 * untrusted input and does not belong in the log trail.
 *
 * Scope is deliberately narrow: the member-facing write forms (profile edit,
 * RSVP). Moderator event writes stay out — their authors are authenticated
 * staff, not the public — and the join flow has no text form to trap (one-click
 * OAuth plus a static invite link). The RSVP Livewire button carries no trap
 * either: it is a single click with no inputs, and humans legitimately click
 * it within seconds of page load, so a time floor there would punish members.
 */
final class SpamTrap
{
    /**
     * The decoy field. A real profile has no website — bio, games, timezone is
     * the whole member-owned surface — and the RSVP body is status/user_id, so
     * this name collides with nothing legitimate on either writer.
     */
    public const HONEY_FIELD = 'website';

    /**
     * Floor between the form opening and an accepted save, in milliseconds.
     * One second: genuine members take seconds to fill three fields
     * (TOG-9361), while sub-second scripted submits still trip it. A patient
     * bot still passes — this is one cheap layer, not a wall.
     *
     * Millisecond precision is load-bearing, not cosmetic: with
     * second-resolution stamps a genuine ~0.5s browser fill is a wall-clock
     * lottery (same wall second reads as 0 elapsed and swallows the save —
     * PR #497 Dusk evidence), while millisecond stamps measure the real gap.
     */
    public const MIN_FILL_MS = 1000;

    /** A non-blank decoy value means a bot filled it. Whitespace is blank. */
    public static function honeypotFilled(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        if (! is_string($value)) {
            return $value !== null;
        }

        return trim($value) !== '';
    }

    /**
     * True when the save lands sooner after the form opened than a human
     * plausibly manages. Millisecond stamps (see MIN_FILL_MS): fail-closed on
     * a zero stamp (a save without opening the form, reachable only by
     * forging the request) and on a stamp from the future (clock weirdness,
     * forged payload on an unlocked field) — both read as instant.
     */
    public static function tooFast(int $formOpenedAtMs): bool
    {
        if ($formOpenedAtMs <= 0) {
            return true;
        }

        // getTimestampMs() is typed int upstream (InternalActionClient ships
        // the same call) — the magic ->timestamp accessor is not, which is
        // what phpstan flags.
        return now()->getTimestampMs() - $formOpenedAtMs < self::MIN_FILL_MS;
    }
}
