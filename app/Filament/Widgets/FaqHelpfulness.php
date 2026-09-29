<?php

namespace App\Filament\Widgets;

use App\Models\FaqVote;
use App\Support\FaqEntry;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

/**
 * Was-this-helpful scores on the admin dashboard (TOG-8863).
 *
 * One row per FAQ entry with a vote on it, ranked worst-first so the answers
 * that need rewriting surface at the top. An entry is flagged when its
 * helpfulness rate is under 50% with at least 5 votes, or when it sits in the
 * bottom 3 — either way editors can name what to rewrite without reading
 * anything member-shaped.
 *
 * Moderator-only through the existing panel gate, and the rows are aggregates
 * only (entry slug, counts, rate): no member data, nothing to narrow per
 * viewer. The FaqVote query selects no voter half, so a voter id can never
 * leak through here — the same Aggregates-only rule as JoinFunnelStats.
 *
 * Counts are cached for 60 seconds, same as JoinFunnelStats: the dashboard is
 * read rarely and the table grows on every vote.
 */
class FaqHelpfulness extends Widget
{
    protected string $view = 'filament.widgets.faq-helpfulness';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /** Entries with at least this many votes can be flagged low-scoring. */
    public const FLAG_MIN_VOTES = 5;

    /**
     * Ranked worst-first: every entry with a vote on it, rate ascending, then
     * most-voted first so a thin 0% does not outrank a solid 20% with 40 votes.
     *
     * @return list<array{entry: string, question: string, helpful: int, total: int, rate: float, flagged: bool}>
     */
    public static function scores(): array
    {
        /** @var array<string, array{helpful: int, total: int}> $counts */
        $counts = Cache::remember('faq-helpfulness', 60, function (): array {
            // toBase, not the model: FaqVote casts `helpful` to boolean, and
            // the aggregate alias below is also called `helpful` — hydrating
            // through the model would boolean-cast the COUNT (every entry
            // reads helpful=1). Plain stdClass rows keep the counts numeric.
            $rows = FaqVote::query()->toBase()
                ->selectRaw('entry, count(*) filter (where helpful) as helpful, count(*) as total')
                ->groupBy('entry')
                ->get();

            $counts = [];
            foreach ($rows as $row) {
                $counts[(string) $row->entry] = [
                    'helpful' => (int) $row->helpful,
                    'total' => (int) $row->total,
                ];
            }

            return $counts;
        });

        $scores = [];
        foreach (FaqEntry::cases() as $case) {
            if (! isset($counts[$case->value])) {
                continue;
            }

            $scores[] = [
                'entry' => $case->value,
                'question' => $case->question(),
                'helpful' => $counts[$case->value]['helpful'],
                'total' => $counts[$case->value]['total'],
                'rate' => $counts[$case->value]['total'] > 0
                    ? $counts[$case->value]['helpful'] / $counts[$case->value]['total']
                    : 0.0,
                'flagged' => false,
            ];
        }

        usort($scores, fn ($a, $b) => [$a['rate'], $b['total']] <=> [$b['rate'], $a['total']]);

        $flagged = 0;
        foreach ($scores as $i => $score) {
            if ($score['total'] >= self::FLAG_MIN_VOTES
                && ($score['rate'] < 0.5 || $i < 3)) {
                $scores[$i]['flagged'] = true;
                $flagged++;
            }
        }

        // "or bottom-3": with fewer than 3 flaggable rows the worst of what is
        // there still deserves attention, and with none the dashboard should
        // say "look here" rather than nothing.
        if ($flagged === 0 && $scores !== []) {
            $scores[0]['flagged'] = true;
        }

        return $scores;
    }

    /** @return array{scores: list<array{entry: string, question: string, helpful: int, total: int, rate: float, flagged: bool}>} */
    protected function getViewData(): array
    {
        return ['scores' => self::scores()];
    }
}
