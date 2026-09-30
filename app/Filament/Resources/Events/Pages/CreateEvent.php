<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Services\EventService;
use App\Support\EventInput;
use App\Support\RecurrenceInput;
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
     * With a repeat rule the service creates the whole series in one
     * transaction — the parent plus one row per occurrence — instead of a
     * single event. Without one this is the same create as before.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $recurrence = RecurrenceInput::fromFormData($data);
        $service = app(EventService::class);
        $host = auth()->user() ?? abort(403);

        if ($recurrence === null) {
            return $service->create($host, EventInput::fromValidated($data));
        }

        return $service->createSeries($host, EventInput::fromValidated($data), $recurrence);
    }
}
