<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Livewire\GoingCount;
use App\Livewire\RsvpButton;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * The "N of M going" badge after a write (TOG-7966).
 *
 * The badge lives outside RsvpButton, so a successful RSVP/withdraw left it
 * showing the pre-click number until a full reload. RsvpButton now broadcasts
 * `going-count-updated` and GoingCount re-reads the aggregate for that event.
 * These tests pin both halves: the broadcast carries the right event and
 * viewer state, and the badge follows it — politely, via role="status".
 */
beforeEach(function () {
    Queue::fake();

    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->event = Event::factory()->create([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
        'capacity' => 4,
    ]);
});

/* ---------------------------------------------------------------------------
   Initial render: same numbers the static badge printed
   --------------------------------------------------------------------------- */

it('renders the going count against the cap', function () {
    Rsvp::factory()->count(2)->create(['event_id' => $this->event->id, 'status' => RsvpStatus::Going]);

    Livewire::test(GoingCount::class, ['event' => $this->event->fresh()])
        ->assertSee('2 of 4 going')
        ->assertSeeHtml('data-testid="event-going-count"');
});

it('renders the going count without inventing a cap', function () {
    $this->event->update(['capacity' => null]);
    Rsvp::factory()->create(['event_id' => $this->event->id, 'status' => RsvpStatus::Going]);

    Livewire::test(GoingCount::class, ['event' => $this->event->fresh()])
        ->assertSee('1 going')
        ->assertDontSee('1 of');
});

it('announces count changes politely, never as an alert', function () {
    $html = Livewire::test(GoingCount::class, ['event' => $this->event])
        ->html();

    expect($html)->toContain('role="status"')->not->toContain('role="alert"');
});

it('announces nothing on first render, so page load stays quiet', function () {
    // The sr-only prefix names the write that just happened. Before any write
    // there is nothing to name, so no prefix — otherwise every badge on the
    // calendar would announce on page load.
    $html = Livewire::test(GoingCount::class, ['event' => $this->event])
        ->html();

    expect($html)->not->toContain('sr-only');
});

/* ---------------------------------------------------------------------------
   The refresh: re-reads the aggregate for exactly that event
   --------------------------------------------------------------------------- */

it('re-reads the aggregate when its own event is answered', function () {
    $component = Livewire::test(GoingCount::class, ['event' => $this->event])
        ->assertSee('0 of 4 going');

    Rsvp::factory()->create(['event_id' => $this->event->id, 'status' => RsvpStatus::Going]);

    $component->call('refreshCount', eventKey: $this->event->event_key, viewerState: 'going')
        ->assertSee('1 of 4 going')
        ->assertSee("You're going.", false);
});

it('follows a withdraw down as well as an RSVP up', function () {
    Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => RsvpStatus::Going,
    ]);

    $component = Livewire::test(GoingCount::class, ['event' => $this->event->fresh()])
        ->assertSee('1 of 4 going');

    Rsvp::query()->where('user_id', $this->member->id)->delete();

    $component->call('refreshCount', eventKey: $this->event->event_key, viewerState: 'none')
        ->assertSee('0 of 4 going')
        ->assertSee('RSVP removed.', false);
});

it('ignores answers to other events on the same page', function () {
    $other = Event::factory()->create([
        'starts_at' => now()->addDays(4),
        'ends_at' => now()->addDays(4)->addHours(2),
        'status' => EventStatus::Published,
        'capacity' => 4,
    ]);

    Livewire::test(GoingCount::class, ['event' => $this->event])
        ->call('refreshCount', eventKey: $other->event_key, viewerState: 'going')
        ->assertSee('0 of 4 going')
        ->assertDontSee("You're going.", false);
});

/* ---------------------------------------------------------------------------
   The broadcast: RsvpButton tells the badge after every successful write
   --------------------------------------------------------------------------- */

it('broadcasts the going-count update after a successful RSVP', function () {
    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertDispatched('going-count-updated', eventKey: $this->event->event_key, viewerState: 'going');
});

it('broadcasts the going-count update after a successful withdraw', function () {
    Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => RsvpStatus::Going,
    ]);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('withdraw')
        ->assertDispatched('going-count-updated', eventKey: $this->event->event_key, viewerState: 'none');
});
