<?php

use App\Enums\EventStatus;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Models\Event;
use Filament\Tables\Table;

it('only promises cancellation of existing Discord mirrors while retaining the permanent warning', function (EventStatus $status, ?string $mirrorId, bool $series): void {
    $event = (new Event)->forceFill([
        'status' => $status,
        'discord_event_id' => $mirrorId,
        'recurrence_frequency' => $series ? 'weekly' : null,
    ]);

    // Resolve the real action with an unsaved record; no page mount, database,
    // cancellation write or Discord request is needed to verify modal copy.
    $table = EventsTable::configure(Table::make(new ListEvents));
    $action = $table->getAction('cancel');

    expect($action)->not->toBeNull();

    $description = $action->record($event)->getModalDescription();

    expect($description)->toBe($series
        ? 'Cancelling calls off every instance in the series. This is permanent — any existing Discord mirrors will be cancelled and RSVPs are not coming back.'
        : 'Cancelling is permanent. Any existing Discord mirror will be cancelled; RSVPs are not coming back.');
})->with([
    'draft without mirror' => [EventStatus::Draft, null, false],
    'draft with mirror' => [EventStatus::Draft, '123456789012345678', false],
    'published without mirror' => [EventStatus::Published, null, false],
    'published with mirror' => [EventStatus::Published, '123456789012345678', false],
    'draft series without mirror' => [EventStatus::Draft, null, true],
    'draft series with mirror' => [EventStatus::Draft, '123456789012345678', true],
    'published series without mirror' => [EventStatus::Published, null, true],
    'published series with mirror' => [EventStatus::Published, '123456789012345678', true],
]);
