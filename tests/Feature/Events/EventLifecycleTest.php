<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Jobs\SyncEventToDiscord;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\EventService;
use App\Support\EventInput;
use App\Support\RsvpRateLimit;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->moderator = User::factory()->create(['is_moderator' => true]);
    $this->member = User::factory()->create(['is_moderator' => false]);
});

/** @return array<string, mixed> */
function eventPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Friday night Helldivers',
        'game' => 'Helldivers 2',
        'description' => 'Bring stims.',
        'starts_at' => '2026-07-15 20:00',
        'ends_at' => '2026-07-15 22:00',
        'timezone' => 'Europe/London',
        'location' => 'Voice: General',
        'capacity' => 4,
    ], $overrides);
}

it('creates an event as a draft so nothing publishes itself', function () {
    $this->actingAs($this->moderator)
        ->postJson(route('events.store'), eventPayload())
        ->assertStatus(201)
        ->assertJsonPath('data.status', EventStatus::Draft->value);

    expect(Event::query()->firstOrFail()->created_by)->toBe($this->moderator->id);
});

it('refuses a create from a member', function () {
    $this->actingAs($this->member)
        ->postJson(route('events.store'), eventPayload())
        ->assertForbidden();

    expect(Event::query()->count())->toBe(0);
});

it('refuses a create from a guest', function () {
    $this->postJson(route('events.store'), eventPayload())->assertUnauthorized();
});

it('publishes and cancels through the route, by event key not id', function () {
    $event = Event::factory()->create(['status' => EventStatus::Draft]);

    $this->actingAs($this->moderator)
        ->postJson(route('events.publish', $event))
        ->assertOk()
        ->assertJsonPath('data.status', EventStatus::Published->value)
        ->assertJsonPath('data.event_key', $event->event_key);

    expect($event->fresh()?->status)->toBe(EventStatus::Published);

    $this->actingAs($this->moderator)
        ->postJson(route('events.cancel', $event))
        ->assertOk()
        ->assertJsonPath('data.status', EventStatus::Cancelled->value);

    expect($event->fresh()?->status)->toBe(EventStatus::Cancelled);
});

it('never exposes the autoincrement id in a route', function () {
    $event = Event::factory()->create();

    expect(route('events.show', $event))->toContain($event->event_key)
        ->and(route('events.show', $event))->not->toContain('/'.$event->id);
});

it('refuses a publish or a cancel from a member', function (string $route) {
    $event = Event::factory()->create(['status' => EventStatus::Draft]);

    $this->actingAs($this->member)->postJson(route($route, $event))->assertForbidden();

    expect($event->fresh()?->status)->toBe(EventStatus::Draft);
})->with(['events.publish', 'events.cancel']);

it('will not re-publish a cancelled event', function () {
    $event = Event::factory()->create(['status' => EventStatus::Cancelled]);

    $this->actingAs($this->moderator)
        ->postJson(route('events.publish', $event))
        ->assertStatus(409);
});

it('records an RSVP for the member who is signed in', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => 4]);

    // 201 on the first answer, 200 on a change: one answer per member per event, so
    // re-answering updates the same row rather than making a second one.
    $this->actingAs($this->member)
        ->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
        ->assertStatus(201)
        ->assertJsonPath('data.status', RsvpStatus::Going->value)
        // Committed here, not in Discord yet — and the response says so rather than
        // claiming a Discord event that does not exist.
        ->assertJsonPath('data.synced_to_discord_at', null);

    $this->actingAs($this->member)
        ->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Maybe->value])
        ->assertStatus(200)
        ->assertJsonPath('data.status', RsvpStatus::Maybe->value);

    expect(Rsvp::query()->where('user_id', $this->member->id)->count())->toBe(1);
});

it('refuses an RSVP made on another members behalf', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    $other = User::factory()->create();

    $this->actingAs($this->member)
        ->putJson(route('events.rsvp.update', $event), [
            'status' => RsvpStatus::Going->value,
            'user_id' => $other->id,
        ])
        ->assertForbidden();

    expect(Rsvp::query()->count())->toBe(0);
});

