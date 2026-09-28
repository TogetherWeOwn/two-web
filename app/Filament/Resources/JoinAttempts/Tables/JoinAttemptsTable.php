<?php

namespace App\Filament\Resources\JoinAttempts\Tables;

use App\Enums\JoinOutcome;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class JoinAttemptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('outcome')
                    ->badge()
                    ->color(fn (JoinOutcome $state): string => match ($state) {
                        JoinOutcome::Added => 'success',
                        JoinOutcome::AlreadyMember => 'gray',
                        JoinOutcome::Denied => 'warning',
                        JoinOutcome::Error, JoinOutcome::Degraded => 'danger',
                    }),
                TextColumn::make('source')
                    ->placeholder('—')
                    ->toggleable(),
                // Discord ids are opaque snowflakes, not searchable in any
                // useful sense — but the exact id from a bot log line should
                // find its row, so these two stay exact-match searchable while
                // the outcome badge carries the filtering.
                TextColumn::make('discord_id')
                    ->label('Discord ID')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('request_id')
                    ->label('Request ID')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Attempted')
                    ->dateTime('D j M Y, H:i', 'UTC')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('outcome')
                    ->options(JoinOutcome::class),
            ])
            // No record actions: opening a row is the deep-dive (the View
            // page), and this trail is read-only — there is deliberately no
            // edit or delete anywhere on this resource.
            ->recordActions([
                //
            ])
            ->toolbarActions([
                //
            ]);
    }
}
