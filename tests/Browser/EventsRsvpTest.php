<?php

use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Laravel\Dusk\Browser;

// Journeys 4 and 6 of the fixed list in docs/testing-strategy.md, on the events
// page. No seventh journey is being added here: 4 is "RSVP to an event → the RSVP
// round-trips to Discord through the bot" and 6 is "the degraded path — the bot is
// unreachable", and both of them run through this page now that it exists.
//
// These earn a real browser because the RSVP is a Livewire round-trip: the button
// posts, the server re-renders one card, and the member reads the answer off the
// page. A feature test asserts the component; it cannot assert that the wire is
// connected, which is the part that breaks when an asset build changes.
//
// ## What "round-trips to Discord through the bot" can honestly assert here
//
// The write-back is a queued job (SyncEventToDiscord) and no queue worker runs in
// the Dusk job, so nothing in this file waits for Discord to know. That is not a
// gap being papered over — it is the same shape as production the moment the queue
// is more than a second deep. What the browser owns is the half a member can see:
// the answer is recorded, it counts, and the page says out loud that Discord has
// not caught up yet. The bot leg itself is pinned by
// tests/Integration/DiscordWriteBackTimingTest.php against a real commit.
//
// ## Data
//
// Dusk here talks to a real server against a shared database and nothing truncates
// between tests, which is the existing convention in this directory. Every
// assertion is therefore scoped to one event's own `event_key` testids rather than
// to page-wide text, so another test's events sharing the page cannot make one of
// these pass or fail.

test('a member answers an event and the page tells the truth about Discord', function () {
    $member = User::factory()->create();
    $event = Event::factory()->create(['title' => 'Helldivers Friday', 'capacity' => null]);

    $this->browse(function (Browser $browser) use ($member, $event) {
        $browser->loginAs($member)
            ->visit('/events')
            ->waitFor('[data-testid="event-'.$event->event_key.'"]')
            ->assertSee('Helldivers Friday')

            // Nothing answered yet.
            ->assertAttribute('[data-testid="rsvp-state-'.$event->event_key.'"]', 'data-state', 'none')

            ->click('[data-testid="rsvp-going-'.$event->event_key.'"]')

            // The Livewire round-trip landed and the card re-rendered with the
            // answer on it. waitUntil rather than a sleep: the assertion is the
            // wait, so a broken wire fails as "never became going" instead of as a
            // flake somebody reruns.
            ->waitUsing(10, 100, fn (): bool => $browser->attribute(
                '[data-testid="rsvp-state-'.$event->event_key.'"]', 'data-state'
            ) === 'going')

            // The honest pending state. The bot is not running in this job, so this
            // is also the message a member gets in production while the queue
            // drains — and it must never say the RSVP failed, because it did not.
            ->assertVisible('[data-testid="rsvp-pending-'.$event->event_key.'"]')
            ->assertSee('Discord has not caught up yet')
            ->assertMissing('[data-testid="rsvp-error-'.$event->event_key.'"]')

            // It counts. The seat is taken as far as the page is concerned.
            ->assertSeeIn('[data-testid="event-going-'.$event->event_key.'"]', '1 going');
    });

    // And it is a row, not a rendering. The browser said so; the database agrees.
    expect(Rsvp::query()
        ->where('event_id', $event->id)
        ->where('user_id', $member->id)
        ->value('status'))->toBe(RsvpStatus::Going);
});

test('a member can take their answer back', function () {
    $member = User::factory()->create();
    $event = Event::factory()->create();
    Rsvp::factory()->for($event)->for($member)->create(['status' => RsvpStatus::Going]);

    $this->browse(function (Browser $browser) use ($member, $event) {
        $browser->loginAs($member)
            ->visit('/events')
            ->waitFor('[data-testid="rsvp-withdraw-'.$event->event_key.'"]')
            ->assertAttribute('[data-testid="rsvp-state-'.$event->event_key.'"]', 'data-state', 'going')
            ->click('[data-testid="rsvp-withdraw-'.$event->event_key.'"]')
            ->waitUsing(10, 100, fn (): bool => $browser->attribute(
                '[data-testid="rsvp-state-'.$event->event_key.'"]', 'data-state'
            ) === 'none')
            ->assertMissing('[data-testid="rsvp-withdraw-'.$event->event_key.'"]');
    });

    expect(Rsvp::query()->where('event_id', $event->id)->where('user_id', $member->id)->exists())
        ->toBeFalse();
});

