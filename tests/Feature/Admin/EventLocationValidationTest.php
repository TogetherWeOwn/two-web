<?php

use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Queue::fake();
    Http::preventStrayRequests();
    $this->actingAs(User::factory()->create(['is_moderator' => true]));
    $this->eventData = [
        'title' => 'Community game night',
        'starts_at' => '2026-10-01 20:00',
        'ends_at' => '2026-10-01 22:00',
        'timezone' => 'Europe/London',
    ];
});

dataset('invalid event locations', [
    'null' => [null, 'required'],
    'empty' => ['', 'required'],
    'whitespace' => ['   ', 'required'],
    'overlong' => [str_repeat('x', 256), 'max'],
]);

it('rejects invalid locations when creating through the panel without saving a row', function (?string $location, string $rule) {
    livewire(CreateEvent::class)
        ->fillForm([...$this->eventData, 'location' => $location])
        ->call('create')
        ->assertHasFormErrors(['location' => $rule]);

    expect(Event::query()->count())->toBe(0);
})->with('invalid event locations');

it('rejects invalid locations when editing through the panel without changing the row', function (?string $location, string $rule) {
    $event = Event::factory()->create(['location' => 'Voice: General']);
    $originalTitle = $event->title;

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['title' => 'This edit must not save', 'location' => $location])
        ->call('save')
        ->assertHasFormErrors(['location' => $rule]);

    expect($event->fresh()->location)->toBe('Voice: General')
        ->and($event->fresh()->title)->toBe($originalTitle);
})->with('invalid event locations');

it('rejects invalid locations when creating through the API without saving a row', function (?string $location) {
    $this->postJson(route('events.store'), [...$this->eventData, 'location' => $location])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('location');

    expect(Event::query()->count())->toBe(0);
})->with('invalid event locations');

it('rejects invalid locations when editing through the API without changing the row', function (?string $location) {
    $event = Event::factory()->create(['location' => 'Voice: General']);
    $originalTitle = $event->title;

    $this->patchJson(route('events.update', $event), [...$this->eventData, 'location' => $location])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('location');

    expect($event->fresh()->location)->toBe('Voice: General')
        ->and($event->fresh()->title)->toBe($originalTitle);
})->with('invalid event locations');

it('requires an omitted location on panel and API creates', function () {
    livewire(CreateEvent::class)
        ->fillForm($this->eventData)
        ->call('create')
        ->assertHasFormErrors(['location' => 'required']);

    $this->postJson(route('events.store'), $this->eventData)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('location');

    expect(Event::query()->count())->toBe(0);
});

it('requires a location before editing a legacy event with no location', function () {
    $event = Event::factory()->create(['location' => null]);
    $originalTitle = $event->title;

    livewire(EditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm(['title' => 'This edit must not save'])
        ->call('save')
        ->assertHasFormErrors(['location' => 'required']);

    $this->patchJson(route('events.update', $event), $this->eventData)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('location');

    expect($event->fresh()->location)->toBeNull()
        ->and($event->fresh()->title)->toBe($originalTitle);
});

it('accepts valid locations on panel and API creates and edits', function (string $location) {
    livewire(CreateEvent::class)
        ->fillForm([...$this->eventData, 'location' => $location])
        ->call('create')
        ->assertHasNoFormErrors();

    $panelEvent = Event::query()->sole();
    expect($panelEvent->location)->toBe($location);

    $panelEvent->update(['location' => null]);
    livewire(EditEvent::class, ['record' => $panelEvent->getRouteKey()])
        ->fillForm(['location' => $location])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($panelEvent->fresh()->location)->toBe($location);

    $response = $this->postJson(route('events.store'), [...$this->eventData, 'location' => $location])
        ->assertCreated();
    $apiEvent = Event::query()->where('event_key', $response->json('data.event_key'))->firstOrFail();
    expect($apiEvent->location)->toBe($location);

    $apiEvent->update(['location' => null]);
    $this->patchJson(route('events.update', $apiEvent), [...$this->eventData, 'location' => $location])
        ->assertOk();
    expect($apiEvent->fresh()->location)->toBe($location);
})->with([
    'named location' => 'Voice: General',
    'maximum length' => str_repeat('x', 255),
]);
