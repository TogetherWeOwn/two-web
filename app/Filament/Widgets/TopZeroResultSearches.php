<?php

namespace App\Filament\Widgets;

use App\Support\Events\EventSearchLogger;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Top event searches that found nothing, on the admin dashboard (TOG-8400).
 *
 * Content gaps made visible: when guests keep searching for a game night
 * nobody posted, this is where a moderator sees it. Normalized queries
 * only — the table stores no user id, no session, no IP and no raw input,
 * so there is nothing here that identifies who searched and nothing to
 * narrow per viewer. Moderator-only through the existing panel gate.
 *
 * `defaultKeySort(false)`: Filament otherwise appends an `id` tiebreaker to
 * every table query, and `id` is not in this GROUP BY — Postgres rejects
 * the query. The aggregation's own order (misses desc, query asc) is total
 * already: counts tie only on distinct queries, which the name breaks.
 */
class TopZeroResultSearches extends TableWidget
{
    // Rendered on first paint, not behind a lazy placeholder: a moderator
    // opening the dashboard to check for content gaps should see the misses
    // immediately. Same choice as the JoinFunnelStats widget beside it.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (EventSearchLogger $logger): Builder => $logger->topZeroResultQuery())
            ->heading('Top searches with no results')
            ->description('What guests looked for on /events and found nothing. A repeat miss here is a game night nobody posted yet.')
            ->columns([
                TextColumn::make('normalized_query')
                    ->label('Search')
                    ->copyable(),
                TextColumn::make('searches')
                    ->label('Misses')
                    // `number_format` in PHP rather than `->numeric()`: the
                    // column modifier formats through `Number::format`,
                    // which needs ext-intl. Same rendering, no extension
                    // requirement — the JoinFunnelStats widget formats its
                    // counts the same way.
                    ->formatStateUsing(fn (mixed $state): string => number_format((int) $state))
                    ->sortable(),
                TextColumn::make('last_searched_at')
                    ->label('Last searched')
                    ->since()
                    ->sortable(),
            ])
            ->emptyStateHeading('No missed searches')
            ->emptyStateDescription('Every recent /events search found something — or nobody has searched yet.')
            ->defaultKeySort(false)
            ->paginated(false);
    }
}
