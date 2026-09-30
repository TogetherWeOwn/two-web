<?php

namespace App\Filament\Resources\Events\Tables;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Services\EventService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
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
                    ->label('Starts (UTC)')
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
                // Whether the event takes new answers (TOG-8725). A paused
                // event stays published and visible while refusing new
                // answers — unpublishing to the same end would hide it.
                IconColumn::make('rsvp_open')
                    ->label('RSVPs')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-pause-circle')
                    ->toggleable(),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(EventStatus::class),
                TernaryFilter::make('rsvp_open')
                    ->label('RSVPs open'),
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
                // Pause and reopen answers (TOG-8725). Through EventService
                // like every other state change above: row-locked there, and
                // the reopen settles freed seats to the head of the line
                // there. Pausing keeps the event visible while stopping new
                // answers; cancelling above is the permanent version.
                Action::make('pauseRsvps')
                    ->label('Pause RSVPs')
                    ->authorize(fn (Event $record): bool => auth()->user()?->can('toggleRsvp', $record) ?? false)
                    ->visible(fn (Event $record): bool => $record->status === EventStatus::Published && $record->isRsvpOpen())
                    ->requiresConfirmation()
                    ->modalDescription('Pausing keeps the event visible but stops new RSVPs. Existing answers stay; members can still withdraw. Reversible.')
                    ->action(fn (Event $record, EventService $service) => $service->setRsvpOpen($record, false))
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning'),
                Action::make('reopenRsvps')
                    ->label('Reopen RSVPs')
                    ->authorize(fn (Event $record): bool => auth()->user()?->can('toggleRsvp', $record) ?? false)
                    ->visible(fn (Event $record): bool => $record->status === EventStatus::Published && ! $record->isRsvpOpen())
                    ->requiresConfirmation()
                    ->modalDescription('Reopening takes new RSVPs again. Seats freed while paused go to the head of the waitlist first.')
                    ->action(fn (Event $record, EventService $service) => $service->setRsvpOpen($record, true))
                    ->icon('heroicon-o-play-circle')
                    ->color('success'),
            ])
            ->toolbarActions([
                // No bulk actions: every state change should be one deliberate,
                // individually audited moderator decision.
            ]);
    }
}
