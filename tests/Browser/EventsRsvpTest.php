<?php

use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Laravel\Dusk\Browser;

/*
 * The RSVP round trip in a real browser, at the size it actually gets opened at.
 *
 * The viewport is not decoration. This page is opened in the Discord in-app
 * browser on a phone more often than anywhere else, and 360px is the narrowest
 * width worth supporting — every assertion here runs at that width so a layout
 * that only works on a laptop fails rather than passes.
 *
 * What this covers that the Livewire component tests cannot: that the button is
 * really clickable, that the Livewire round trip actually completes against a
 * real server, and that the RSVP state survives a full page reload — which is
 * the difference between a component that updated its own properties and one
 * that wrote a row.
 *
 * Same split as tests/Browser/DiscordJoinTest.php: the browser owns the page and
 * the affordances on it, the Pest component tests own every state and failure
 * path, and the `budgets` job's axe pass owns accessibility.
 */
beforeEach(function (): void {
    $this->member = User::factory()->create();
});

test('a member RSVPs to an event and the answer survives a reload', function () {
    $event = Event::factory()->create([
        'title' => 'Helldivers night',
        'capacity' => 8,
    ]);

    $this->browse(function (Browser $browser) use ($event) {
        $browser->resize(360, 780)
            ->loginAs($this->member)
            ->visit('/calendar')
            ->assertSee('Helldivers night')
            ->assertVisible('[data-testid="events-calendar"]')
            ->assertVisible('[data-testid="event-card"]')

            ->click(sprintf('[data-testid="rsvp-button-%s"]', $event->event_key))
            // waitForText, not pause: the assertion is "the round trip finished",
            // and a fixed sleep either flakes or wastes the difference.
            ->waitForText("You're in")
            ->assertVisible('[data-testid="rsvp-withdraw"]')

            // The row, not the component's memory of it.
            ->refresh()
            ->waitForText("You're in")
            ->assertVisible('[data-testid="rsvp-withdraw"]');
    });

    expect(Rsvp::query()->where('event_id', $event->id)->where('user_id', $this->member->id)->exists())
        ->toBeTrue();
});

test('a member can stand down again', function () {
    $event = Event::factory()->create(['title' => 'Valorant five stack']);
    Rsvp::factory()->for($event)->for($this->member)->create();

    $this->browse(function (Browser $browser) use ($event) {
        $browser->resize(360, 780)
            ->loginAs($this->member)
            ->visit('/calendar')
            ->waitForText("You're in")
            ->click('[data-testid="rsvp-withdraw"]')
            ->waitFor(sprintf('[data-testid="rsvp-button-%s"]', $event->event_key))
            ->assertDontSee("You're in");
    });

    expect(Rsvp::query()->where('event_id', $event->id)->where('user_id', $this->member->id)->exists())
        ->toBeFalse();
});

test('the empty calendar reads as early rather than broken, and offers a way out', function () {
    $this->browse(function (Browser $browser) {
        $browser->resize(360, 780)
            ->visit('/calendar')
            ->assertSee('Nothing on the calendar yet')
            ->assertDontSee('No events')
            // Rule 2 of the empty-state spec: exactly one action, and a real
            // anchor so it is keyboard-reachable and copyable.
            ->assertVisible('[data-testid="events-empty-action"]')
            ->assertAttribute('[data-testid="events-empty-action"]', 'href', url('/discord'));
    });
});

test('a full event offers no seat that does not exist', function () {
    $event = Event::factory()->create(['title' => 'Raid night', 'capacity' => 1]);
    Rsvp::factory()->for($event)->create();

    $this->browse(function (Browser $browser) use ($event) {
        $browser->resize(360, 780)
            ->loginAs($this->member)
            ->visit('/calendar')
            ->assertSee("This one's full")
            ->assertAttribute(
                sprintf('[data-testid="rsvp-button-%s"]', $event->event_key),
                'disabled',
                'true',
            );
    });

    expect(Rsvp::query()->where('event_id', $event->id)->where('user_id', $this->member->id)->exists())
        ->toBeFalse();
});
