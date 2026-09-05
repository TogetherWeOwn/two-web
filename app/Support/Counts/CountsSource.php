<?php

namespace App\Support\Counts;

/**
 * Where the landing page gets its numbers.
 *
 * The one implementation that ships is {@see CountsReader}, which reads the
 * bot's `web_v1` views. The interface exists because "the counts" and "the
 * bot's database" are genuinely different concerns: the page's contract is
 * that it is handed a member count that may be absent and a ladder that may be
 * empty, and it renders correctly either way. How those were obtained — a
 * live view, a 60-second cache, or a connection that refused — is settled
 * before the page sees them.
 *
 * That seam is also what makes the degraded state testable without a bot
 * database to break on purpose.
 */
interface CountsSource
{
    /** The member count for the hero, or the unavailable state. Never throws. */
    public function liveCounts(): LiveCounts;

    /**
     * The Prospect → Legend ladder in ladder order, or an empty array if the
     * ranks could not be read. Never throws.
     *
     * @return list<Rank>
     */
    public function ranks(): array;
}
