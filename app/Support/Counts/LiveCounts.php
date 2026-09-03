<?php

namespace App\Support\Counts;

use Illuminate\Support\Carbon;

/**
 * The member count as the landing page is allowed to publish it.
 *
 * One rule holds this whole class together, and it comes from the designer
 * (two-design `docs/CONTENT.md` §4) and from the data contract (two-bot
 * `docs/WEBSITE_CONTRACT.md` §3) independently:
 *
 * > **A zero is never a stand-in for "we do not know".**
 *
 * The bot guarantees the input half of that: a count column is null when the
 * collector could not read it, and `0` would mean the server genuinely emptied
 * out. This class guarantees the output half — `hasMemberCount()` is false when
 * the value is null, and the view omits the number rather than rendering
 * anything in its place. "A zero here is the single most damaging thing this
 * page can display."
 *
 * `online_count` is deliberately not a headline. It is null in v1 (the bot does
 * not request the presence intent — WEBSITE_CONTRACT §6.1) and even once it is
 * not, at 04:00 it is a small number with no story attached. It appears only as
 * a presence dot beside the member count, and only when it is above zero.
 */
final readonly class LiveCounts
{
    /** Older than this and the number gets an "as of HH:MM" beside it. */
    private const STALE_AFTER_MINUTES = 10;

    public function __construct(
        public ?int $memberCount,
        public ?int $onlineCount,
        public ?Carbon $countsUpdatedAt,
        public CountsFreshness $freshness,
    ) {}

    /**
     * The state the page renders when we could not read the bot at all.
     *
     * A missing database, a refused connection and a view returning nulls all
     * land here on purpose: from the reader's side they are the same event, and
     * a visitor should never see an error about our infrastructure.
     */
    public static function unavailable(?Carbon $countsUpdatedAt = null): self
    {
        return new self(null, null, $countsUpdatedAt, CountsFreshness::Unavailable);
    }

    /**
     * Build from one row of `web_v1.live_counts`, deciding freshness from the
     * timestamp the view returns.
     *
     * The timestamp is returned by the view even when the value beside it has
     * aged out, so a degraded render can still say when we last knew. That is
     * why `$countsUpdatedAt` is kept here even in the unavailable case.
     */
    public static function fromRow(?int $memberCount, ?int $onlineCount, ?Carbon $countsUpdatedAt, ?Carbon $now = null): self
    {
        if ($memberCount === null) {
            return self::unavailable($countsUpdatedAt);
        }

        // A count with no timestamp cannot be aged, and an unageable number is
        // published as fresh forever. The bot's schema refuses to store one
        // (`guild_counters_members_dated`); if one reaches us anyway, we treat
        // it as unknown rather than trusting it.
        if ($countsUpdatedAt === null) {
            return self::unavailable();
        }

        $now ??= Carbon::now();

        $freshness = $countsUpdatedAt->diffInMinutes($now, absolute: true) < self::STALE_AFTER_MINUTES
            ? CountsFreshness::Fresh
            : CountsFreshness::Stale;

        return new self($memberCount, $onlineCount, $countsUpdatedAt, $freshness);
    }

    /** Whether there is a member count to print. False means omit it entirely. */
    public function hasMemberCount(): bool
    {
        return $this->memberCount !== null;
    }

    /**
     * Whether to print "as of HH:MM" under the number.
     *
     * Only in the stale state: a timestamp on a number read forty seconds ago
     * is noise that makes the fresh case look doubtful.
     */
    public function isStale(): bool
    {
        return $this->freshness === CountsFreshness::Stale && $this->countsUpdatedAt !== null;
    }

    /**
     * Whether to show the online dot.
     *
     * Zero online is true but it is also the one reading that says "dead" on a
     * page whose job is to say "small". We publish presence when there is
     * presence and stay quiet otherwise — the member count carries the section
     * on its own.
     */
    public function hasOnlineCount(): bool
    {
        return $this->onlineCount !== null && $this->onlineCount > 0;
    }
}
