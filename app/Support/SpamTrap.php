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
     * Floor between the form opening and an accepted save, in seconds. Three
     * is the fastest a human can open the edit form, focus a field, type
     * anything and hit save; the Dusk journey takes an order of magnitude
     * longer. A patient bot still passes — this is one cheap layer, not a
     * wall — but instant scripted submits do not.
     */
    public const MIN_FILL_SECONDS = 3;

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
     * plausibly manages. Fail-closed: a form-open stamp from the future
     * (clock weirdness, forged payload on an unlocked field) reads as instant.
     */
    public static function tooFast(int $formOpenedAt): bool
    {
        // getTimestamp() is typed int upstream (InternalActionClient ships the
        // same call) — the magic ->timestamp accessor is not, which is what
        // phpstan flags.
        return now()->getTimestamp() - $formOpenedAt < self::MIN_FILL_SECONDS;
    }
}
