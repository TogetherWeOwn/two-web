<?php

namespace App\Support\Counts;

/**
 * One rung of the Prospect → Legend ladder.
 *
 * The ladder is the strongest thing on the landing page: it answers "what does
 * joining actually mean here?" with something a generic Discord cannot say. So
 * the rungs are always all five, in `rank_order`, even on an empty database —
 * a rank that vanished because nobody holds it would make the ladder look
 * shorter than it is (two-bot `docs/WEBSITE_CONTRACT.md` §2).
 *
 * `memberCount` is the *highest rank held*, not the number of holders. In the
 * TWO server the ranks stack — a Legend still holds Soldier, Member and
 * Prospect — so `holders_count` sums to 122 against 84 humans. Publish that as
 * a ladder and the column visibly does not add up. This is the one number that
 * belongs on the page, and it is nullable for the same reason every count is.
 */
final readonly class Rank
{
    public function __construct(
        public string $key,
        public string $label,
        public ?int $memberCount,
    ) {}

    /**
     * Whether this rung has a headcount to print.
     *
     * A real `0` is expected here and is not the same as unknown: nobody holds
     * Legend yet (WEBSITE_CONTRACT §3). So `0` prints, and null does not.
     */
    public function hasCount(): bool
    {
        return $this->memberCount !== null;
    }

    /**
     * Whether this rung is genuinely empty — a read `0`, not an unknown.
     *
     * The page renders a word here rather than the numeral. `0` is true and
     * printing it would be honest, but two-design `docs/CONTENT.md` §4 bans a
     * counter that renders `0` without a designed sentence explaining it, and a
     * column of numbers ending in a bare `0` reads as a dead ladder rather than
     * as a rung nobody has reached yet. Distinct from `hasCount()`, which is
     * about whether we *know* the number.
     */
    public function isUnclaimed(): bool
    {
        return $this->memberCount === 0;
    }
}
