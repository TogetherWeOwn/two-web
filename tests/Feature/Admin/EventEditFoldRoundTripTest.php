<?php

use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\User;

use function Pest\Livewire\livewire;

// The autumn fold makes one wall time name two instants an hour apart: on
// 2026-10-25 in Europe/London, both 00:30Z and 01:30Z read as "01:30" locally.
// The edit form used to fill with, and save back, wall text alone — an innocent
// open-and-save silently shifted a first-occurrence event by +1h (TOG-6805).

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_moderator' => true]));
});

function foldEvent(): Event
{
    // First 01:30 (BST side): 00:30Z, not the 01:30Z the parser prefers. The
    // end sits well clear of the fold so the form's after-starts validation
    // passes and the test exercises the shift, not the validator.
    return Event::factory()->create([
        'starts_at' => '2026-10-25 00:30:00',
        'ends_at' => '2026-10-25 04:30:00',
        'timezone' => 'Europe/London',
    ]);
}

it('keeps the stored instant on an unchanged save of a fold-ambiguous event', function () {
    $event = foldEvent();

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($event->fresh()->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-25 00:30:00');
});

it('respects a deliberately edited time on a fold-ambiguous event', function () {
    $event = foldEvent();

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['starts_at' => '2026-10-25 02:30'])
        ->call('save')
        ->assertHasNoFormErrors();

    // 02:30 is unambiguous (fold is over) and the keystroke dropped the
    // carrier, so the new wall text wins: 02:30 GMT = 02:30Z.
    expect($event->fresh()->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-25 02:30:00');
});

it('keeps sub-minute precision the picker cannot display on an unchanged save', function () {
    // The picker speaks minute precision, so wall text alone truncates seconds:
    // pre-fix, an unchanged save of 01:30:45Z rewrote it as 01:30:00Z. The
    // carrier preserves the exact instant — exercised on a spring-gap morning
    // so the fold and the gap are both covered by this file.
    $event = Event::factory()->create([
        'starts_at' => '2026-03-29 01:30:45',
        'ends_at' => '2026-03-29 03:30:00',
        'timezone' => 'Europe/London',
    ]);

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($event->fresh()->starts_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-03-29 01:30:45');
});
