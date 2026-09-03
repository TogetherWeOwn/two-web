<?php

namespace App\Support\Counts;

use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the counts the landing page publishes, from the bot's `web_v1` views.
 *
 * ## Why this class cannot throw
 *
 * Every public method here answers with a value, never an exception. That is
 * not defensive habit — it is the requirement. The landing page is the top of
 * the join funnel, and the one thing worse than a page with no member count is
 * no page at all. The bot's database being unreachable is a fault on our side
 * that a visitor should never be told about (two-bot
 * `docs/WEBSITE_CONTRACT.md` §5), so a failed read degrades to the designed
 * empty state and gets logged for us instead.
 *
 * This is also the expected path today, not an edge case. `live_counts`
 * returned nulls and `rank_counts` returned five null rows until the TOG-73
 * collector landed, and a fresh database still answers that way.
 *
 * ## Why the queries are `select`, not Eloquent
 *
 * `web_v1` is a contract of views, not our schema. The `two_web_ro` role has
 * SELECT on those views and nothing else, so there is no model to bind and no
 * table to migrate. Reaching past the views into the bot's tables is forbidden
 * by the contract and refused by the grants; this class only ever names a view.
 *
 * ## Caching
 *
 * 60 seconds, which is what the contract asks for and what the collector
 * refreshes at — anything shorter is load with no fresher answer. The cache is
 * the reason a slow bot database cannot make this page slow twice.
 */
final readonly class CountsReader implements CountsSource
{
    /** Matches the collector's own refresh interval. */
    private const CACHE_SECONDS = 60;

    private const MEMBER_COUNT_KEY = 'counts.live';

    private const RANKS_KEY = 'counts.ranks';

    public function __construct(
        private DatabaseManager $db,
        private CacheRepository $cache,
    ) {}

    /**
     * The member count for the hero, or the unavailable state.
     */
    public function liveCounts(): LiveCounts
    {
        return $this->remember(self::MEMBER_COUNT_KEY, function (): LiveCounts {
            $row = $this->db->connection('bot')
                ->selectOne('select human_member_count, online_count, counts_updated_at from web_v1.live_counts');

            if ($row === null) {
                // The view promises exactly one row even on an empty database,
                // so this means something other than "no data" — worth a log
                // line, and still not worth a broken page.
                return LiveCounts::unavailable();
            }

            return LiveCounts::fromRow(
                memberCount: $this->intOrNull($row->human_member_count ?? null),
                onlineCount: $this->intOrNull($row->online_count ?? null),
                countsUpdatedAt: $this->timestampOrNull($row->counts_updated_at ?? null),
            );
        }, LiveCounts::unavailable());
    }

    /**
     * The Prospect → Legend ladder, in ladder order.
     *
     * An empty array means we could not read the ranks at all; the section is
     * omitted rather than rendered as an empty ladder. Rows with a null
     * `member_count` are kept — the rung still belongs on the ladder, it just
     * has no number beside it yet.
     *
     * @return list<Rank>
     */
    public function ranks(): array
    {
        return $this->remember(self::RANKS_KEY, function (): array {
            $rows = $this->db->connection('bot')
                ->select('select rank_key, rank_label, member_count from web_v1.rank_counts order by rank_order');

            // array_values because the ladder is a list, in ladder order, and
            // `select()` makes no promise about the keys it hands back.
            return array_values(array_map(fn (object $row): Rank => new Rank(
                key: (string) ($row->rank_key ?? ''),
                label: (string) ($row->rank_label ?? ''),
                memberCount: $this->intOrNull($row->member_count ?? null),
            ), $rows));
        }, []);
    }

    /**
     * Run a read behind the cache, falling back to `$onFailure` if anything at
     * all goes wrong.
     *
     * `Throwable`, not a narrower type, and deliberately: an unconfigured
     * connection, a refused socket, a missing schema and a permission denial
     * are four different exception types that all mean the same thing to a
     * visitor. Catching them individually would leave the fifth one — whichever
     * it turns out to be — as a white screen on the page that matters most.
     *
     * @template T
     *
     * @param  Closure():T  $read
     * @param  T  $onFailure
     * @return T
     */
    private function remember(string $key, Closure $read, mixed $onFailure): mixed
    {
        try {
            return $this->cache->remember($key, self::CACHE_SECONDS, $read);
        } catch (Throwable $e) {
            // Never the exception message: a PDO failure can carry the DSN, and
            // a Laravel QueryException substitutes real bindings into the SQL
            // it prints. The class name says which kind of failure it was and
            // carries nothing a log reader should not see.
            Log::warning('Counts unavailable; rendering the degraded landing page.', [
                'key' => $key,
                'exception' => $e::class,
            ]);

            return $onFailure;
        }
    }

    /**
     * Counts arrive from PDO as strings on some drivers and ints on others, and
     * null means "we do not know" on every one of them. Casting through this
     * keeps that distinction: `null` stays null instead of becoming `0`.
     */
    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * The contract returns every timestamp as ISO-8601 UTC text, so this parses
     * rather than trusting the driver to have made a date of it.
     *
     * A value we cannot parse is treated as absent: a count we cannot age is
     * one we must not publish as fresh.
     */
    private function timestampOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
