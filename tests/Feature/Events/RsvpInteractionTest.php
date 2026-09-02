<?php

use App\Enums\RsvpStatus;
use App\Livewire\EventsCalendar;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Services\EventService;
use Livewire\Livewire;

/**
 * The RSVP round trip, and the three ways it can end badly.
 *
 * The distinction this file exists to hold: **the bot being unreachable is not an
 * RSVP failure**. The row commits before the write-back is dispatched, and
 * SyncEventToDiscord releases a BotTransportException for retry rather than
 * failing it — so a member whose RSVP is saved but not yet mirrored has succeeded,
 * and telling them otherwise is a lie the backend went to some trouble to avoid.
 * An honest failure message is reserved for an answer that genuinely did not save.
 */
beforeEach(function (): void {
    $this->member = User::factory()->create();
});

it('records an RSVP and tells the member they are in', function (): void {
    $event = Event::factory()->create();

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->call('rsvp', $event->event_key)
        ->assertSee("You're in", escape: false);

    expect(Rsvp::query()->where('event_id', $event->id)->where('user_id', $this->member->id)->first())
        ->not->toBeNull()
        ->status->toBe(RsvpStatus::Going);
});

it('lets a member stand down again', function (): void {
    $event = Event::factory()->create();
    Rsvp::factory()->for($event)->for($this->member)->create();

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->call('withdraw', $event->event_key)
        ->assertDontSee("You're in", escape: false);

    expect(Rsvp::query()->where('event_id', $event->id)->where('user_id', $this->member->id)->exists())
        ->toBeFalse();
});

it('tells the loser of the last seat that it filled up, and does not offer a retry', function (): void {
    // The typed refusal from EventService. A member who lost the race is not
    // looking at a transient error, so a "try again" would be a lie: the seat is
    // gone and trying again produces the same 409.
    $event = Event::factory()->create(['capacity' => 1]);
    Rsvp::factory()->for($event)->create();

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->call('rsvp', $event->event_key)
        ->assertSee("This one's full", escape: false)
        ->assertDontSee('Try once more');

    expect(Rsvp::query()->where('event_id', $event->id)->where('user_id', $this->member->id)->exists())
        ->toBeFalse();
});

it('shows an honest failure when the answer genuinely did not save', function (): void {
    // A real write failure — not a Discord one. This is the only path that may
    // tell a member their RSVP did not work.
    $event = Event::factory()->create();

    $this->mock(EventService::class)
        ->shouldReceive('rsvp')
        ->andThrow(new RuntimeException('the database went away'));

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->call('rsvp', $event->event_key)
        ->assertSee("That RSVP didn't save", escape: false)
        ->assertDontSee("You're in", escape: false);
});

it('treats an RSVP that Discord has not seen yet as saved, never as failed', function (): void {
    // `synced_to_discord_at` is null the instant an RSVP is written, and stays
    // null for as long as the bot is unreachable. The member is in. Rendering
    // this as an error is the single most likely misreading of the backend
    // contract, so it is pinned here.
    $event = Event::factory()->create();

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->call('rsvp', $event->event_key)
        ->assertSee("You're in", escape: false)
        ->assertSee('Syncing to Discord')
        ->assertDontSee("didn't save", escape: false)
        ->assertDontSee('failed');

    expect(Rsvp::query()->where('event_id', $event->id)->first()->synced_to_discord_at)->toBeNull();
});

it('stops showing the syncing note once the bot has caught up', function (): void {
    $event = Event::factory()->create();
    Rsvp::factory()->for($event)->for($this->member)->create([
        'synced_to_discord_at' => now(),
    ]);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertSee("You're in", escape: false)
        ->assertDontSee('Syncing to Discord');
});

it('refuses an RSVP to an event that is not open, without calling it a failure', function (): void {
    // Cancelled or draft: "not now", not "not you" and not "it broke".
    $event = Event::factory()->create();
    app(EventService::class)->cancel($event);

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->call('rsvp', $event->event_key)
        ->assertDontSee("That RSVP didn't save", escape: false);

    expect(Rsvp::query()->where('event_id', $event->id)->exists())->toBeFalse();
});

it('carries the stable selectors QA drives the round trip with', function (): void {
    // These names are a contract with the Dusk journey. Renaming one is what
    // silently turns a green browser suite into one that tests nothing.
    $event = Event::factory()->create();

    Livewire::actingAs($this->member)
        ->test(EventsCalendar::class)
        ->assertSeeHtml('data-testid="events-calendar"')
        ->assertSeeHtml('data-testid="event-card"')
        ->assertSeeHtml(sprintf('data-testid="rsvp-button-%s"', $event->event_key));
});

it('does not offer an RSVP button to a guest', function (): void {
    Event::factory()->create();

    Livewire::test(EventsCalendar::class)
        ->assertDontSeeHtml('data-testid="rsvp-button-')
        ->assertSee('Sign in with Discord');
});

it('refuses to write an RSVP for a guest even if the call is forged', function (): void {
    // Livewire methods are a public HTTP surface. Hiding the button is a UI
    // decision; this is the one that actually holds.
    $event = Event::factory()->create();

    Livewire::test(EventsCalendar::class)->call('rsvp', $event->event_key);

    expect(Rsvp::query()->where('event_id', $event->id)->exists())->toBeFalse();
});
