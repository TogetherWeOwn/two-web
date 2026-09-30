<?php

use App\Enums\RsvpStatus;
use App\Filament\Resources\Events\Pages\EditEvent;
use App\Jobs\SyncEventToDiscord;
use App\Models\AgentEventGrant;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\EventService;
use App\Support\EventInput;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Queue::fake();
    $this->actingAs(User::factory()->create(['is_moderator' => true]));
    $this->event = Event::factory()->create(['capacity' => 8, 'timezone' => 'UTC']);
    Rsvp::factory()->count(5)->create(['event_id' => $this->event->id, 'status' => RsvpStatus::Going]);
    Rsvp::factory()->create(['event_id' => $this->event->id, 'status' => RsvpStatus::Maybe]);
    Rsvp::factory()->create(['event_id' => $this->event->id, 'status' => RsvpStatus::Waitlisted]);
    $this->fields = [
        'title' => 'Updated event title',
        'game' => $this->event->game,
        'description' => $this->event->description,
        'starts_at' => $this->event->starts_at->format('Y-m-d H:i:s'),
        'ends_at' => $this->event->ends_at->format('Y-m-d H:i:s'),
        'timezone' => 'UTC',
        'location' => 'Voice: General',
    ];
});

it('refuses capacity below occupied seats without saving any fields or changing RSVPs', function (string $path, ?int $oldCapacity) {
    $this->event->update(['capacity' => $oldCapacity]);
    $before = $this->event->fresh()->getAttributes();
    $rsvps = $this->event->rsvps()->orderBy('id')->get()->toArray();
    $fields = [...$this->fields, 'capacity' => 2];

    if ($path === 'api') {
        $this->patchJson(route('events.update', $this->event), $fields)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['capacity'])
            ->assertJsonPath('errors.capacity.0', 'Capacity cannot be lower than the number of members already going.');
    } else {
        livewire(EditEvent::class, ['record' => $this->event->event_key])
            ->fillForm($fields)
            ->call('save')
            ->assertHasFormErrors(['capacity'])
            ->assertNotNotified();
    }

    expect($this->event->fresh()->getAttributes())->toBe($before)
        ->and($this->event->rsvps()->orderBy('id')->get()->toArray())->toBe($rsvps);
    Queue::assertNotPushed(SyncEventToDiscord::class);
})->with(['api', 'panel'])->with(['finite' => 8, 'unlimited' => null]);

it('allows the occupied-seat floor, an increase, and unlimited capacity', function (string $path, ?int $capacity, int $going) {
    $fields = [...$this->fields, 'capacity' => $capacity];

    if ($path === 'api') {
        $this->patchJson(route('events.update', $this->event), $fields)->assertOk();
    } else {
        livewire(EditEvent::class, ['record' => $this->event->event_key])
            ->fillForm($fields)
            ->call('save')
            ->assertHasNoFormErrors();
    }

    expect($this->event->fresh()->capacity)->toBe($capacity)
        ->and($this->event->fresh()->title)->toBe('Updated event title')
        ->and($this->event->goingCount())->toBe($going)
        ->and($this->event->rsvps()->where('status', RsvpStatus::Maybe)->count())->toBe(1);
    Queue::assertPushed(SyncEventToDiscord::class);
})->with(['api', 'panel'])->with([
    'equal to Going only' => [5, 5],
    'increased with waitlist promotion' => [10, 6],
    'unlimited with waitlist promotion' => [null, 6],
]);

it('refuses an agent-owned capacity shrink without consuming its version', function () {
    $grant = AgentEventGrant::query()->create([
        'agent_id' => '00000000-0000-4000-8000-000000000001',
        'company_id' => '00000000-0000-4000-8000-000000000002',
        'guild_id' => '1545644954272137297',
        'verifier_hash' => AgentEventGrant::verifierFor('capacity-floor-test-fixture'),
        'max_events' => 1,
    ]);
    $this->event->update([
        'created_by' => null,
        'agent_grant_id' => $grant->id,
        'agent_version' => 1,
    ]);
    $before = $this->event->fresh()->getAttributes();
    $input = EventInput::fromValidated([...$this->fields, 'capacity' => 2]);

    try {
        app(EventService::class)->updateForGrant($this->event, $input, 1);
        $this->fail('A capacity below occupied seats must be refused.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('capacity');
    }

    expect($this->event->fresh()->getAttributes())->toBe($before)
        ->and($this->event->goingCount())->toBe(5);
    Queue::assertNotPushed(SyncEventToDiscord::class);
});

it('recounts occupied seats when saving a form opened before another member joined', function () {
    $page = livewire(EditEvent::class, ['record' => $this->event->event_key])
        ->fillForm([...$this->fields, 'capacity' => 5]);
    Rsvp::factory()->create(['event_id' => $this->event->id, 'status' => RsvpStatus::Going]);

    $page->call('save')->assertHasFormErrors(['capacity']);

    expect($this->event->fresh()->capacity)->toBe(8)
        ->and($this->event->goingCount())->toBe(6);
    Queue::assertNotPushed(SyncEventToDiscord::class);
});
