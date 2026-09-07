<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Laravel\Dusk\Browser;

/*
 * The RSVP round-trip in a real browser — QA's journey for TOG-53.
 *
 * What this owns that the Pest suite cannot. The Livewire component tests render
 * markup and call methods directly; they prove the component decides correctly.
 * They cannot prove the click reaches it. Everything between the two — the route
 * being public, Livewire's JS actually being on the page, the button being a real
 * focusable control rather than a styled div, the answer surviving a full reload —
 * only exists in a browser, and every one of those has been a shipped bug
 * somewhere.
 *
 * The 360px case is a requirement, not a nicety: the card says this gets opened on
 * a phone in the Discord in-app browser more than anywhere else, so the narrow
 * viewport is the primary journey and is asserted first.
 *
 * Discord itself is not a merge dependency. The real database queue worker sends
 * the production-signed event.upsert payload to ci/dusk-stub.mjs, which records it
 * for the journey to inspect before the browser reloads the synced state.
 */

/** A published event, three days out, with room in it. */
function browsableEvent(array $overrides = []): Event
{
    return Event::factory()->create(array_merge([
        'title' => 'Friday night Helldivers',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
        'capacity' => 4,
    ], $overrides));
}

test('a member RSVPs, the bot receives it, and the page advances to synced', function () {
    $member = User::factory()->create();
    $event = browsableEvent();
    $receipt = base_path('storage/logs/dusk-bot-receipt.json');

    if (is_file($receipt)) {
        unlink($receipt);
    }

    $this->browse(function (Browser $browser) use ($member, $event, $receipt) {
        $browser->loginAs($member)
            // 360px: the width the brief names. Asserted on the journey itself
            // rather than in a separate "does it reflow" test, so a layout that
            // only works wide fails the thing QA actually signs off.
            ->resize(360, 780)
            ->visit('/events')
            ->waitForText('Friday night Helldivers')
            ->assertVisible('[data-testid="event-card"]')

            // The control is offered before it is pressed. If this is missing the
            // failure says "no RSVP button", not "click timed out".
            ->assertVisible('[data-testid="rsvp-going"]')
            ->assertSeeIn('[data-testid="rsvp-going"]', "I'm in")

            ->click('[data-testid="rsvp-going"]')

            // waitFor, not pause: the round trip is a network call and any fixed
            // sleep is either a flake or dead time (docs/flake-policy.md).
            ->waitFor('[data-testid="rsvp-confirmed"]')
            ->assertSeeIn('[data-testid="rsvp-confirmed"]', "You're in")
            // The mark as well as the words — the confirmation is never colour alone.
            ->assertVisible('[data-testid="rsvp-check"]')

            // Committed here, Discord not caught up yet. This is a true state and
            // it must not read as a failure.
            ->assertVisible('[data-testid="rsvp-syncing"]')
            ->assertMissing('[data-testid="rsvp-failed"]')

            // The worker is a third process. Wait for its durable receipt rather
            // than sleeping, then prove the exact event reached the bot boundary.
            ->waitUsing(20, 100, fn () => is_file($receipt), 'The bot stub received no event.upsert call.')
            ->refresh()
            ->waitFor('[data-testid="rsvp-synced"]')
            ->assertSeeIn('[data-testid="rsvp-synced"]', 'Synced to Discord.')
            ->assertMissing('[data-testid="rsvp-syncing"]');

        $received = json_decode((string) file_get_contents($receipt), true, flags: JSON_THROW_ON_ERROR);

        expect($received['body'])->toMatchArray([
            'action' => 'event.upsert',
            'event_key' => $event->event_key,
            'name' => 'Friday night Helldivers',
            'location' => 'Voice: General',
        ])->and($received['body'])->not->toHaveKey('channel_key')
            ->and($received['headers']['idempotencyKey'])->not->toBeNull();
    });
});

test('a member can stand down again', function () {
    $member = User::factory()->create();
    $event = browsableEvent();
    Rsvp::factory()->create([
        'event_id' => $event->id,
        'user_id' => $member->id,
        'status' => RsvpStatus::Going,
    ]);

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->resize(360, 780)
            ->visit('/events')
            ->waitFor('[data-testid="rsvp-withdraw"]')
            ->assertSeeIn('[data-testid="rsvp-withdraw"]', "Can't make it")
            ->click('[data-testid="rsvp-withdraw"]')
            ->waitFor('[data-testid="rsvp-going"]')
            ->assertMissing('[data-testid="rsvp-confirmed"]')

            ->refresh()
            ->waitFor('[data-testid="rsvp-going"]')
            ->assertMissing('[data-testid="rsvp-confirmed"]');
    });
});

test('a guest is asked to log in rather than handed a button that cannot work', function () {
    browsableEvent();

    $this->browse(function (Browser $browser) {
        $browser->resize(360, 780)
            ->visit('/events')
            ->waitForText('Friday night Helldivers')
            // Public on purpose. A 302 to Discord here would hide the page from
            // everybody who has not joined yet, which is who it is written for.
            ->assertPathIs('/events')
            ->assertSeeLink('Log in with Discord')
            ->assertMissing('[data-testid="rsvp-going"]');
    });
});

test('a full event says so instead of offering a seat that is not there', function () {
    $member = User::factory()->create();
    $event = browsableEvent(['capacity' => 1]);
    Rsvp::factory()->create(['event_id' => $event->id, 'status' => RsvpStatus::Going]);

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->resize(360, 780)
            ->visit('/events')
            ->waitFor('[data-testid="event-full"]')
            ->assertSeeIn('[data-testid="event-full"]', "This one's full.")
            ->assertSeeIn('[data-testid="event-full"]', 'Cap is 1.')
            ->assertMissing('[data-testid="rsvp-going"]');
    });
});

test('an empty calendar reads as early rather than broken', function () {
    // No events at all. This is the state the site is in most of the time when it
    // is new, and the one the brief singles out.
    //
    // The precondition is stated rather than assumed. Dusk truncates between tests
    // (tests/Pest.php), but this is the one test in the file whose subject is an
    // absence, so it says so itself: if the truncation is ever loosened, this fails
    // on its own terms instead of inheriting whatever the previous test created.
    expect(Event::query()->count())->toBe(0);

    $this->browse(function (Browser $browser) {
        $browser->resize(360, 780)
            ->visit('/events')
            ->waitFor('[data-testid="events-empty-never"]')
            ->assertSeeIn('[data-testid="events-empty-never"]', 'Nothing on the calendar yet.')
            // Exactly one action, and it is the one that helps.
            ->assertSeeLink('Join the Discord')
            // Nothing on this page is an error. A member who lands here early must
            // not think the site is down.
            ->assertMissing('[data-testid="rsvp-failed"]')
            ->assertDontSee('No events found');
    });
});

test('the calendar view renders a month grid and jumps to the event', function () {
    $member = User::factory()->create();
    browsableEvent();

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            // Wide, because the grid is the desktop affordance; below md it is
            // behind a horizontal scroll rather than squashed into seven columns.
            ->resize(1280, 900)
            ->visit('/events')
            ->waitForText('Friday night Helldivers')
            ->click('[data-testid="events-view-calendar"]')
            ->waitFor('[data-testid="events-calendar-grid"]')
            ->assertVisible('[data-testid="calendar-day"]')
            ->assertSeeIn('[data-testid="events-calendar-grid"]', 'Mon');
    });
});
