<?php

namespace App\Filament\Resources\FeaturedContents\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FeaturedContentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('position')
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable(),
                IconColumn::make('is_published')
                    ->label('Published')
                    ->boolean(),
                TextColumn::make('starts_at')
                    ->label('From')
                    ->dateTime('j M Y, H:i', 'UTC')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('ends_at')
                    ->label('Until')
                    ->dateTime('j M Y, H:i', 'UTC')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Last changed')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('position')->orderBy('id'))
            ->filters([
                TernaryFilter::make('is_published')
                    ->label('Published'),
            ])
            ->recordActions([
                EditAction::make(),

                // Deleting featured content is fine — unlike an event, nothing
                // downstream (Discord, RSVPs) refers to it, and the activity
                // log keeps the deletion itself on record. One row at a time,
                // though: no bulk delete, every removal is one audited click.
                DeleteAction::make(),
            ])
            ->toolbarActions([
                //
            ]);
    }
}
