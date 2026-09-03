<?php

namespace App\Filament\Resources\Events\Tables;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Services\EventService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('game')
                    ->toggleable(),
                TextColumn::make('starts_at')
                    ->label('Starts')
                    ->dateTime('D j M Y, H:i', 'UTC')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (EventStatus $state): string => match ($state) {
                        EventStatus::Published => 'success',
                        EventStatus::Draft => 'gray',
                        EventStatus::Cancelled => 'danger',
                        EventStatus::Past => 'warning',
                    }),
                TextColumn::make('capacity')
                    ->placeholder('Unlimited')
                    ->toggleable(),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(EventStatus::class),
            ])
            ->recordActions([
                EditAction::make(),

                // Publish and cancel go through EventService, never a direct
                // status write: transitions are row-locked there, cancelled is
                // terminal there, and the Discord write-back is dispatched
                // there. There is deliberately no delete anywhere on this
                // resource — a published event has been announced, and the
                // audit trail of a cancellation is the record that it was.
                Action::make('publish')
                    ->authorize(fn (Event $record): bool => auth()->user()?->can('publish', $record) ?? false)
                    ->visible(fn (Event $record): bool => $record->status === EventStatus::Draft)
                    ->requiresConfirmation()
                    ->modalDescription('Publishing announces the event to Discord. Members can RSVP from that moment.')
                    ->action(fn (Event $record, EventService $service) => $service->publish($record))
                    ->icon('heroicon-o-megaphone')
                    ->color('success'),
                Action::make('cancel')
                    ->authorize(fn (Event $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                    ->visible(fn (Event $record): bool => in_array($record->status, [EventStatus::Draft, EventStatus::Published], true))
                    ->requiresConfirmation()
                    ->modalDescription('Cancelling is permanent. Discord will be told; RSVPs are not coming back.')
                    ->action(fn (Event $record, EventService $service) => $service->cancel($record))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger'),
            ])
            ->toolbarActions([
                // No bulk actions: every state change should be one deliberate,
                // individually audited moderator decision.
            ]);
    }
}
