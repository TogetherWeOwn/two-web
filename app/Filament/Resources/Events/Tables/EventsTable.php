<?php

namespace App\Filament\Resources\Events\Tables;

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Services\EventService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // The series column reads each child's parent and the fill column
            // reads each row's going seats; eager-load/aggregate both once
            // rather than once per row.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('parentEvent')
                // `going_count` is the same aggregate the JSON listing selects
                // (EventController): one subquery up front, not a count per row.
                ->withCount(['rsvps as going_count' => fn ($query) => $query->where('status', RsvpStatus::Going)]))
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
                // Series at a glance: "Weekly 2/4" on a child, "Weekly ×4" on
                // the parent, nothing on a one-off. A moderator scanning the
                // table can tell which rows move together before cancelling.
                // The frequency word comes from the enum so a second frequency
                // here never disagrees with the value the form stored.
                TextColumn::make('recurrence_index')
                    ->label('Series')
                    ->formatStateUsing(function (Event $record): ?string {
                        $frequency = ($record->isSeriesChild() ? $record->parentEvent?->recurrence_frequency : $record->recurrence_frequency)?->value;

                        if ($frequency === null) {
                            return null;
                        }

                        $label = ucfirst($frequency);

                        if ($record->isSeriesChild()) {
                            $count = $record->parentEvent?->recurrence_count;

                            return $count === null ? $label : "{$label} {$record->recurrence_index}/{$count}";
                        }

                        return $record->recurrence_count === null ? $label : "{$label} ×{$record->recurrence_count}";
                    })
                    ->placeholder('—')
                    ->toggleable(),
                // Fill at a glance: "12/20" on a capped event, bare count when
                // uncapped. A full event (going >= capacity) is a warning
                // badge, so the row a moderator must not overbook stands out.
                // "maybe" is not a seat (see Event::goingCount): the count only
                // ever answers Going, same as the member-facing badge.
                TextColumn::make('going_count')
                    ->label('Fill')
                    ->formatStateUsing(function (Event $record): string {
                        // Prefer the query's aggregate; fall back to a count so
                        // the column still renders outside the table query (the
                        // same idiom as the events JSON resource).
                        $going = $record->going_count ?? $record->goingCount();

                        if ($record->capacity === null) {
                            return (string) $going;
                        }

                        return "{$going}/{$record->capacity}";
                    })
                    ->badge(fn (Event $record): bool => $record->capacity !== null
                        && ($record->going_count ?? $record->goingCount()) >= $record->capacity)
                    ->color(fn (Event $record): ?string => $record->capacity !== null
                        && ($record->going_count ?? $record->goingCount()) >= $record->capacity
                            ? 'warning'
                            : null),
                TextColumn::make('capacity')
                    ->placeholder('Unlimited')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    // A series parent names its consequence: publishing the first
                    // meeting announces every instance that exists, not just this
                    // row. A closure so the copy sees the record — one-offs read
                    // exactly as before.
                    ->modalDescription(fn (Event $record): string => $record->isSeriesParent()
                        ? 'Publishing announces the whole series to Discord — every instance that exists goes live and members can RSVP on each one.'
                        : 'Publishing announces the event to Discord. Members can RSVP from that moment.')
                    ->action(fn (Event $record, EventService $service) => $service->publish($record))
                    ->icon('heroicon-o-megaphone')
                    ->color('success'),
                Action::make('cancel')
                    ->authorize(fn (Event $record): bool => auth()->user()?->can('cancel', $record) ?? false)
                    ->visible(fn (Event $record): bool => in_array($record->status, [EventStatus::Draft, EventStatus::Published], true))
                    ->requiresConfirmation()
                    ->modalDescription(fn (Event $record): string => $record->isSeriesParent()
                        ? 'Cancelling calls off every instance in the series. This is permanent — Discord will be told and RSVPs are not coming back.'
                        : 'Cancelling is permanent. Discord will be told; RSVPs are not coming back.')
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