it('answers a full event with a 409 and not a 500', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => 1]);
    Rsvp::factory()->for($event)->create(['status' => RsvpStatus::Going]);

    $this->actingAs($this->member)
        ->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
        ->assertStatus(409)
        ->assertJsonPath('reason', 'event_at_capacity');
});

it('still takes a maybe for a full event, because maybe is not a seat', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => 1]);
    Rsvp::factory()->for($event)->create(['status' => RsvpStatus::Going]);

    $this->actingAs($this->member)
        ->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Maybe->value])
        ->assertSuccessful();
});

it('withdraws an RSVP', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);
    Rsvp::factory()->for($event)->for($this->member)->create();

    $this->actingAs($this->member)
        ->deleteJson(route('events.rsvp.destroy', $event))
        ->assertNoContent();

    expect(Rsvp::query()->count())->toBe(0);
});

it('shares one member-safe limit across HTTP RSVP writes and recovers when it expires', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => null]);
    $anotherEvent = Event::factory()->create(['status' => EventStatus::Published, 'capacity' => null]);
    $otherMember = User::factory()->create(['is_moderator' => false]);

    $this->freezeTime();

    for ($attempt = 0; $attempt < RsvpRateLimit::MAX_ATTEMPTS; $attempt++) {
        $route = $attempt % 2 === 0 ? $event : $anotherEvent;
        $status = $attempt % 2 === 0 ? RsvpStatus::Going : RsvpStatus::Maybe;

        $this->actingAs($this->member)
            ->putJson(route('events.rsvp.update', $route), ['status' => $status->value])
            ->assertSuccessful();
    }

    $limited = $this->actingAs($this->member)
        ->deleteJson(route('events.rsvp.destroy', $event))
        ->assertStatus(429)
        ->assertHeader('Retry-After', RsvpRateLimit::DECAY_SECONDS);

    expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThan(0);

    // A busy household or community-space IP must not make members share a bucket.
    $this->actingAs($otherMember)
        ->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
        ->assertSuccessful();

    $this->travel(RsvpRateLimit::DECAY_SECONDS + 1)->seconds();

    $this->actingAs($this->member)
        ->deleteJson(route('events.rsvp.destroy', $event))
        ->assertNoContent();
});

it('queues the Discord write-back rather than calling the bot in the request', function () {
    Queue::fake();

    $event = Event::factory()->create(['status' => EventStatus::Published]);

    app(EventService::class)->rsvp($event, $this->member, RsvpStatus::Going);

    Queue::assertPushed(
        SyncEventToDiscord::class,
        fn (SyncEventToDiscord $job): bool => $job->eventKey === $event->event_key && $job->afterCommit === true,
    );
});

it('coalesces a burst of changes into one write-back per event', function () {
    Queue::fake();

    $event = Event::factory()->create(['status' => EventStatus::Draft]);
    $service = app(EventService::class);

    // Publishing and then cancelling within the debounce window is two changes to
    // the same Discord event. Twenty members answering in a minute is twenty. Both
    // must be one edit: the bot's budget is 60 requests a minute for the whole
    // site, and one popular event would otherwise spend a third of it.
    $service->publish($event);
    $service->cancel($event->refresh());

    Queue::assertPushed(SyncEventToDiscord::class, 1);

    // Which is only safe because the job carries the key and re-reads: the single
    // surviving write-back sends whatever the event says when it runs, not a
    // snapshot taken when it was queued.
    expect((new SyncEventToDiscord($event->event_key))->uniqueId())->toBe($event->event_key);
});

it('does not dispatch a write-back for a draft, which Discord has never seen', function () {
    Queue::fake();

    app(EventService::class)->create($this->moderator, EventInput::fromValidated(eventPayload()));

    Queue::assertNothingPushed();
});

it('dispatches a write-back when an event is cancelled', function () {
    Queue::fake();

    $event = Event::factory()->create(['status' => EventStatus::Published]);

    app(EventService::class)->cancel($event);

    Queue::assertPushed(SyncEventToDiscord::class, 1);
});
