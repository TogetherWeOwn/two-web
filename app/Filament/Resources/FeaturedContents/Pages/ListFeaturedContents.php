<?php

namespace App\Filament\Resources\FeaturedContents\Pages;

use App\Filament\Resources\FeaturedContents\FeaturedContentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeaturedContents extends ListRecords
{
    protected static string $resource = FeaturedContentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
