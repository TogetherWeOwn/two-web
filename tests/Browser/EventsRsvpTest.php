<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;
use App\Support\RsvpRateLimit;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
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

/*
 * The RSVP round-trip, part two: the pre-boot guard (TOG-7927). Every journey
 * that clicks a control waits for boot first — `waitForLivewireBoot()`,
 * shared in Support/DeferredLivewireBoot.php so MobileClickPathTest drives
 * the same bound-side click — and the last test owns the pre-boot window
 * itself (blocked script, disabled button, recovery). No deferred page
 * server-renders an `a[wire:click]` (pagination renders buttons; the calendar
 * day-links only exist after a client-side view switch), so a pre-boot link
 * tap is unreachable on real markup — the link half (capture-stop plus the
 * announced copy) is pinned by the Feature tests instead.
 */
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
            ->assertVisible('[data-testid="event-card"]');

        // The guard disables the button until the deferred runtime binds it —
        // click before that and the tap lands in the unbound window (TOG-7927).
        waitForLivewireBoot($browser);

        $browser
            // The control is offered before it is pressed. If this is missing the
            // failure says "no RSVP button", not "click timed out".
            ->assertVisible('[data-testid="rsvp-going"]')
            ->assertSeeIn('[data-testid="rsvp-going"]', "I'm in")
            ->assertButtonEnabled('[data-testid="rsvp-going"]')

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
            // The receipt proves the bot was called, not that the write-back
            // committed: the stub writes the receipt before answering, and the
            // job stamps synced_to_discord_at only after the answer arrives.
            // Refreshing in between renders "syncing" with nothing to re-render
            // it (the page does not poll), so wait for the committed stamp
            // first and only then refresh into the synced state.
            ->waitUsing(20, 100, function () use ($member, $event) {
                return Rsvp::query()
                    ->where('event_id', $event->id)
                    ->where('user_id', $member->id)
                    ->whereNotNull('synced_to_discord_at')
                    ->exists();
            }, 'The write-back never marked the RSVP synced.')
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
                ->waitFor('[data-testid="rsvp-going"]');

            waitForLivewireBoot($browser);

            $browser->click('[data-testid="rsvp-going"]')
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

test('a rate-limited RSVP click announces the wait and keeps the control', function () {
    $member = User::factory()->create();
    $event = browsableEvent();

    // The limiter is database-backed and shared across processes, so spending
    // the member's budget here throttles the browser journey too: the click
    // below is the 13th write. The HTTP 429 envelope is unchanged; the control
    // must speak instead of failing silently (TOG-7976).
    for ($attempt = 0; $attempt < RsvpRateLimit::MAX_ATTEMPTS; $attempt++) {
        RsvpRateLimit::hit($member);
    }

    $this->browse(function (Browser $browser) use ($member, $event) {
        $browser->loginAs($member)
            ->resize(360, 780)
            ->visit('/events')
            ->waitFor('[data-testid="rsvp-going"]');

        waitForLivewireBoot($browser);

        $browser->click('[data-testid="rsvp-going"]')
            // The exact wait depends on wall-clock seconds since the budget was
            // spent across the process boundary, so pin the stable fragments of
            // the CM-frozen copy, not the number.
            ->waitFor('[data-testid="rsvp-rate-limited"]')
            ->assertSeeIn('[data-testid="rsvp-rate-limited"]', 'Slow down — try again in')
            ->assertSeeIn('[data-testid="rsvp-rate-limited"]', 'seconds. Nothing changed, just wait a moment.')
            // Polite announcement for a temporary wait, never an interruption.
            ->assertAttribute('[data-testid="rsvp-rate-limited"]', 'role', 'status')
            // The control returns to default and stays usable — never disabled
            // or replaced, and no failure copy for a wait.
            ->assertVisible('[data-testid="rsvp-going"]')
            ->assertButtonEnabled('[data-testid="rsvp-going"]')
            ->assertMissing('[data-testid="rsvp-confirmed"]')
            ->assertMissing('[data-testid="rsvp-failed"]');

        // The throttled click changed nothing.
        expect(Rsvp::query()
            ->where('event_id', $event->id)
            ->where('user_id', $member->id)
            ->exists())->toBeFalse();
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
            ->waitFor('[data-testid="rsvp-withdraw"]');

        waitForLivewireBoot($browser);

        $browser->assertSeeIn('[data-testid="rsvp-withdraw"]', "Can't make it")
            ->click('[data-testid="rsvp-withdraw"]')
            ->waitFor('[data-testid="rsvp-going"]')
            ->assertMissing('[data-testid="rsvp-confirmed"]')

            ->refresh()
            ->waitFor('[data-testid="rsvp-going"]')
            ->assertMissing('[data-testid="rsvp-confirmed"]');
    });
});

