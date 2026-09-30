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
   Answers that do not hold a seat
   --------------------------------------------------------------------------- */

it('shows the JSON answer without offering an unnoticed seat-taking upgrade', function (RsvpStatus $status, string $copy, bool $full, bool $stamped) {
    if ($full) {
        $this->event->update(['capacity' => 1]);
        Rsvp::factory()->create(['event_id' => $this->event->id, 'status' => RsvpStatus::Going]);
    }

    $this->actingAs($this->member)
        ->putJson(route('events.rsvp.update', $this->event), ['status' => $status->value])
        ->assertSuccessful();

    // The stamp a metadata write-back leaves on the row: it must not change
    // the copy, because it is not proof this answer was mirrored (TOG-8826).
    if ($stamped) {
        Rsvp::query()->where('event_id', $this->event->id)->where('user_id', $this->member->id)
            ->update(['synced_to_discord_at' => now()]);
    }

    $component = Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event->fresh()])
        ->assertSee($copy)
        ->assertSeeHtml('data-testid="rsvp-answer"')
        ->assertSee('Remove answer')
        ->assertSeeHtml('data-testid="rsvp-withdraw"')
        ->assertDontSeeHtml('data-testid="rsvp-going"')
        ->assertDontSeeHtml('data-testid="waitlist-join"')
        ->assertDontSeeHtml('data-testid="event-full"')
        ->assertDontSeeHtml('data-testid="rsvp-confirmed"');

    // TOG-8826: a Maybe/NotGoing answer is saved here and never mirrored —
    // `event.upsert` carries event metadata only — so it must never claim a
    // Discord sync, stamped or not.
    $component->assertSeeHtml('data-testid="rsvp-saved"')
        ->assertSee('Your answer is saved here.')
        ->assertDontSeeHtml('data-testid="rsvp-syncing"')
        ->assertDontSeeHtml('data-testid="rsvp-synced"')
        ->assertDontSeeHtml('data-testid="rsvp-sync-failed"');

    expect(Rsvp::query()->where('event_id', $this->event->id)->where('user_id', $this->member->id)->sole()->status)
        ->toBe($status);
})->with([
    'maybe' => [RsvpStatus::Maybe, "You're a maybe"],
    'not going' => [RsvpStatus::NotGoing, "You're not going"],
])->with(['room available' => false, 'full' => true])
    ->with(['unstamped' => false, 'stamped' => true]);

it('shows an eager-loaded non-seat answer honestly', function (RsvpStatus $status, string $copy) {
    Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => $status,
    ]);

    $this->actingAs($this->member);
    $event = Event::query()->with('viewerRsvps')->findOrFail($this->event->id);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $event])
        ->assertSee($copy)
        ->assertSee('Remove answer')
        ->assertSeeHtml('data-testid="rsvp-saved"')
        ->assertSee('Your answer is saved here.')
        ->assertDontSeeHtml('data-testid="rsvp-going"')
        ->assertDontSeeHtml('data-testid="rsvp-syncing"')
        ->assertDontSeeHtml('data-testid="rsvp-synced"')
        ->assertDontSeeHtml('data-testid="rsvp-sync-failed"');
})->with([
    'maybe' => [RsvpStatus::Maybe, "You're a maybe"],
    'not going' => [RsvpStatus::NotGoing, "You're not going"],
]);

it('lets a member remove a non-seat answer even when the event is full', function (RsvpStatus $status, bool $full) {
    if ($full) {
        $this->event->update(['capacity' => 1]);
        Rsvp::factory()->create(['event_id' => $this->event->id, 'status' => RsvpStatus::Going]);
    }

    Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => $status,
    ]);

    $component = Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event->fresh()])
        ->assertSee('Remove answer')
        ->call('withdraw')
        ->assertDispatched('rsvp-state-changed')
        ->assertDontSeeHtml('data-testid="rsvp-answer"')
        ->assertDontSeeHtml('data-testid="rsvp-withdraw"');

    $component->assertSeeHtml($full ? 'data-testid="waitlist-join"' : 'data-testid="rsvp-going"');

    expect(Rsvp::query()->where('event_id', $this->event->id)->where('user_id', $this->member->id)->exists())
        ->toBeFalse();
})->with([RsvpStatus::Maybe, RsvpStatus::NotGoing])
    ->with(['room available' => false, 'full' => true]);

