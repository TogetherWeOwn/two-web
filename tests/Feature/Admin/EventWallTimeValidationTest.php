<?php

use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_moderator' => true]));
});

dataset('invalid panel wall times', [
    'spring-forward gap' => ['2026-03-29 01:30', 'never occurred in Europe/London'],
    'numeric offset' => ['2026-03-29T03:00:00+02:00', 'without a UTC offset or zone'],
    'UTC designator' => ['2026-03-29T03:00:00Z', 'without a UTC offset or zone'],
    'named zone' => ['2026-03-29 03:00 Europe/London', 'without a UTC offset or zone'],
]);

it('reports invalid wall times on the create field without saving an event', function (string $field, string $wall, string $message) {
    $page = livewire(CreateEvent::class)
        ->fillForm([
            'title' => 'Community game night',
            'starts_at' => '2026-03-29 00:30',
            'ends_at' => '2026-03-29 04:30',
            'timezone' => 'Europe/London',
            'location' => 'Voice: General',
        ])
        // Submit raw picker state, including inputs a native browser would not emit.
        ->set('data.'.$field, $wall)
        ->call('create')
        ->assertHasFormErrors([$field]);

    expect(implode(' ', $page->instance()->getErrorBag()->get('data.'.$field)))->toContain($message)
        ->and(Event::query()->count())->toBe(0);
})->with(['starts_at', 'ends_at'])->with('invalid panel wall times');

it('reports invalid wall times on the edit field without changing the event', function (string $field, string $wall, string $message) {
    $event = Event::factory()->create([
        'starts_at' => '2026-03-29 00:30:00',
        'ends_at' => '2026-03-29 03:30:00',
        'timezone' => 'Europe/London',
    ]);
    $original = $event->fresh()->getAttributes();

    $page = livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['title' => 'This edit must not save'])
        ->set('data.'.$field, $wall)
        ->call('save')
        ->assertHasFormErrors([$field]);

    expect(implode(' ', $page->instance()->getErrorBag()->get('data.'.$field)))->toContain($message)
        ->and($event->fresh()->getAttributes())->toBe($original);
})->with(['starts_at', 'ends_at'])->with('invalid panel wall times');

it('validates gaps against the selected zone rather than the default', function () {
    $page = livewire(CreateEvent::class)
        ->fillForm([
            'title' => 'Community game night',
            'starts_at' => '2026-03-08 01:30',
            'ends_at' => '2026-03-08 04:30',
            'timezone' => 'America/New_York',
            'location' => 'Voice: General',
        ])
        ->set('data.starts_at', '2026-03-08 02:30')
        ->call('create')
        ->assertHasFormErrors(['starts_at']);

    expect(implode(' ', $page->instance()->getErrorBag()->get('data.starts_at')))->toContain('never occurred in America/New_York')
        ->and(Event::query()->count())->toBe(0);
});

it('creates real naive wall times on either side of the spring gap', function () {
    livewire(CreateEvent::class)
        ->fillForm([
            'title' => 'Community game night',
            'starts_at' => '2026-03-29 00:30',
            'ends_at' => '2026-03-29 02:30',
            'timezone' => 'Europe/London',
            'location' => 'Voice: General',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::query()->sole();
    expect($event->starts_at->toIso8601String())->toBe('2026-03-29T00:30:00+00:00')
        ->and($event->ends_at->toIso8601String())->toBe('2026-03-29T01:30:00+00:00');
});

it('keeps an untouched autumn fold instant and seconds when editing', function (string $start) {
    $event = Event::factory()->create([
        'starts_at' => $start,
        'ends_at' => '2026-10-25 03:00:37',
        'timezone' => 'Europe/London',
    ]);

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['title' => 'Updated title'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($event->fresh()->title)->toBe('Updated title')
        ->and($event->fresh()->starts_at->format('Y-m-d H:i:s'))->toBe($start)
        ->and($event->fresh()->ends_at->format('Y-m-d H:i:s'))->toBe('2026-10-25 03:00:37');
})->with([
    'first fold instant' => ['2026-10-25 00:30:23'],
    'second fold instant' => ['2026-10-25 01:30:23'],
]);
