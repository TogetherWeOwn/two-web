<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Exceptions\EventNotOpenException;
use App\Livewire\RsvpButton;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\EventService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * The RSVP pause (TOG-8725): moderators stop new answers without
 * unpublishing — the event stays published and visible, but the gate refuses
 * while it is closed.
 *
 * Four things this file holds that would otherwise drift:
 *  - a pause is moderator-only, through the same policy verb the routes use;
 *  - a closed event refuses every answer kind over HTTP (403 at the gate,
 *    friendly copy in the service's 409);
 *  - the write-path refusal carries its own reason (`rsvp_closed`), so
 *    clients can tell "paused" apart from "called off";
 *  - pausing never traps a member: withdrawals and the waitlist way out
 *    keep working, and the line freezes rather than dealing seats mid-pause.
 */
beforeEach(function () {
    Queue::fake();

    $this->moderator = User::factory()->create(['is_moderator' => true]);
    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->event = Event::factory()->create([
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ]);
});

function pausedEvent(Event $event): Event
{
    return tap($event->fresh(), fn (Event $fresh) => app(EventService::class)->setRsvpOpen($fresh, false));
}

/* ---------------------------------------------------------------------------
   The gate
   --------------------------------------------------------------------------- */

it('lets only a moderator pause or reopen answers', function () {
    expect(Gate::forUser($this->moderator)->allows('toggleRsvp', $this->event))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('toggleRsvp', $this->event))->toBeFalse();
});

it('refuses the policy gate for a closed event over HTTP, for every answer kind', function (RsvpStatus $status) {
    $event = pausedEvent($this->event);

    $this->actingAs($this->member)
        ->putJson(route('events.rsvp.update', $event), ['status' => $status->value])
        ->assertForbidden();

    expect(Rsvp::query()->count())->toBe(0);
})->with([
    'going' => RsvpStatus::Going,
    'maybe' => RsvpStatus::Maybe,
    'not going' => RsvpStatus::NotGoing,
    'waitlisted' => RsvpStatus::Waitlisted,
]);

/* ---------------------------------------------------------------------------
   The toggle routes
   --------------------------------------------------------------------------- */

it('pauses and reopens through the route, by event key', function () {
    $this->actingAs($this->moderator)
        ->postJson(route('events.rsvp.pause', $this->event))
        ->assertOk()
        ->assertJsonPath('data.rsvp_open', false);

    expect($this->event->fresh()?->isRsvpOpen())->toBeFalse();

    $this->actingAs($this->moderator)
        ->postJson(route('events.rsvp.reopen', $this->event))
        ->assertOk()
        ->assertJsonPath('data.rsvp_open', true);

    expect($this->event->fresh()?->isRsvpOpen())->toBeTrue();
});

it('refuses a pause or a reopen from a member', function (string $route) {
    $this->actingAs($this->member)->postJson(route($route, $this->event))->assertForbidden();

    expect($this->event->fresh()?->isRsvpOpen())->toBeTrue();
})->with(['events.rsvp.pause', 'events.rsvp.reopen']);

it('refuses a pause or a reopen from a guest', function (string $route) {
    $this->postJson(route($route, $this->event))->assertUnauthorized();
})->with(['events.rsvp.pause', 'events.rsvp.reopen']);

/* ---------------------------------------------------------------------------
   The write path: refusal with friendly copy
   --------------------------------------------------------------------------- */

it('answers 409 rsvp_closed with friendly copy for callers that reach the service past the gate', function () {
    $event = pausedEvent($this->event);

    $exception = null;

    try {
        app(EventService::class)->rsvp($event, $this->member, RsvpStatus::Going);
    } catch (EventNotOpenException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull()
        ->and($exception->reason)->toBe('rsvp_closed')
        ->and($exception->getMessage())->toBe('RSVPs are paused for this event — check back soon.')
        ->and(Rsvp::query()->count())->toBe(0);
});

it('still lets a member stand down from a paused event', function () {
    // A pause must never trap a member on an event: leaving is always allowed.
    $event = pausedEvent($this->event);
    Rsvp::factory()->for($event)->for($this->member, 'user')->create(['status' => RsvpStatus::Going]);

    $this->actingAs($this->member)
        ->deleteJson(route('events.rsvp.destroy', $event))
        ->assertNoContent();

    expect(Rsvp::query()->count())->toBe(0);
});

/* ---------------------------------------------------------------------------
   The waitlist while paused
   --------------------------------------------------------------------------- */

it('freezes the line while paused and settles freed seats to it on reopen', function () {
    $full = Event::factory()->create([
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
        'capacity' => 1,
    ]);
    $holder = User::factory()->create(['is_moderator' => false]);
    $inLine = User::factory()->create(['is_moderator' => false]);
    app(EventService::class)->rsvp($full, $holder, RsvpStatus::Going);
    app(EventService::class)->rsvp($full, $inLine, RsvpStatus::Waitlisted);

    app(EventService::class)->setRsvpOpen($full, false);

    // The holder stands down mid-pause: the seat stays free rather than
    // dealing to the line while the event claims to take no answers.
    app(EventService::class)->withdrawRsvp($full->fresh(), $holder);
    expect(Rsvp::query()->where('user_id', $inLine->id)->first()?->status)
        ->toBe(RsvpStatus::Waitlisted);

    // The reopen settles the backlog in the same locked write: the line head
    // takes the freed seat before any newcomer can.
    app(EventService::class)->setRsvpOpen($full->fresh(), true);
    expect(Rsvp::query()->where('user_id', $inLine->id)->first()?->status)->toBe(RsvpStatus::Going);
});

/* ---------------------------------------------------------------------------
   The member surface
   --------------------------------------------------------------------------- */

it('shows the paused copy instead of the button to a member with no stake', function () {
    $event = pausedEvent($this->event);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $event->fresh()])
        ->assertSee('RSVPs are paused for this event — check back soon.', false)
        ->assertSeeHtml('data-testid="rsvp-paused"')
        ->assertDontSeeHtml('data-testid="rsvp-going"')
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertSee('RSVPs are paused for this event — check back soon.', false)
        ->assertDontSee("You're in", false);

    expect(Rsvp::query()->count())->toBe(0);
});

it('announces the paused state politely, not as an alert', function () {
    $html = Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => pausedEvent($this->event)->fresh()])
        ->html();

    expect($html)->toContain('role="status"')
        ->toContain('data-testid="rsvp-paused"')
        ->not->toContain('role="alert"');
});

it('keeps the withdraw control for a seat holder on a paused event', function () {
    // Pausing must not strand a member behind the paused copy: the holder
    // keeps their confirmation and the way out of it.
    $event = pausedEvent($this->event);
    Rsvp::factory()->for($event)->for($this->member, 'user')->create(['status' => RsvpStatus::Going]);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $event->fresh()])
        ->assertDontSeeHtml('data-testid="rsvp-paused"')
        ->assertSeeHtml('data-testid="rsvp-withdraw"')
        ->call('withdraw')
        ->assertOk();

    expect(Rsvp::query()->count())->toBe(0);
});

it('keeps the line and the way out of it for a waitlisted member on a paused event', function () {
    $event = pausedEvent($this->event);
    Rsvp::factory()->for($event)->for($this->member, 'user')->create(['status' => RsvpStatus::Waitlisted]);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $event->fresh()])
        ->assertDontSeeHtml('data-testid="rsvp-paused"')
        ->assertSeeHtml('data-testid="waitlist-leave"')
        ->assertDontSeeHtml('data-testid="waitlist-claim"')
        ->call('withdraw')
        ->assertOk();

    expect(Rsvp::query()->count())->toBe(0);
});
