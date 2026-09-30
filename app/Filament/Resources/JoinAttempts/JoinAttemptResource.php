<?php

namespace App\Filament\Resources\JoinAttempts;

use App\Filament\Resources\JoinAttempts\Pages\ListJoinAttempts;
use App\Filament\Resources\JoinAttempts\Pages\ViewJoinAttempt;
use App\Filament\Resources\JoinAttempts\Schemas\JoinAttemptInfolist;
use App\Filament\Resources\JoinAttempts\Tables\JoinAttemptsTable;
use App\Models\JoinAttempt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Read-only viewer for individual join attempts (TOG-8401).
 *
 * JoinAttempt rows are a write-once audit trail: JoinController writes them
 * alongside its log calls, and the JoinFunnelStats widget counts them. This
 * resource lets a moderator inspect one row — filter the list by outcome,
 * open the record, copy the trace ids back into the bot logs. No create or
 * edit pages are registered, no record actions, and the policy denies every
 * write verb: read-only is enforced in three places on purpose.
 */
class JoinAttemptResource extends Resource
{
    protected static ?string $model = JoinAttempt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Join attempts';

    protected static ?string $modelLabel = 'join attempt';

    public static function infolist(Schema $schema): Schema
    {
        return JoinAttemptInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JoinAttemptsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListJoinAttempts::route('/'),
            'view' => ViewJoinAttempt::route('/{record}'),
        ];
    }
}
