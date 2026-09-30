<?php

use App\Enums\EventStatus;
use App\Enums\RecurrenceFrequency;
use App\Jobs\SyncEventToDiscord;
use App\Models\Event;
use App\Models\User;
use App\Services\EventService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 30)->setTime(12, 0));
    $this->actingAs(User::factory()->create(['is_moderator' => true]));
    Queue::fake();
});

it('refuses an already-ended draft through the JSON publish route without announcing it', function () {
    $event = Event::factory()->draft()->create([
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subSecond(),
    ]);
    $updatedAt = $event->updated_at;

    $this->postJson(route('events.publish', $event))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ends_at')
        ->assertJsonPath('errors.ends_at.0', 'An event that has already ended cannot be published. Update its dates first.');

    expect($event->fresh()->status)->toBe(EventStatus::Draft)
        ->and($event->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
    Queue::assertNothingPushed();
});

it('checks persisted end time under the lock rather than trusting a stale draft model', function () {
    $event = Event::factory()->draft()->create();
    Event::query()->whereKey($event->getKey())->update([
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subSecond(),
    ]);

    expect(fn () => app(EventService::class)->publish($event))
        ->toThrow(ValidationException::class, 'An event that has already ended cannot be published. Update its dates first.');

    expect($event->fresh()->status)->toBe(EventStatus::Draft);
    Queue::assertNothingPushed();
});

it('allows publication until the strict end-time boundary, including ongoing events', function (int $secondsUntilEnd) {
    $event = Event::factory()->draft()->create([
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addSeconds($secondsUntilEnd),
    ]);

    $this->postJson(route('events.publish', $event))->assertOk();

    expect($event->fresh()->status)->toBe(EventStatus::Published);
    Queue::assertPushed(SyncEventToDiscord::class, fn (SyncEventToDiscord $job): bool => $job->eventKey === $event->event_key);
})->with([0, 1]);

it('still allows cancellation of an already-ended draft', function () {
    $event = Event::factory()->draft()->create([
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subSecond(),
    ]);

    $this->postJson(route('events.cancel', $event))->assertOk();

    expect($event->fresh()->status)->toBe(EventStatus::Cancelled);
});

it('refuses an ended draft child when its series parent is published', function () {
    $parent = Event::factory()->draft()->create([
        'recurrence_frequency' => RecurrenceFrequency::Weekly,
        'recurrence_count' => 2,
        'recurrence_index' => 1,
    ]);
    $child = Event::factory()->draft()->create([
        'parent_event_id' => $parent->getKey(),
        'recurrence_index' => 2,
        'starts_at' => now()->subHours(2),
        'ends_at' => now()->subSecond(),
    ]);

    expect(fn () => app(EventService::class)->publish($parent))
        ->toThrow(ValidationException::class);

    expect($parent->fresh()->status)->toBe(EventStatus::Draft)
        ->and($child->fresh()->status)->toBe(EventStatus::Draft);
    Queue::assertNothingPushed();
});
