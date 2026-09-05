<?php

namespace App\Filament\Resources\FeaturedContents\Pages;

use App\Filament\Resources\FeaturedContents\FeaturedContentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFeaturedContent extends EditRecord
{
    protected static string $resource = FeaturedContentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
