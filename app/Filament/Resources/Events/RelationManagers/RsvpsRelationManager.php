<?php

namespace App\Filament\Resources\Events\RelationManagers;

use App\Enums\RsvpStatus;
use App\Models\Rsvp;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RsvpsRelationManager extends RelationManager
{
    protected static string $relationship = 'rsvps';

    protected static ?string $title = 'RSVPs';

    /**
     * Filament's own `CanAuthorizeAccess` trait only defines
     * `hydrateCanAuthorizeAccess`, which Livewire fires on subsequent
     * requests — never on the initial mount. The edit page filters its
     * managers through `canViewForRecord` before mounting, but a component
     * mounted any other way would render for a member unchecked. So the
     * roster enforces RsvpPolicy::viewAny here too, in `boot`, which Livewire
     * calls on every mount and every subsequent request alike.
     */
    public function boot(): void
    {
        abort_unless(static::canViewForRecord($this->getOwnerRecord(), $this->getPageClass()), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                // Display name is the identity the site shows everywhere,
                // username the fallback — the same chain as the share page's
                // attendee list (EventPageController) and User::getFilamentName.
                TextColumn::make('user.display_name')
                    ->label('Member')
                    ->searchable()
                    ->formatStateUsing(fn (?string $state, Rsvp $record): ?string => $state
                        ?? $record->user?->username),
                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->color(fn (RsvpStatus $state): string => match ($state) {
                        RsvpStatus::Going => 'success',
                        RsvpStatus::Maybe => 'warning',
                        RsvpStatus::NotGoing => 'danger',
                        RsvpStatus::Waitlisted => 'gray',
                    }),
                // The member's latest answer, not their first: re-answering
                // rewrites the row (EventService::rsvp is an updateOrCreate),
                // so updated_at is when the current status was given.
                TextColumn::make('updated_at')
                    ->label('Answered')
                    ->dateTime('D j M Y, H:i', 'UTC')
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            // One query for the whole roster: without this every member cell
            // is its own query.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->emptyStateHeading('No RSVPs yet')
            // Read-only by construction: moderators answer nothing for
            // anybody (RsvpPolicy), so the roster offers no write actions and
            // pins that with explicit empties rather than relying on the
            // absence of defaults.
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
