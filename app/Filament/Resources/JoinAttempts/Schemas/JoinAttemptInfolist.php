<?php

namespace App\Filament\Resources\JoinAttempts\Schemas;

use App\Enums\JoinOutcome;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class JoinAttemptInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Outcome')
                    ->schema([
                        TextEntry::make('outcome')
                            ->badge()
                            ->color(fn (JoinOutcome $state): string => match ($state) {
                                JoinOutcome::Added => 'success',
                                JoinOutcome::AlreadyMember => 'gray',
                                JoinOutcome::Denied => 'warning',
                                JoinOutcome::Error, JoinOutcome::Degraded => 'danger',
                            }),
                        TextEntry::make('source')
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('Attempted at')
                            ->dateTime('D j M Y, H:i', 'UTC'),
                    ])
                    ->columns(3),
                // The join-back-to-the-logs block: request_id and discord_id
                // are the two ids JoinController logs alongside the outcome,
                // so a moderator cross-referencing a bot log line copies them
                // from here. Copyable for that reason, not editable — this
                // page has no form.
                Section::make('Trace')
                    ->schema([
                        TextEntry::make('request_id')
                            ->label('Request ID')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('discord_id')
                            ->label('Discord ID')
                            ->copyable()
                            ->placeholder('—'),
                    ])
                    ->columns(2),
            ]);
    }
}
