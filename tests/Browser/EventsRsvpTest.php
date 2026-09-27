<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

test('a failed RSVP write returns the control and succeeds on retry', function () {
    $member = User::factory()->create();
    $event = browsableEvent();

    // The application server is a separate process, so a mocked service in this
    // PHPUnit process would not reach the browser journey. This trigger lives in the
    // real Dusk database and rejects exactly the first RSVP insert; dropping it after
    // the failed click restores the normal write path for the retry.
    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION dusk_fail_first_rsvp_insert()
        RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION 'Dusk: reject the first RSVP insert';
        END;
        $$ LANGUAGE plpgsql;

        CREATE TRIGGER dusk_fail_first_rsvp_insert
        BEFORE INSERT ON rsvps
        FOR EACH STATEMENT
        EXECUTE FUNCTION dusk_fail_first_rsvp_insert();
        SQL);

    try {
        $this->browse(function (Browser $browser) use ($member) {
            $browser->loginAs($member)
                ->resize(360, 780)
                ->visit('/events')
                ->waitUntil('window.Livewire?.initialRenderIsFinished === true')
                ->waitFor('[data-testid="rsvp-going"]')
                ->click('[data-testid="rsvp-going"]')
                ->waitFor('[data-testid="rsvp-failed"]')
                ->assertSeeIn('[data-testid="rsvp-failed"]', "That RSVP didn't save. Try once more.")
                ->assertVisible('[data-testid="rsvp-going"]')
                ->assertButtonEnabled('[data-testid="rsvp-going"]')
                ->assertMissing('[data-testid="rsvp-confirmed"]')
                ->assertMissing('[data-testid="rsvp-syncing"]');

            expect(Rsvp::query()->count())->toBe(0);

            DB::unprepared('DROP TRIGGER dusk_fail_first_rsvp_insert ON rsvps');

            $browser->click('[data-testid="rsvp-going"]')
                ->waitFor('[data-testid="rsvp-confirmed"]')
                ->assertSeeIn('[data-testid="rsvp-confirmed"]', "You're in")
                ->assertVisible('[data-testid="rsvp-check"]')
                ->assertVisible('[data-testid="rsvp-syncing"]')
                ->assertMissing('[data-testid="rsvp-failed"]');
        });
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS dusk_fail_first_rsvp_insert ON rsvps');
        DB::unprepared('DROP FUNCTION IF EXISTS dusk_fail_first_rsvp_insert()');
    }

    expect(Rsvp::query()
        ->where('event_id', $event->id)
        ->where('user_id', $member->id)
        ->where('status', RsvpStatus::Going)
        ->exists())->toBeTrue();
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

    // A healthy empty read is not an unavailable bot. The shared cache makes
    // the fixture visible to the separate HTTP process without a test route.
    Cache::put('events.discord-upcoming', [], 600);

    try {
        $this->browse(function (Browser $browser) {
            $browser->resize(360, 780)
                ->visit('/events')
                ->waitFor('[data-testid="events-empty-never"]')
                ->assertSeeIn('[data-testid="events-empty-never"]', 'Nothing on the calendar yet.')
                ->assertSeeLink('Join the Discord')
                ->assertMissing('[data-testid="events-empty-error"]')
                ->assertMissing('[data-testid="rsvp-failed"]')
                ->click('[data-testid="events-view-calendar"]')
                ->waitFor('[data-testid="events-calendar-grid"]')
                ->assertAttribute('[data-testid="events-view-calendar"]', 'aria-checked', 'true')
                ->assertVisible('[data-testid="events-empty-never"]')
                ->assertDontSee('No events found');
        });
    } finally {
        Cache::forget('events.discord-upcoming');
    }
});

test('the gap calendar shows five past names and keeps the selected view', function () {
    Cache::put('events.discord-upcoming', [], 600);

    for ($days = 1; $days <= 7; $days++) {
        browsableEvent([
            'title' => "Past game night {$days}",
            'starts_at' => now()->subDays($days)->subHours(2),
            'ends_at' => now()->subDays($days),
        ]);
    }

    try {
        $this->browse(function (Browser $browser) {
            $browser->resize(360, 780)
                ->visit('/events')
                ->waitFor('[data-testid="events-empty-gap"]')
                ->assertSeeIn('[data-testid="events-empty-gap"]', 'No upcoming events — check back soon.')
                ->assertSeeIn('[data-testid="events-empty-gap"]', 'Last time: Past game night 1')
                ->assertElementsCount('[data-testid="events-empty-gap-item"]', 5)
                ->assertDontSee('Past game night 6')
                ->assertMissing('[data-testid="events-empty-never"]')
                ->click('[data-testid="events-view-calendar"]')
                ->waitFor('[data-testid="events-calendar-grid"]')
                ->assertAttribute('[data-testid="events-view-calendar"]', 'aria-checked', 'true')
                ->assertVisible('[data-testid="events-empty-gap"]');
        });
    } finally {
        Cache::forget('events.discord-upcoming');
    }
});

test('a failed calendar read retries in the browser without losing the view', function () {
    // An invalid cached read triggers the reader's error path deterministically,
    // without reaching Discord or adding a production-accessible failure seam.
    Cache::put('events.discord-upcoming', 'invalid-event-result', 600);

    try {
        $this->browse(function (Browser $browser) {
            $browser->resize(360, 780)
                ->visit('/events')
                ->waitFor('[data-testid="events-empty-error"]')
                ->assertSeeIn('[data-testid="events-empty-error"]', "We couldn't load the calendar.")
                ->assertSeeIn('[data-testid="events-empty-error"]', 'The Discord always has the latest — come ask there.')
                ->assertMissing('[data-testid="events-empty-never"]')
                ->click('[data-testid="events-view-calendar"]')
                ->waitFor('[data-testid="events-calendar-grid"]')
                ->assertAttribute('[data-testid="events-view-calendar"]', 'aria-checked', 'true');

            Cache::put('events.discord-upcoming', [], 600);

            $browser->click('[data-testid="events-retry"]')
                ->waitFor('[data-testid="events-empty-never"]')
                ->assertMissing('[data-testid="events-empty-error"]')
                ->assertAttribute('[data-testid="events-view-calendar"]', 'aria-checked', 'true')
                ->assertVisible('[data-testid="events-calendar-grid"]');
        });
    } finally {
        Cache::forget('events.discord-upcoming');
    }
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
