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
     * Wall text alone cannot round-trip a DST fold or gap: "01:30 Europe/London"
     * on 2026-10-25 names two instants an hour apart, and the fill renders one
     * while the save parses the other. So the stored instant rides along in a
     * hidden `<key>_utc` carrier, and handleRecordUpdate keeps it when the wall
     * text comes back unchanged — an innocent open-and-save is then a true
     * no-op.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $timezone = is_string($data['timezone'] ?? null) ? $data['timezone'] : 'UTC';

        foreach (['starts_at' => 'starts_at_utc', 'ends_at' => 'ends_at_utc'] as $key => $hint) {
            if (is_string($data[$key] ?? null)) {
                $instant = CarbonImmutable::parse($data[$key], 'UTC');
                $data[$hint] = $instant->toIso8601String();
                $data[$key] = $instant
                    ->setTimezone($timezone)
                    ->format('Y-m-d H:i:s');
            }
        }

        return $data;
    }

    /**
     * Decide per field whether the captured instant survives. The wall text
     * stays naive throughout — resubmitting the instant as an offset string is
     * refused by EventInput's naive-wall guard (TOG-6804) — so a match simply
     * leaves the `<key>_utc` carrier in place for handleRecordUpdate, while any
     * keystroke drops it and the new wall text wins, including when the host
     * retyped the same characters the fold left ambiguous.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $timezone = is_string($data['timezone'] ?? null) ? $data['timezone'] : 'UTC';

        foreach (['starts_at', 'ends_at'] as $key) {
            $hint = $key.'_utc';
            $wall = is_string($data[$key] ?? null) ? $data[$key] : null;
            $instant = is_string($data[$hint] ?? null) ? $data[$hint] : null;

            if ($wall === null || $instant === null) {
                unset($data[$hint]);

                continue;
            }

            try {
                $captured = CarbonImmutable::parse($instant, 'UTC');
                // The picker speaks minute precision, so truncate both sides —
                // comparing seconds would never match and the carrier would be
                // dead. Seconds still survive an unchanged save: they ride in
                // the carrier, not in the wall text.
                $submittedMinute = CarbonImmutable::parse($wall, $timezone)->format('Y-m-d H:i');
            } catch (\Throwable) {
                unset($data[$hint]);

                continue;
            }

            $wallAtCapture = $captured->setTimezone($timezone)->format('Y-m-d H:i');

            if ($submittedMinute !== $wallAtCapture) {
                unset($data[$hint]);
            }
        }

        return $data;
    }

    /**
     * Same rule as CreateEvent: EventService owns event writes. No delete
     * header action — cancellation is the moderator verb for "this is off",
     * and it leaves the audit trail a deletion would erase.
     *
     * An untouched fold/gap-ambiguous wall time keeps the exact instant the
     * form rendered: the naive-wall guard forbids feeding that instant back
     * through the string path, so the captured Carbon is spliced into a
     * rebuilt input here instead.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Event $record */
        $input = EventInput::fromValidated($data);

        $preserved = [];

        foreach (['starts_at' => 'startsAt', 'ends_at' => 'endsAt'] as $key => $property) {
            $hint = $data[$key.'_utc'] ?? null;

            if (! is_string($hint)) {
                continue;
            }

            try {
                $preserved[$property] = CarbonImmutable::parse($hint, 'UTC');
            } catch (\Throwable) {
                // A forged or mangled carrier falls back to the parsed wall
                // text — the same answer the form gave before carriers existed.
                continue;
            }
        }

        if ($preserved !== []) {
            $input = new EventInput(
                title: $input->title,
                game: $input->game,
                description: $input->description,
                startsAt: $preserved['startsAt'] ?? $input->startsAt,
                endsAt: $preserved['endsAt'] ?? $input->endsAt,
                timezone: $input->timezone,
                location: $input->location,
                capacity: $input->capacity,
            );
        }

        return app(EventService::class)->update($record, $input);
    }
}
