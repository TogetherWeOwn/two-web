<?php

namespace App\Filament\Resources\DataRequests\Tables;

use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Models\DataRequest;
use App\Models\User;
use App\Services\DataRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DataRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Asked')
                    ->since()
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (DataRequestType $state): string => match ($state) {
                        DataRequestType::Deletion => 'danger',
                        DataRequestType::Export => 'info',
                    }),
                TextColumn::make('discord_id')
                    ->label('Discord ID')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (DataRequestStatus $state): string => match ($state) {
                        DataRequestStatus::Pending => 'warning',
                        DataRequestStatus::Approved => 'success',
                        DataRequestStatus::Rejected => 'gray',
                    }),
                TextColumn::make('decided_at')
                    ->label('Decided')
                    ->since()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('created_at')
            ->filters([
                // No status filter: the queue lists pending rows only (the
                // resource scopes the query), so a status dropdown could never
                // match anything but pending. Type stays — triage asks
                // "which request is it, export or deletion" first.
                SelectFilter::make('type')
                    ->options(DataRequestType::class),
            ])
            ->recordActions([
                // Approve / reject go through DataRequestService, never a
                // direct status write: the decision is row-locked there, a
                // deletion approval queues the actual delete there, and the
                // activity log keeps the decision itself on record. Visible
                // only while the row is pending — deciding a closed row is
                // refused in the service and hidden here.
                Action::make('approve')
                    ->visible(fn (DataRequest $record): bool => $record->status === DataRequestStatus::Pending)
                    ->requiresConfirmation()
                    ->modalDescription('For a deletion: this queues the actual delete. Tell the member to download anything they want to keep first.')
                    ->action(function (DataRequest $record, DataRequestService $service): void {
                        $decider = auth()->user();
                        abort_unless($decider instanceof User, 403);
                        $decided = $service->approve($record, $decider);

                        if (! $decided) {
                            Notification::make()
                                ->warning()
                                ->title('Already decided')
                                ->body('Another moderator got here first — nothing changed.')
                                ->send();
                        }
                    })
                    ->icon('heroicon-o-check')
                    ->color('success'),
                Action::make('reject')
                    ->visible(fn (DataRequest $record): bool => $record->status === DataRequestStatus::Pending)
                    ->requiresConfirmation()
                    ->modalDescription('Rejection changes nothing about the member’s data. Verify identity first — see the runbook.')
                    ->form([
                        Select::make('decision_reason')
                            ->label('Reason')
                            ->options(array_combine(
                                DataRequestService::REJECTION_REASONS,
                                DataRequestService::REJECTION_REASONS,
                            ))
                            ->required(),
                    ])
                    ->action(function (DataRequest $record, DataRequestService $service, array $data): void {
                        $decider = auth()->user();
                        abort_unless($decider instanceof User, 403);
                        $decided = $service->reject($record, $decider, (string) ($data['decision_reason'] ?? ''));

                        if (! $decided) {
                            Notification::make()
                                ->warning()
                                ->title('Already decided')
                                ->body('Another moderator got here first — nothing changed.')
                                ->send();
                        }
                    })
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger'),
            ])
            ->toolbarActions([
                // No bulk actions: every decision is one deliberate,
                // individually audited moderator act.
            ]);
    }
}
