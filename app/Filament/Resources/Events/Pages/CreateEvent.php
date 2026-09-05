<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Services\EventService;
use App\Support\EventInput;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateEvent extends CreateRecord
{
    protected static string $resource = EventResource::class;

    /**
     * Through EventService, never Model::create(): the service owns the status
     * (drafts only from here), the Discord write-back, and the transaction. A
     * panel that wrote the model directly would mint events the bot never
     * hears about.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(EventService::class)->create(
            auth()->user() ?? abort(403),
            EventInput::fromValidated($data),
        );
    }
}
