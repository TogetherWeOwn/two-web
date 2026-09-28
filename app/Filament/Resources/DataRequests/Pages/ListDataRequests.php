<?php

namespace App\Filament\Resources\DataRequests\Pages;

use App\Filament\Resources\DataRequests\DataRequestResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;

class ListDataRequests extends ListRecords
{
    protected static string $resource = DataRequestResource::class;

    /**
     * No header actions: members create rows from /profile, and every
     * decision is a per-row approve/reject. The default header carries no
     * Create button, so omitting this override is the whole control.
     *
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