it('keeps a non-seat answer and its remove control when withdrawal fails', function (RsvpStatus $status, string $copy) {
    Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => $status,
    ]);
    $this->mock(EventService::class)->shouldReceive('withdrawRsvp')->andThrow(new BotTransportException('down'));

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('withdraw')
        ->assertSee($copy)
        ->assertSee('Remove answer')
        ->assertSeeHtml('data-testid="rsvp-failed"')
        ->assertDontSeeHtml('data-testid="rsvp-going"')
        ->assertNotDispatched('rsvp-state-changed');

    expect(Rsvp::query()->where('event_id', $this->event->id)->where('user_id', $this->member->id)->sole()->status)
        ->toBe($status);
})->with([
    'maybe' => [RsvpStatus::Maybe, "You're a maybe"],
    'not going' => [RsvpStatus::NotGoing, "You're not going"],
]);

it('keeps non-seat answers removable while RSVPs are paused', function (RsvpStatus $status, bool $full) {
    if ($full) {
        $this->event->update(['capacity' => 1]);
        Rsvp::factory()->create(['event_id' => $this->event->id, 'status' => RsvpStatus::Going]);
    }

    Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => $status,
    ]);
    $this->event->update(['rsvp_open' => false]);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event->fresh()])
        ->assertSeeHtml('data-testid="rsvp-answer"')
        ->assertSee('Remove answer')
        ->assertDontSeeHtml('data-testid="rsvp-paused"')
        ->assertDontSeeHtml('data-testid="rsvp-going"')
        ->call('withdraw')
        ->assertDispatched('rsvp-state-changed')
        ->assertDontSeeHtml('data-testid="rsvp-answer"')
        ->assertSeeHtml('data-testid="rsvp-paused"')
        // TOG-8826: removing an answer while paused swaps the controls for
        // the notice alone — the same focus loss as a successful RSVP — so
        // the notice must be focusable for the rsvp-state-changed handler.
        ->assertSeeHtml('role="status" tabindex="-1" data-testid="rsvp-paused"');

    expect(Rsvp::query()->where('event_id', $this->event->id)->where('user_id', $this->member->id)->exists())
        ->toBeFalse();
})->with([RsvpStatus::Maybe, RsvpStatus::NotGoing])
    ->with(['room available' => false, 'full' => true]);

it('never describes a non-seat answer as a Discord sync outcome, even when the mirror failed', function (RsvpStatus $status) {
    // TOG-8826: a terminally-refused mirror concerns the aggregate metadata
    // write, not this answer — a Maybe was never in the payload — so the row
    // must neither claim a sync nor deny one. It is simply saved here.
    Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => $status,
        'synced_to_discord_at' => null,
    ]);
    $this->event->update(['discord_sync_failed_at' => now()]);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event->fresh()])
        ->assertSeeHtml('data-testid="rsvp-answer"')
        ->assertSeeHtml('data-testid="rsvp-saved"')
        ->assertSee('Your answer is saved here.')
        ->assertDontSeeHtml('data-testid="rsvp-syncing"')
        ->assertDontSeeHtml('data-testid="rsvp-synced"')
        ->assertDontSeeHtml('data-testid="rsvp-sync-failed"');
})->with([RsvpStatus::Maybe, RsvpStatus::NotGoing]);

it('offers a focusable answer after a non-seat Livewire write', function (RsvpStatus $status) {
    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', $status->value)
        ->assertDispatched('rsvp-state-changed')
        ->assertSeeHtml('role="status" tabindex="-1" data-testid="rsvp-answer"')
        ->assertSee('Remove answer')
        ->assertDontSeeHtml('data-testid="rsvp-going"');
})->with([RsvpStatus::Maybe, RsvpStatus::NotGoing]);

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