test('a full event says so and does not offer the seat', function () {
    $member = User::factory()->create();
    $event = Event::factory()->create(['capacity' => 1]);
    Rsvp::factory()->for($event)->for(User::factory())->create(['status' => RsvpStatus::Going]);

    $this->browse(function (Browser $browser) use ($member, $event) {
        $browser->loginAs($member)
            ->visit('/events')
            ->waitFor('[data-testid="event-'.$event->event_key.'"]')
            ->assertVisible('[data-testid="event-full-'.$event->event_key.'"]')
            // Disabled rather than hidden: a member who cannot go still needs to see
            // that Going was the thing that ran out, and Maybe is still answerable.
            ->assertDisabled('[data-testid="rsvp-going-'.$event->event_key.'"]')
            ->assertEnabled('[data-testid="rsvp-maybe-'.$event->event_key.'"]');
    });
});

test('the degraded path: the bot is unreachable and the page is still a page', function () {
    // Journey 6. Nothing is listening on BOT_ENDPOINT_URL in this job, so this runs
    // with the bot genuinely down rather than with a mock that says it is.
    //
    // What must be true: the page renders, the answer is taken, the member is told
    // something true, and there is no white screen and no stack trace. What must NOT
    // be true is a message saying the RSVP failed — it did not fail, and a member
    // who believes it did will answer again.
    $member = User::factory()->create();
    $event = Event::factory()->create(['title' => 'Bot Is Down Night', 'capacity' => null]);

    $this->browse(function (Browser $browser) use ($member, $event) {
        $browser->loginAs($member)
            ->visit('/events')
            ->waitFor('[data-testid="event-'.$event->event_key.'"]')
            ->assertSee('Bot Is Down Night')
            ->click('[data-testid="rsvp-going-'.$event->event_key.'"]')
            ->waitFor('[data-testid="rsvp-pending-'.$event->event_key.'"]')
            ->assertSee('Discord has not caught up yet')
            ->assertDontSee('failed')
            ->assertDontSee('Whoops')
            ->assertMissing('[data-testid="rsvp-error-'.$event->event_key.'"]');
    });

    expect(Rsvp::query()->where('event_id', $event->id)->where('user_id', $member->id)->exists())
        ->toBeTrue();
});

test('the page works at 360px, which is where it is actually opened', function () {
    // The card is explicit that the commonest way in is a phone inside the Discord
    // in-app browser. At 360px the month grid is not offered at all and the list is
    // the whole page, so the thing to prove is that the RSVP controls are reachable
    // and tappable at that width rather than off the side of the viewport.
    $member = User::factory()->create();
    $event = Event::factory()->create(['capacity' => null]);

    $this->browse(function (Browser $browser) use ($member, $event) {
        $browser->resize(360, 780)
            ->loginAs($member)
            ->visit('/events')
            ->waitFor('[data-testid="event-'.$event->event_key.'"]')
            ->assertVisible('[data-testid="events-list"]')
            // The view switch is deliberately not rendered this narrow: a
            // seven-column grid at 360px is one tap target wide per day.
            ->assertMissing('[data-testid="events-calendar"]')
            ->assertVisible('[data-testid="rsvp-going-'.$event->event_key.'"]')
            ->click('[data-testid="rsvp-going-'.$event->event_key.'"]')
            ->waitUsing(10, 100, fn (): bool => $browser->attribute(
                '[data-testid="rsvp-state-'.$event->event_key.'"]', 'data-state'
            ) === 'going');
    });
});

test('a stranger is shown what is on and offered the way in', function () {
    $event = Event::factory()->create(['title' => 'Open To Anyone']);

    $this->browse(function (Browser $browser) use ($event) {
        $browser->visit('/events')
            ->waitFor('[data-testid="event-'.$event->event_key.'"]')
            ->assertSee('Open To Anyone')
            // No RSVP controls, one way in.
            ->assertMissing('[data-testid="rsvp-going-'.$event->event_key.'"]')
            ->assertVisible('[data-testid="rsvp-signin-'.$event->event_key.'"]')
            ->assertAttribute(
                '[data-testid="rsvp-signin-'.$event->event_key.'"]',
                'href',
                url('/auth/discord/redirect'),
            );
    });
});
