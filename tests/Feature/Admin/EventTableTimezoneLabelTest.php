<?php

use App\Filament\Resources\Events\Pages\ListEvents;
use App\Models\Event;
use App\Models\User;

use function Pest\Livewire\livewire;

it('labels the starts column as UTC for events in different timezones', function () {
    $moderator = User::factory()->create(['is_moderator' => true]);
    $this->actingAs($moderator);

    // 20:00 in New York is already the next day in UTC.
    $newYork = Event::factory()->create([
        'created_by' => $moderator->id,
        'starts_at' => '2026-10-02 00:00:00',
        'ends_at' => '2026-10-02 02:00:00',
        'timezone' => 'America/New_York',
    ]);
    $london = Event::factory()->create([
        'created_by' => $moderator->id,
        'starts_at' => '2026-10-01 19:00:00',
        'ends_at' => '2026-10-01 21:00:00',
        'timezone' => 'Europe/London',
    ]);

    livewire(ListEvents::class)
        ->assertCanSeeTableRecords([$newYork, $london])
        ->assertSee('Starts (UTC)')
        ->assertTableColumnFormattedStateSet('starts_at', 'Fri 2 Oct 2026, 00:00', record: $newYork)
        ->assertSee('Fri 2 Oct 2026, 00:00')
        ->assertTableColumnFormattedStateSet('starts_at', 'Thu 1 Oct 2026, 19:00', record: $london)
        ->assertSee('Thu 1 Oct 2026, 19:00');
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament tables need ext-intl; present in CI, absent in the local sandbox PHP');