it('shows the closed copy when the last seat goes while the member is deciding', function () {
    // The stale-component edge, with no mocks: the button is offered because
    // there is room at render, the seats fill before the click, and the real
    // service refuses. The member must see "full", never a retryable failure.
    $component = Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->assertSeeHtml('data-testid="rsvp-going"');

    $takers = User::factory()->count(4)->create(['is_moderator' => false]);
    foreach ($takers as $taker) {
        app(EventService::class)->rsvp($this->event->fresh(), $taker, RsvpStatus::Going);
    }

    $component->call('rsvp', RsvpStatus::Going->value)
        ->assertSee("This one's full.", false)
        ->assertSee('Cap is 4.')
        // Closed, not failed: nothing here may invite a retry that cannot succeed.
        ->assertDontSee("That RSVP didn't save.", false)
        ->assertDontSee('Try once more.', false)
        ->assertDontSeeHtml('data-testid="rsvp-going"')
        ->assertDontSeeHtml('data-testid="rsvp-failed"');

    // And nothing was saved for the loser of the race.
    expect(Rsvp::query()->where('user_id', $this->member->id)->exists())->toBeFalse();
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

it('announces the throttle wait when the shared write budget is spent', function () {
    // TOG-7976: a throttled Livewire click used to rethrow the
    // ThrottleRequestsException, so the member got Livewire's silent failure
    // modal instead of words. The budget is still shared with the JSON routes
    // (the HTTP 429 envelope below is unchanged); only the control now speaks.
    $this->freezeTime();

    for ($attempt = 0; $attempt < RsvpRateLimit::MAX_ATTEMPTS; $attempt++) {
        $this->actingAs($this->member)
            ->putJson(route('events.rsvp.update', $this->event), [
                'status' => RsvpStatus::Going->value,
            ])
            ->assertSuccessful();
    }

    // The JSON route still refuses with the shared 429 envelope (TOG-6788).
    assertThrottleEnvelope(
        $this->actingAs($this->member)
            ->putJson(route('events.rsvp.update', $this->event), [
                'status' => RsvpStatus::Going->value,
            ]),
        RsvpRateLimit::DECAY_SECONDS,
    );

    // The member is going by now, so this is the withdraw path: the wait is
    // announced politely beside a control that stays usable.
    $html = Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('withdraw')
        ->assertStatus(200)
        ->assertSeeHtml('data-testid="rsvp-rate-limited"')
        ->assertSee('Slow down — try again in 60 seconds. Nothing changed, just wait a moment.', false)
        // COMPONENTS.md §1.1: the control returns to default and stays usable.
        ->assertSeeHtml('data-testid="rsvp-withdraw"')
        ->html();

    expect($html)->toContain('role="status"')->not->toContain('role="alert"');

    // Nothing was withdrawn by the throttled click.
    expect(Rsvp::query()->where('user_id', $this->member->id)->exists())->toBeTrue();

    // Past the decay the same click works and the wait is gone.
    $this->travel(RsvpRateLimit::DECAY_SECONDS + 1)->seconds();

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('withdraw')
        ->assertSee("I'm in", false)
        ->assertDontSeeHtml('data-testid="rsvp-rate-limited"');
});

it('announces the throttle wait on the join path without taking the button', function () {
    // Same announced node for rsvp(): the copy is neutral ("Nothing changed")
    // so one node covers both verbs.
    for ($attempt = 0; $attempt < RsvpRateLimit::MAX_ATTEMPTS; $attempt++) {
        RsvpRateLimit::hit($this->member);
    }

    $html = Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertStatus(200)
        ->assertSeeHtml('data-testid="rsvp-rate-limited"')
        ->assertSee('Slow down — try again in', false)
        ->assertSee('Nothing changed, just wait a moment.', false)
        ->assertSeeHtml('data-testid="rsvp-going"')
        ->assertDontSee("You're in", false)
        ->html();

    expect($html)->toContain('role="status"')->not->toContain('role="alert"');
    expect(Rsvp::query()->where('user_id', $this->member->id)->exists())->toBeFalse();
});

it('names one second and falls back when the wait has no number', function () {
    // {N} is the ceiling of Retry-After, min 1; an unusable header selects the
    // "in a moment" fallback instead of a number (TOG-7928 `copy` doc).
    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->set('rateLimited', true)
        ->set('retryAfterSeconds', 1)
        ->assertSee('Slow down — try again in 1 second. Nothing changed, just wait a moment.', false)
        ->set('retryAfterSeconds', null)
        ->assertSee('Slow down — try again in a moment. Nothing changed, just wait a bit.', false);
});

it('announces the closed and full states politely, not as alerts', function () {
    // TOG-7332: a cancellation landing while the member watches, or losing
    // the last-seat race after clicking, swaps these states in without a
    // reload — they must announce via role="status", never role="alert".
    $this->event->update(['status' => EventStatus::Cancelled]);

    $closed = Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event->fresh()])
        ->html();

    expect($closed)->toContain('role="status"')
        ->toContain('data-testid="rsvp-closed"')
        ->not->toContain('role="alert"');

    $full = Event::factory()->create([
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
        'capacity' => 1,
    ]);
    Rsvp::factory()->create(['event_id' => $full->id, 'status' => RsvpStatus::Going]);

    $html = Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $full])
        ->html();

    expect($html)->toContain('role="status"')
        ->toContain('data-testid="event-full"')
        ->not->toContain('role="alert"');
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
   Expired session (TOG-8135). The page was rendered signed in and the session
   died underneath it (SESSION_LIFETIME). The click arrives with nobody behind
   it — no user instance — so the component names the expiry and points at the
   way back in instead of returning silently. Distinct from $failed on purpose:
   retrying cannot succeed without logging in first.
   --------------------------------------------------------------------------- */

