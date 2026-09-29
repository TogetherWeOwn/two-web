<?php

namespace App\Filament\Resources\JoinAttempts\Pages;

use App\Filament\Resources\JoinAttempts\JoinAttemptResource;
use Filament\Resources\Pages\ViewRecord;

class ViewJoinAttempt extends ViewRecord
{
    protected static string $resource = JoinAttemptResource::class;

    // No EditAction: the audit trail is write-once. The base view page adds
    // no header actions on its own; the empty override states the intent.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
