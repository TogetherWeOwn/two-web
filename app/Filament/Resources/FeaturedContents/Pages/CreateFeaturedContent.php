<?php

namespace App\Filament\Resources\FeaturedContents\Pages;

use App\Filament\Resources\FeaturedContents\FeaturedContentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFeaturedContent extends CreateRecord
{
    protected static string $resource = FeaturedContentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Who staged it, for the trail. Not part of the form: a moderator
        // cannot file content as somebody else.
        $data['created_by'] = auth()->id();

        return $data;
    }
}
