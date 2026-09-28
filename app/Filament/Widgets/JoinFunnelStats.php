<?php

namespace App\Filament\Widgets;

use App\Enums\JoinOutcome;
use App\Models\JoinAttempt;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * Join funnel outcomes on the admin dashboard (TOG-5617).
 *
 * Counts per JoinOutcome over the retention window (90 days by default, see
 * config/join.php) so funnel breakage — a Discord outage, the bot down, a
 * consent-Cancel wave — is visible without reading logs.
 * Moderator-only through the existing panel gate: the widget never renders
 * for anyone who cannot open /admin, and the counts contain no member data
 * (outcomes only), so there is nothing here to narrow per viewer.
 *
 * Counts are cached for 60 seconds: the dashboard is read rarely and the
 * table grows on every join attempt.
 */
class JoinFunnelStats extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $heading = 'Join funnel';

    protected ?string $description = 'Terminal outcome of every one-click join attempt.';

    protected ?string $pollingInterval = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $counts = Cache::remember('join-funnel-stats', 60, function (): array {
            $rows = JoinAttempt::query()
                ->selectRaw('outcome, count(*) as total')
                ->groupBy('outcome')
                ->pluck('total', 'outcome')
                ->all();

            $counts = [];
            foreach (JoinOutcome::cases() as $case) {
                $counts[$case->value] = (int) ($rows[$case->value] ?? 0);
            }

            return $counts;
        });

        return [
            Stat::make('Added', number_format($counts[JoinOutcome::Added->value]))
                ->description('Members added to the server'),
            Stat::make('Already member', number_format($counts[JoinOutcome::AlreadyMember->value]))
                ->description('Re-joins by existing members'),
            Stat::make('Denied', number_format($counts[JoinOutcome::Denied->value]))
                ->description('Cancelled at Discord consent'),
            Stat::make('Errors + degraded', number_format(
                $counts[JoinOutcome::Error->value] + $counts[JoinOutcome::Degraded->value]
            ))->description('Discord/bot failures needing attention'),
        ];
    }
}
