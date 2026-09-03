<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use App\Models\Event;
use App\Services\EventService;
use App\Support\EventInput;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    /**
     * The row stores UTC instants plus the host's zone; the form speaks local
     * wall time plus zone. Convert on the way in, or the form shows "19:00"
     * for an event the host typed as "20:00 Europe/London" — and saving it
     * unchanged would then shift the event by an hour.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $timezone = is_string($data['timezone'] ?? null) ? $data['timezone'] : 'UTC';

        foreach (['starts_at', 'ends_at'] as $key) {
            if (is_string($data[$key] ?? null)) {
                $data[$key] = CarbonImmutable::parse($data[$key], 'UTC')
                    ->setTimezone($timezone)
                    ->format('Y-m-d H:i:s');
            }
        }

        return $data;
    }

    /**
     * Same rule as CreateEvent: EventService owns event writes. No delete
     * header action — cancellation is the moderator verb for "this is off",
     * and it leaves the audit trail a deletion would erase.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Event $record */
        return app(EventService::class)->update($record, EventInput::fromValidated($data));
    }
}
