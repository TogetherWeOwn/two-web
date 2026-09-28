<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Livewire\RsvpButton;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Livewire\Livewire;

// TOG-7419: a clock-ended event still marked Published (reconcile flips finished
// rows to Past every ~10 min) must refuse RSVPs on the write path — the same
// answer the page shows ("This event has ended.", no RSVP button, TOG-7273).
// Status-Past is pinned in RsvpAbuseSurfaceTest; this file pins the open half.

beforeEach(function () {
    $this->member = User::factory()->create(['is_moderator' => false]);
});

function endedPublishedEvent(): Event
{
    return Event::factory()->create([
        'status' => EventStatus::Published,
        'starts_at' => now()->subHours(3),
        'ends_at' => now()->subHour(),
    ]);
}

it('refuses an HTTP RSVP on a clock-ended event still marked Published', function () {
    $event = endedPublishedEvent();

    $this->actingAs($this->member)
        ->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Going->value])
        ->assertForbidden();

    expect(Rsvp::query()->count())->toBe(0);
});

it('refuses a maybe as well, because the event is over either way', function () {
    $event = endedPublishedEvent();

    $this->actingAs($this->member)
        ->putJson(route('events.rsvp.update', $event), ['status' => RsvpStatus::Maybe->value])
        ->assertForbidden();

    expect(Rsvp::query()->count())->toBe(0);
});

it('writes nothing through the Livewire path and offers no button', function () {
    $event = endedPublishedEvent();

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $event->fresh()])
        ->assertDontSeeHtml('data-testid="rsvp-going"')
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertDontSeeHtml('data-testid="rsvp-going"')
        ->assertDontSee("You're in", false);

    expect(Rsvp::query()->count())->toBe(0);
});

it('still lets a member stand down from a clock-ended event', function () {
    // Withdraw is not gated on the clock: trapping a member on an event that
    // already happened because they cannot remove their own row would be the bug.
    $event = endedPublishedEvent();
    Rsvp::factory()->for($event)->for($this->member, 'user')->create(['status' => RsvpStatus::Going]);

    $this->actingAs($this->member)
        ->deleteJson(route('events.rsvp.destroy', $event))
        ->assertNoContent();

    expect(Rsvp::query()->count())->toBe(0);
});
