<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Exceptions\EventAtCapacityException;
use App\Livewire\RsvpButton;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\Bot\Exceptions\BotTransportException;
use App\Services\EventService;
use App\Support\RsvpRateLimit;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * The RSVP control, and every way it can end.
 *
 * The rule this file exists to hold, from the TOG-52 hand-off: **the member's
 * answer is committed before Discord is ever contacted**, so a Discord problem is
 * never an RSVP failure. A test that lets those two blur is how the page ends up
 * telling somebody their RSVP failed when it is sitting in the database.
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
   Not signed in
   --------------------------------------------------------------------------- */

it('asks a guest to log in rather than showing a button that cannot work', function () {
    Livewire::test(RsvpButton::class, ['event' => $this->event])
        ->assertSee('Log in with Discord')
        ->assertDontSeeHtml('data-testid="rsvp-going"');
});

/* ---------------------------------------------------------------------------
   The happy path
   --------------------------------------------------------------------------- */

it('offers the RSVP in the words the copy spec uses', function () {
    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->assertSeeHtml('data-testid="rsvp-going"')
        ->assertSee("I'm in", false);
});

it('records the RSVP and says you are in', function () {
    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertSee("You're in", false)
        ->assertSeeHtml('data-testid="rsvp-confirmed"');

    expect(Rsvp::query()->where('user_id', $this->member->id)->first()?->status)
        ->toBe(RsvpStatus::Going);
});

it('announces the confirmation politely, not as an alert', function () {
    // ACCESSIBILITY.md 4.1.3: role="status" for the confirmation, never role="alert".
    $html = Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', RsvpStatus::Going->value)
        ->html();

    expect($html)->toContain('role="status"')->not->toContain('role="alert"');
});

it('carries the confirmation with a word and a mark, not a colour change alone', function () {
    // COPY.md: "You're in (with a check, not a colour change alone)".
    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertSee("You're in", false)
        ->assertSeeHtml('data-testid="rsvp-check"');
});

it('lets a member stand down again', function () {
    Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => RsvpStatus::Going,
    ]);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->assertSee("Can't make it", false)
        ->call('withdraw')
        ->assertSee("I'm in", false);

    expect(Rsvp::query()->where('user_id', $this->member->id)->exists())->toBeFalse();
});

it('shows the answer just given, not the one the page was loaded with', function () {
    // The events page eager-loads `viewerRsvps` so twelve cards are not twelve
    // queries. That cache has to be dropped on write, or the re-render after the
    // click serves the state from before it and the member's RSVP looks lost.
    $this->actingAs($this->member);
    $event = Event::query()->with('viewerRsvps')->findOrFail($this->event->id);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $event])
        ->assertSeeHtml('data-testid="rsvp-going"')
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertSeeHtml('data-testid="rsvp-confirmed"')
        ->assertSee("You're in", false);
});

it('reads the eager-loaded answer rather than asking again per card', function () {
    Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => RsvpStatus::Going,
    ]);

    $this->actingAs($this->member);
    $event = Event::query()->with('viewerRsvps')->findOrFail($this->event->id);
    expect($event->relationLoaded('viewerRsvps'))->toBeTrue();

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $event])
        ->assertSee("You're in", false);
});

it('never serves one member the answer belonging to another', function () {
    // `viewerRsvps` binds to whoever was authenticated when the query was built.
    // If that is ever not the person rendering, the rows must be ignored, not shown.
    $other = User::factory()->create();
    Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $other->id,
        'status' => RsvpStatus::Going,
    ]);

    $this->actingAs($other);
    $event = Event::query()->with('viewerRsvps')->findOrFail($this->event->id);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $event])
        ->assertDontSee("You're in", false)
        ->assertSee("I'm in", false);
});

/* ---------------------------------------------------------------------------
   Discord lag is not a failure
   --------------------------------------------------------------------------- */

it('tells the member the answer is saved while Discord catches up', function () {
    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertSeeHtml('data-testid="rsvp-syncing"')
        ->assertSee('Saved. Syncing to Discord.')
        // The one thing this must never be.
        ->assertDontSee("That RSVP didn't save.", false);
});

it('drops the syncing note once the write-back has landed', function () {
    Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => RsvpStatus::Going,
        'synced_to_discord_at' => now(),
    ]);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->assertSee("You're in", false)
        ->assertSeeHtml('data-testid="rsvp-synced"')
        ->assertSee('Synced to Discord.')
        ->assertDontSeeHtml('data-testid="rsvp-syncing"');
});

/* ---------------------------------------------------------------------------
   Full
   --------------------------------------------------------------------------- */