test('the going count ticks with the answer without a reload', function () {
    // TOG-7966: the badge lives outside RsvpButton, so it used to show the
    // pre-click number until a full reload. No refresh below on purpose — the
    // tick itself is the assertion. waitForTextIn, never pause (flake policy).
    $member = User::factory()->create();
    browsableEvent();

    $this->browse(function (Browser $browser) use ($member) {
        $browser->loginAs($member)
            ->resize(360, 780)
            ->visit('/events')
            ->waitForText('Friday night Helldivers')
            ->waitFor('[data-testid="event-going-count"]')
            ->assertSeeIn('[data-testid="event-going-count"]', '0 of 4 going')
            // Polite live region: the count changes without a reload, so the
            // change must announce via role="status", never role="alert".
            ->assertAttribute('[data-testid="event-going-count"]', 'role', 'status')
            ->assertVisible('[data-testid="rsvp-going"]');

        waitForLivewireBoot($browser);

        $browser->click('[data-testid="rsvp-going"]')
            ->waitFor('[data-testid="rsvp-confirmed"]')
            ->waitForTextIn('[data-testid="event-going-count"]', '1 of 4 going')
            ->assertSeeIn('[data-testid="event-going-count"]', '1 of 4 going')
            ->click('[data-testid="rsvp-withdraw"]')
            ->waitFor('[data-testid="rsvp-going"]')
            ->waitForTextIn('[data-testid="event-going-count"]', '0 of 4 going')
            ->assertSeeIn('[data-testid="event-going-count"]', '0 of 4 going');
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
                ->assertAttribute('[data-testid="events-empty-never"] [data-testid="discord-join"]', 'href', route('discord'))
                ->assertSeeLink('Join the Discord')
                ->assertMissing('[data-testid="events-empty-error"]')
                ->assertMissing('[data-testid="rsvp-failed"]');

            // The toggle renders before the deferred runtime binds it — a bare
            // `waitFor` above is not enough (TOG-7927).
            waitForLivewireBoot($browser);

            $browser->click('[data-testid="events-view-calendar"]')
                ->waitFor('[data-testid="events-calendar-grid"]')
                ->assertAttribute('[data-testid="events-view-calendar"]', 'aria-pressed', 'true')
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
                ->assertCount('[data-testid="events-empty-gap-item"]', 5)
                ->assertDontSee('Past game night 6')
                ->assertMissing('[data-testid="events-empty-never"]');

            // Same unbound window as above: the toggle is a `wire:click` button.
            waitForLivewireBoot($browser);

            $browser->click('[data-testid="events-view-calendar"]')
                ->waitFor('[data-testid="events-calendar-grid"]')
                ->assertAttribute('[data-testid="events-view-calendar"]', 'aria-pressed', 'true')
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
                ->assertMissing('[data-testid="events-empty-never"]');

            // The retry below re-fires a `wire:click` read; it needs the runtime
            // bound, which this wait pins before the first toggle click.
            waitForLivewireBoot($browser);

            $browser->click('[data-testid="events-view-calendar"]')
                ->waitFor('[data-testid="events-calendar-grid"]')
                ->assertAttribute('[data-testid="events-view-calendar"]', 'aria-pressed', 'true');

            Cache::put('events.discord-upcoming', [], 600);

            $browser->click('[data-testid="events-retry"]')
                ->waitFor('[data-testid="events-empty-never"]')
                ->assertMissing('[data-testid="events-empty-error"]')
                ->assertAttribute('[data-testid="events-view-calendar"]', 'aria-pressed', 'true')
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
            ->waitForText('Friday night Helldivers');

        // The grid toggle is a `wire:click` button: unbound until the
        // deferred runtime boots (TOG-7927).
        waitForLivewireBoot($browser);

        $browser->click('[data-testid="events-view-calendar"]')
            ->waitFor('[data-testid="events-calendar-grid"]')
            ->assertVisible('[data-testid="calendar-day"]')
            ->assertSeeIn('[data-testid="events-calendar-grid"]', 'Mon');
    });
});