it('names the expired session with a way back in when the RSVP click arrives signed out', function () {
    Livewire::test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertSee('Your session expired.', false)
        ->assertSeeHtml('data-testid="rsvp-session-expired"')
        ->assertSee('Log in with Discord')
        // Not a retryable failure: nothing here may invite a retry that cannot help.
        ->assertDontSee("That RSVP didn't save.", false)
        ->assertDontSeeHtml('data-testid="rsvp-failed"');

    expect(Rsvp::query()->where('user_id', $this->member->id)->exists())->toBeFalse();
});

it('names the expired session when the withdraw click arrives signed out', function () {
    Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => RsvpStatus::Going,
    ]);

    Livewire::test(RsvpButton::class, ['event' => $this->event])
        ->call('withdraw')
        ->assertSee('Your session expired.', false)
        ->assertSeeHtml('data-testid="rsvp-session-expired"')
        ->assertDontSeeHtml('data-testid="rsvp-failed"');

    // The answer is untouched: nothing was withdrawn.
    expect(Rsvp::query()->where('user_id', $this->member->id)->exists())->toBeTrue();
});

it('announces the expired session as an alert, because it interrupted what they were doing', function () {
    $html = Livewire::test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', RsvpStatus::Going->value)
        ->html();

    expect($html)->toContain('role="alert"')
        ->toContain('data-testid="rsvp-session-expired"');
});

/* ---------------------------------------------------------------------------
   Expired-session 419 interceptor (TOG-9354). The sessionExpired branch above
   only runs when the round trip reaches the component — but a dead session
   419s in ValidateCsrfToken first (stale data-csrf against a fresh session),
   so Livewire's handlePageExpiry answers with a native confirm() and the
   banner stays unreachable. The blade intercepts the 419 per-component and
   reloads into the guest render, which carries the same login link with the
   ?next= return. Pinned as shipped markup (like DeferredPrebootGuardTest):
   the browser behaviour itself is Dusk's ground in EventsRsvpTest.
   --------------------------------------------------------------------------- */