it('shows the honest full message when the last seat goes to somebody else', function () {
    // The race is real and the backend types the refusal. This is the loser's view.
    $this->mock(EventService::class)
        ->shouldReceive('rsvp')
        ->andThrow(new EventAtCapacityException($this->event));

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertSee("This one's full.", false)
        ->assertSee('Cap is 4.')
        // Not a retry spinner, and not a generic failure: this will not succeed later.
        ->assertDontSee("That RSVP didn't save.", false)
        ->assertDontSeeHtml('data-testid="rsvp-going"');
});

it('shows a full event as full before the member ever clicks', function () {
    $full = Event::factory()->create([
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
        'capacity' => 1,
    ]);
    Rsvp::factory()->create(['event_id' => $full->id, 'status' => RsvpStatus::Going]);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $full])
        ->assertSee("This one's full.", false)
        ->assertDontSeeHtml('data-testid="rsvp-going"');
});

it('still lets somebody already going stand down from a full event', function () {
    $full = Event::factory()->create([
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
        'capacity' => 1,
    ]);
    Rsvp::factory()->create([
        'event_id' => $full->id,
        'user_id' => $this->member->id,
        'status' => RsvpStatus::Going,
    ]);

    // Full and you are the reason it is full. Trapping them here is the bug.
    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $full])
        ->assertSee("Can't make it", false);
});

/* ---------------------------------------------------------------------------
   Failure
   --------------------------------------------------------------------------- */

it('tells the member the RSVP did not save when the write genuinely fails', function () {
    $this->mock(EventService::class)
        ->shouldReceive('rsvp')
        ->andThrow(new BotTransportException('the bot is unreachable'));

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertSee("That RSVP didn't save.", false)
        ->assertSee('Try once more.')
        // COMPONENTS.md §1.1: the button returns to default and stays usable.
        ->assertSeeHtml('data-testid="rsvp-going"');
});

it('does not leak the internal reason to the member', function () {
    $this->mock(EventService::class)
        ->shouldReceive('rsvp')
        ->andThrow(new BotTransportException('connect() failed: 10.0.0.4:8081'));

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertDontSee('10.0.0.4')
        ->assertDontSee('connect()');
});

it('announces a failure as an alert, because it interrupted what they were doing', function () {
    $this->mock(EventService::class)
        ->shouldReceive('rsvp')
        ->andThrow(new BotTransportException('down'));

    $html = Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', RsvpStatus::Going->value)
        ->html();

    expect($html)->toContain('role="alert"');
});

it('clears a previous failure once the retry works', function () {
    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->set('failed', true)
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertDontSee("That RSVP didn't save.", false)
        ->assertSee("You're in", false);
});

it('shares the HTTP RSVP allowance and returns Retry-After from a limited Livewire action', function () {
    $this->freezeTime();

    for ($attempt = 0; $attempt < RsvpRateLimit::MAX_ATTEMPTS; $attempt++) {
        $this->actingAs($this->member)
            ->putJson(route('events.rsvp.update', $this->event), [
                'status' => RsvpStatus::Going->value,
            ])
            ->assertSuccessful();
    }

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('withdraw')
        ->assertStatus(429)
        ->assertHeader('Retry-After', RsvpRateLimit::DECAY_SECONDS);

    $this->travel(RsvpRateLimit::DECAY_SECONDS + 1)->seconds();

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('withdraw')
        ->assertSee("I'm in", false);
});

/* ---------------------------------------------------------------------------
   Closed events
   --------------------------------------------------------------------------- */

it('does not offer an RSVP on a cancelled event', function () {
    $this->event->update(['status' => EventStatus::Cancelled]);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event->fresh()])
        ->assertSee('Cancelled')
        ->assertDontSeeHtml('data-testid="rsvp-going"');
});

it('does not offer an RSVP on an event that has already happened', function () {
    $this->event->update(['status' => EventStatus::Past]);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event->fresh()])
        ->assertDontSeeHtml('data-testid="rsvp-going"');
});

/* ---------------------------------------------------------------------------
   The loading state. It is a real requirement — "an honest loading state" — and
   in Livewire it is `wire:loading` markup, which is asserted as markup.
   --------------------------------------------------------------------------- */

it('reserves the loading state on the control itself', function () {
    $html = Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->html();

    expect($html)
        ->toContain('wire:loading')
        ->toContain('wire:target="rsvp"')
        // COMPONENTS.md §1.1 loading row: the progress verb, and aria-busy.
        ->toContain('Saving…')
        ->toContain('aria-busy');
});

it('disables the control while the answer is in flight so it cannot be double-sent', function () {
    $html = Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->html();

    expect($html)->toContain('wire:loading.attr="disabled"');
});