test('an RSVP tap before Livewire boots cannot land and the control recovers', function () {
    $member = User::factory()->create();
    $event = browsableEvent();

    $this->browse(function (Browser $browser) use ($member, $event) {
        $cdp = new ChromeDevToolsDriver($browser->driver);
        $cdp->execute('Network.enable');
        // Hold the pre-boot window open deterministically: the page renders
        // fully but the deferred runtime never arrives, so Livewire.start()
        // never runs. Network-level blocking, not a paused sleep — a fixed
        // delay is either a flake or dead time (docs/flake-policy.md).
        $cdp->execute('Network.setBlockedURLs', ['urls' => ['*livewire*']]);

        try {
            $browser->loginAs($member)
                ->resize(360, 780)
                ->visit('/events')
                ->waitFor('[data-testid="rsvp-going"]');

            // The guard disables the button at parse time. Wait for the
            // property, not just presence: the button exists in the initial
            // HTML before the guard script below it runs.
            $browser->waitUsing(10, 100, function () use ($browser) {
                $result = $browser->script(
                    'return (document.querySelector(\'[data-testid="rsvp-going"]\') || {}).disabled === true;'
                );

                return (bool) ($result[0] ?? false);
            }, 'The pre-boot guard never disabled the RSVP button.');

            $browser->assertButtonDisabled('[data-testid="rsvp-going"]');

            // The loading state is announced through the live region, not
            // silent — a screen reader hears where the control stands.
            expect(bootStatusText($browser))->toContain('Loading interactive controls');

            // A tap in the window cannot land: even a synthetic click fires
            // nothing on a disabled button, so no answer is written.
            $browser->script('document.querySelector(\'[data-testid="rsvp-going"]\').click();');

            expect(Rsvp::query()
                ->where('event_id', $event->id)
                ->where('user_id', $member->id)
                ->exists())->toBeFalse();
        } finally {
            $cdp->execute('Network.setBlockedURLs', ['urls' => []]);
        }

        // Recovery: with the runtime reachable again the page boots, the guard
        // lifts, and the same control answers a real tap.
        $browser->refresh();

        waitForLivewireBoot($browser);

        $browser->assertButtonEnabled('[data-testid="rsvp-going"]')
            ->click('[data-testid="rsvp-going"]')
            ->waitFor('[data-testid="rsvp-confirmed"]')
            ->assertSeeIn('[data-testid="rsvp-confirmed"]', "You're in");

        // Nobody was stopped, so boot stays silent: no stale loading line and
        // no unprompted ready announcement for a member who touched nothing.
        expect(bootStatusText($browser))->toBe('');

        expect(Rsvp::query()
            ->where('event_id', $event->id)
            ->where('user_id', $member->id)
            ->where('status', RsvpStatus::Going)
            ->exists())->toBeTrue();
    });
});