it('ships the expired-session 419 interceptor on the events page', function () {
    $html = (string) $this->actingAs($this->member)
        ->get(route('events.index'))
        ->assertOk()
        ->getContent();

    // Non-vacuous: the signed-in member is offered the control the hook guards.
    expect($html)->toContain('data-testid="rsvp-going"');

    // The @script block travels inside the wire:effects JSON attribute, which
    // is HTML-escaped — quotes render as &#039;, `>` as `&gt;` — so the hook
    // name is pinned in its escaped form, not the blade source form.
    expect($html)->toContain('$wire.$hook(')
        ->and($html)->toContain('&#039;request&#039;')
        ->and($html)->toContain('status !== 419')
        ->and($html)->toContain('preventDefault()')
        ->and($html)->toContain('window.location.reload()');
});

it('scopes the interceptor to 419s so other failures keep the failure modal', function () {
    $html = (string) $this->actingAs($this->member)
        ->get(route('events.index'))
        ->assertOk()
        ->getContent();

    $guard = strpos($html, 'status !== 419');
    $prevent = strpos($html, 'preventDefault()');

    expect($guard)->not->toBeFalse('interceptor 419 guard missing from events page')
        ->and($prevent)->not->toBeFalse('interceptor preventDefault missing from events page');

    // The early return stands before the prevention: a non-419 failure never
    // reaches preventDefault and keeps Livewire's failure modal.
    expect($prevent)->toBeGreaterThan($guard);
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

it('leaves exactly one RSVP row when the button is fired twice', function () {
    // The double-click: `wire:loading.attr="disabled"` stops the second request
    // in the browser, and `updateOrCreate` behind the unique(event_id, user_id)
    // index makes a second request that does arrive idempotent. Either way the
    // member ends up with one answer, not two rows.
    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', RsvpStatus::Going->value)
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertSee("You're in", false);

    expect(Rsvp::query()->where('user_id', $this->member->id)->count())->toBe(1);
});

it('gives the withdraw control the same in-flight treatment as the RSVP', function () {
    Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => RsvpStatus::Going,
    ]);

    $html = Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->html();

    // The wait copy itself, hidden up front (TOG-6351) and busy while shown.
    // Not a bare `aria-busy` check: the button's static `aria-busy="false"`
    // would pass that without any loading copy at all. Main landed this
    // contract first as "Removing…"; this slice's duplicate spinner and its
    // separate copy are gone, and this test pins the contract that survived.
    expect($html)
        ->toContain('wire:target="withdraw"')
        ->toContain('<span wire:loading wire:target="withdraw" aria-busy="true" style="display: none">Removing…</span>')
        ->toContain("Can't make it");
});

/* ---------------------------------------------------------------------------
   Focus after the re-render (TOG-6956). A successful RSVP or withdraw swaps
   the focused control for its replacement, which drops keyboard focus to
   <body>. The component dispatches to itself on success so the view can move
   focus to the new state; on failure the button stays put, so no dispatch.
   --------------------------------------------------------------------------- */

it('dispatches a focus event to itself after a successful RSVP', function () {
    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertDispatched('rsvp-state-changed');
});

it('dispatches a focus event to itself after a successful withdraw', function () {
    Rsvp::factory()->create([
        'event_id' => $this->event->id,
        'user_id' => $this->member->id,
        'status' => RsvpStatus::Going,
    ]);

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('withdraw')
        ->assertDispatched('rsvp-state-changed');
});

it('does not dispatch the focus event when the write fails', function () {
    // Failure keeps the button in place, so focus is already where it belongs.
    $this->mock(EventService::class)
        ->shouldReceive('rsvp')
        ->andThrow(new BotTransportException('down'));

    Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', RsvpStatus::Going->value)
        ->assertNotDispatched('rsvp-state-changed');
});

it('makes the confirmation focusable so keyboard focus can move there', function () {
    // tabindex="-1": out of the tab order, but focus() works after the swap.
    $html = Livewire::actingAs($this->member)
        ->test(RsvpButton::class, ['event' => $this->event])
        ->call('rsvp', RsvpStatus::Going->value)
        ->html();

    expect($html)->toContain('tabindex="-1"');
});
