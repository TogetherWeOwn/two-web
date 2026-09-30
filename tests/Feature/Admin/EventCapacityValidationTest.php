<?php

use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_moderator' => true]));
});

it('rejects fractional capacity when creating an event without saving a truncated row', function (mixed $capacity) {
    livewire(CreateEvent::class)
        ->fillForm([
            'title' => 'Community game night',
            'starts_at' => '2026-10-01 20:00',
            'ends_at' => '2026-10-01 22:00',
            'timezone' => 'Europe/London',
            'location' => 'Voice: General',
            'capacity' => $capacity,
        ])
        ->call('create')
        ->assertHasFormErrors(['capacity' => 'integer']);

    expect(Event::query()->count())->toBe(0);
})->with([
    'number' => [2.5],
    'numeric string' => ['2.5'],
]);

it('rejects fractional capacity when editing an event without changing the row', function (mixed $capacity) {
    $event = Event::factory()->create(['capacity' => 8]);
    $originalTitle = $event->title;

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm([
            'title' => 'This edit must not save',
            'capacity' => $capacity,
        ])
        ->call('save')
        ->assertHasFormErrors(['capacity' => 'integer']);

    expect($event->fresh()->capacity)->toBe(8)
        ->and($event->fresh()->title)->toBe($originalTitle);
})->with([
    'number' => [2.5],
    'numeric string' => ['2.5'],
]);

it('preserves integer and unlimited capacity when creating an event', function (mixed $capacity, ?int $expected) {
    livewire(CreateEvent::class)
        ->fillForm([
            'title' => 'Community game night',
            'starts_at' => '2026-10-01 20:00',
            'ends_at' => '2026-10-01 22:00',
            'timezone' => 'Europe/London',
            'location' => 'Voice: General',
            'capacity' => $capacity,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Event::query()->sole()->capacity)->toBe($expected);
})->with([
    'minimum integer' => [1, 1],
    'integer string' => ['8', 8],
    'null means unlimited' => [null, null],
    'empty means unlimited' => ['', null],
]);

it('preserves integer and unlimited capacity when editing an event', function (mixed $capacity, ?int $expected) {
    $event = Event::factory()->create(['capacity' => 4]);

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['capacity' => $capacity])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($event->fresh()->capacity)->toBe($expected);
})->with([
    'minimum integer' => [1, 1],
    'integer string' => ['8', 8],
    'null means unlimited' => [null, null],
    'empty means unlimited' => ['', null],
]);
