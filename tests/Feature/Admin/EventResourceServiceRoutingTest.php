<?php

use App\Enums\EventStatus;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Jobs\SyncEventToDiscord;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

// The panel must go through EventService, not write the model directly —
// capacity is a row lock inside its transaction, cancelled is terminal there,
// and the Discord write-back is dispatched there. These prove the wiring by
// observing the service's side effects, which a direct Model::create() or
// status write would not produce.

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_moderator' => true]));
});

// The table tests skip without ext-intl: rendering a populated Filament table
// calls Number::format, which requires it. CI installs intl (ci.yml
// `extensions:` line), so the skips only ever apply to this sandbox's PHP.

it('creates a draft with the acting moderator as host via EventService', function () {
    livewire(CreateEvent::class)
        ->fillForm([
            'title' => 'Community game night',
            'game' => 'Helldivers 2',
            'description' => 'Squad up.',
            'starts_at' => '2026-10-01 20:00',
            'ends_at' => '2026-10-01 22:00',
            'timezone' => 'Europe/London',
            'location' => 'Voice: General',
            'capacity' => 8,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::query()->latest('id')->sole();

    // Draft status and created_by are EventService's doing, not the form's:
    // neither field is a form component, so a direct create would leave them
    // at their defaults and created_by null.
    expect($event->status)->toBe(EventStatus::Draft)
        ->and($event->created_by)->toBe(auth()->id())
        ->and($event->event_key)->not->toBeEmpty()
        // 20:00 Europe/London in October is 19:00Z — the instant conversion
        // that EventInput owns and a naive write would skip.
        ->and($event->starts_at->utc()->format('H:i'))->toBe('19:00');
});

it('publishes through the service, which tells Discord', function () {
    Queue::fake();
    $event = Event::factory()->create(['status' => EventStatus::Draft]);

    livewire(ListEvents::class)
        ->callTableAction('publish', $event);

    expect($event->fresh()->status)->toBe(EventStatus::Published);
    Queue::assertPushed(SyncEventToDiscord::class);
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament tables need ext-intl; present in CI, absent in the local sandbox PHP');

it('cancels through the service and cancelled stays terminal', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    livewire(ListEvents::class)
        ->callTableAction('cancel', $event);

    expect($event->fresh()->status)->toBe(EventStatus::Cancelled);

    // The publish action must not resurrect it: hidden in the UI, and the
    // service throws if called anyway.
    livewire(ListEvents::class)
        ->assertTableActionHidden('publish', $event);
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament tables need ext-intl; present in CI, absent in the local sandbox PHP');

it('hides publish and cancel from the table for events they cannot apply to', function () {
    $cancelled = Event::factory()->create(['status' => EventStatus::Cancelled]);

    livewire(ListEvents::class)
        ->assertTableActionHidden('publish', $cancelled)
        ->assertTableActionHidden('cancel', $cancelled);
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament tables need ext-intl; present in CI, absent in the local sandbox PHP');
