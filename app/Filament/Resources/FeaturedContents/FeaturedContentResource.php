<?php

namespace App\Filament\Resources\FeaturedContents;

use App\Filament\Resources\FeaturedContents\Pages\CreateFeaturedContent;
use App\Filament\Resources\FeaturedContents\Pages\EditFeaturedContent;
use App\Filament\Resources\FeaturedContents\Pages\ListFeaturedContents;
use App\Filament\Resources\FeaturedContents\Schemas\FeaturedContentForm;
use App\Filament\Resources\FeaturedContents\Tables\FeaturedContentsTable;
use App\Models\FeaturedContent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class FeaturedContentResource extends Resource
{
    protected static ?string $model = FeaturedContent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static ?string $navigationLabel = 'Featured content';

    protected static ?string $modelLabel = 'featured content';

    protected static ?string $pluralModelLabel = 'featured content';

    public static function form(Schema $schema): Schema
    {
        return FeaturedContentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeaturedContentsTable::configure($table);
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
            'index' => ListFeaturedContents::route('/'),
            'create' => CreateFeaturedContent::route('/create'),
            'edit' => EditFeaturedContent::route('/{record}/edit'),
        ];
    }
}
