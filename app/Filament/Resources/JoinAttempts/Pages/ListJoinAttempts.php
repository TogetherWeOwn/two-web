<?php

namespace App\Filament\Resources\JoinAttempts\Pages;

use App\Filament\Resources\JoinAttempts\JoinAttemptResource;
use Filament\Resources\Pages\ListRecords;

class ListJoinAttempts extends ListRecords
{
    protected static string $resource = JoinAttemptResource::class;

    // No CreateAction: join attempts are written by JoinController, never by
    // hand. The base page adds no header actions on its own.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
