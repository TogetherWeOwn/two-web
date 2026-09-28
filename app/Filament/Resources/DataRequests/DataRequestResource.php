<?php

namespace App\Filament\Resources\DataRequests;

use App\Enums\DataRequestStatus;
use App\Filament\Resources\DataRequests\Pages\ListDataRequests;
use App\Filament\Resources\DataRequests\Tables\DataRequestsTable;
use App\Models\DataRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DataRequestResource extends Resource
{
    protected static ?string $model = DataRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?string $navigationLabel = 'Data requests';

    protected static ?string $modelLabel = 'data request';

    protected static ?string $pluralModelLabel = 'data requests';

    public static function form(Schema $schema): Schema
    {
        // The queue is decided, never edited: approve/reject record actions
        // are the only writes, and the request row itself is the audit trail.
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return DataRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        // List-only: no create page (members create rows from /profile), no
        // edit page (decisions are record actions), no view page (the table
        // carries what a triage needs, and the panel builds no infolists).
        return [
            'index' => ListDataRequests::route('/'),
        ];
    }

    /**
     * The queue only ever asks one question: what is still open. Closed rows
     * stay readable under the status filter until retention prunes them.
     *
     * @return Builder<DataRequest>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('status', DataRequestStatus::Pending)
            ->orderBy('created_at');
    }

    public static function getNavigationBadge(): ?string
    {
        $open = DataRequest::query()->where('status', DataRequestStatus::Pending)->count();

        return $open > 0 ? (string) $open : null;
    }
}
