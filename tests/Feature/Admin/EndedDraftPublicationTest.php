<?php

use App\Enums\EventStatus;
use App\Filament\Resources\Events\Pages\CreateEvent;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Filament\Resources\Events\Pages\ListEvents;
use App\Jobs\SyncEventToDiscord;
use App\Models\Event;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Queue;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(12, 0));
    $this->actingAs(User::factory()->create(['is_moderator' => true]));
    Queue::fake();
});

it('keeps a past-dated create and edit round-trip in Draft and explains why publish is refused', function () {
    livewire(CreateEvent::class)
        ->fillForm([
            'title' => 'Old game night',
            'game' => 'Minecraft',
            'description' => 'An old draft.',
            'starts_at' => '2020-01-01 20:00',
            'ends_at' => '2020-01-01 22:00',
            'timezone' => 'Europe/London',
            'location' => 'Voice: General',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::query()->sole();
    livewire(EditEvent::class, ['record' => $event->event_key])
        ->call('save')
        ->assertHasNoFormErrors();

    livewire(ListEvents::class)
        ->callTableAction('publish', $event)
        ->assertHasTableActionErrors(['ends_at'])
        ->assertNotified(Notification::make()
            ->title('An event that has already ended cannot be published. Update its dates first.')
            ->danger());

    expect($event->fresh()->status)->toBe(EventStatus::Draft)
        ->and($event->fresh()->ends_at->utc()->format('Y-m-d H:i'))->toBe('2020-01-01 22:00');
    Queue::assertNothingPushed();

    livewire(EditEvent::class, ['record' => $event->event_key])
        ->fillForm([
            'starts_at' => '2026-10-01 20:00',
            'ends_at' => '2026-10-01 22:00',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    livewire(ListEvents::class)
        ->callTableAction('publish', $event->fresh())
        ->assertHasNoTableActionErrors();

    expect($event->fresh()->status)->toBe(EventStatus::Published);
    Queue::assertPushed(SyncEventToDiscord::class);
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament tables require ext-intl; installed in CI');

it('refuses a draft that ends while its publish confirmation is open', function () {
    $event = Event::factory()->draft()->create([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addSecond(),
    ]);
    $page = livewire(ListEvents::class)->mountTableAction('publish', $event);

    $this->travel(2)->seconds();

    $page->callMountedTableAction()
        ->assertHasTableActionErrors(['ends_at'])
        ->assertNotified(Notification::make()
            ->title('An event that has already ended cannot be published. Update its dates first.')
            ->danger());

    expect($event->fresh()->status)->toBe(EventStatus::Draft);
    Queue::assertNothingPushed();
})->skip(fn (): bool => ! extension_loaded('intl'), 'Filament tables require ext-intl; installed in CI');
