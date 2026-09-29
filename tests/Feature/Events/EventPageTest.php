<?php

use App\Enums\EventStatus;
use App\Enums\RsvpStatus;
use App\Models\Event;
use App\Models\Rsvp;
use App\Models\User;

// The shareable event page: one event as HTML at `/e/{event_key}` for a link
// passed around Discord. `/events/{event}` stays JSON — one URL must not serve
// two media types — so these tests also pin the new path's own canonical.

beforeEach(function () {
    $this->member = User::factory()->create(['is_moderator' => false]);
    $this->moderator = User::factory()->create(['is_moderator' => true]);
});

function publishedEvent(array $overrides = []): Event
{
    return Event::factory()->create(array_merge([
        'title' => 'Friday night Helldivers',
        'description' => 'Bring a friend, bring spare ammo.',
        'location' => 'Voice: General',
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
        'status' => EventStatus::Published,
    ], $overrides));
}

it('renders a published event for a guest with its title, time, venue and description', function () {
    $event = publishedEvent();

    $response = $this->get(route('events.page', $event))->assertOk();

    $response->assertSee($event->title)
        ->assertSee($event->description)
        ->assertSee($event->location)
        ->assertSee($event->timezone)
        ->assertSeeHtml('data-testid="event-page"')
        ->assertSeeHtml('<link rel="canonical" href="'.route('events.page', $event).'">');
});

it('returns 404 for an unknown event key', function () {
    $this->get('/e/no-such-event')->assertNotFound();
});

it('pitches joining to a guest instead of showing an RSVP button that cannot work', function () {
    $event = publishedEvent();

    $response = $this->get(route('events.page', $event))->assertOk();

    $response->assertSeeHtml('data-testid="event-join-pitch"')
        ->assertSeeHtml('data-testid="discord-join"')
        ->assertDontSeeHtml('data-testid="rsvp-going"');
});

it('shows a signed-in member the RSVP control', function () {
    $event = publishedEvent();

    $this->actingAs($this->member)
        ->get(route('events.page', $event))
        ->assertOk()
        ->assertSeeHtml('data-testid="rsvp-going"')
        ->assertDontSeeHtml('data-testid="event-join-pitch"');
});

it('shows a signed-in member their existing answer', function () {
    $event = publishedEvent();
    Rsvp::factory()->for($event)->for($this->member)->create(['status' => RsvpStatus::Going]);

    $this->actingAs($this->member)
        ->get(route('events.page', $event))
        ->assertOk()
        ->assertSeeHtml('data-testid="rsvp-confirmed"');
});

it('shows a full event as closed on the shareable page, with no seat to take', function () {
    // `/e/{key}` carries the same RSVP control as the calendar cards, so it
    // must carry the same closed state: a link passed around Discord lands
    // here, and a member arriving after the cap must not be offered a button
    // that cannot succeed.
    $event = publishedEvent(['capacity' => 1]);
    Rsvp::factory()->for($event)->create(['status' => RsvpStatus::Going]);

    $this->actingAs($this->member)
        ->get(route('events.page', $event))
        ->assertOk()
        ->assertSee("This one's full.", false)
        ->assertSee('Cap is 1.')
        ->assertSeeHtml('data-testid="event-full"')
        ->assertDontSeeHtml('data-testid="rsvp-going"')
        ->assertDontSee("That RSVP didn't save.", false);
});

it('lists attendee display names for a signed-in member', function () {
    $event = publishedEvent();
    $alice = User::factory()->create(['display_name' => 'Alice Attendee']);
    $bob = User::factory()->create(['display_name' => 'Bob Going']);
    Rsvp::factory()->for($event)->for($alice)->create(['status' => RsvpStatus::Going]);
    Rsvp::factory()->for($event)->for($bob)->create(['status' => RsvpStatus::Going]);
    // A maybe is not a seat and must not appear in the list.
    $this->member->update(['display_name' => 'Maya Maybe']);
    Rsvp::factory()->for($event)->for($this->member)->create(['status' => RsvpStatus::Maybe]);

    $this->actingAs($this->member)
        ->get(route('events.page', $event))
        ->assertOk()
        ->assertSeeHtml('data-testid="event-attendees"')
        ->assertSee('Alice Attendee')
        ->assertSee('Bob Going')
        ->assertDontSee('Maya Maybe');
});

it('hides attendee names from guests while keeping the count and join pitch', function () {
    $event = publishedEvent();
    $alice = User::factory()->create(['display_name' => 'Alice Attendee']);
    Rsvp::factory()->for($event)->for($alice)->create(['status' => RsvpStatus::Going]);

    $response = $this->get(route('events.page', $event))->assertOk();

    $response->assertSee('1 going')
        ->assertSeeHtml('data-testid="event-join-pitch"')
        ->assertDontSeeHtml('data-testid="event-attendees"')
        ->assertDontSee('Alice Attendee');
});

it('shows how many spots are left against the cap', function () {
    // The progress signal: seats still claimable from the existing
    // `capacity`/`going_count` data — no new schema. A guest sees it too,
    // because it is information, not a control.
    $event = publishedEvent(['capacity' => 5]);
    Rsvp::factory()->count(2)->for($event)->create(['status' => RsvpStatus::Going]);

    $this->get(route('events.page', $event))
        ->assertOk()
        ->assertSeeHtml('data-testid="event-spots-left"')
        ->assertSee('3 of 5 spots left', false);
});

it('shows Full instead of a count when no spots are left', function () {
    $event = publishedEvent(['capacity' => 1]);
    Rsvp::factory()->for($event)->create(['status' => RsvpStatus::Going]);

    $this->get(route('events.page', $event))
        ->assertOk()
        ->assertSeeHtml('data-testid="event-spots-left"')
        ->assertSee('Full', false)
        ->assertDontSee('spots left', false);
});

it('shows Full rather than a negative count when going exceeds the cap', function () {
    // Over-subscription is possible when the cap is lowered after RSVPs exist;
    // the page must never print "-1 of 1 spots left".
    $event = publishedEvent(['capacity' => 1]);
    Rsvp::factory()->count(3)->for($event)->create(['status' => RsvpStatus::Going]);

    $this->get(route('events.page', $event))
        ->assertOk()
        ->assertSeeHtml('data-testid="event-spots-left"')
        ->assertSee('Full', false)
        ->assertDontSee('spots left', false);
});

it('shows no spots signal when the event has no capacity', function () {
    // Unknown capacity renders nothing rather than inventing a number.
    $event = publishedEvent(['capacity' => null]);
    Rsvp::factory()->for($event)->create(['status' => RsvpStatus::Going]);

    $this->get(route('events.page', $event))
        ->assertOk()
        ->assertSee('1 going', false)
        ->assertDontSeeHtml('data-testid="event-spots-left"');
});

it('hides a draft from guests and members but shows it to moderators', function () {
    $draft = publishedEvent(['status' => EventStatus::Draft]);

    $this->get(route('events.page', $draft))->assertForbidden();
    $this->actingAs($this->member)->get(route('events.page', $draft))->assertForbidden();

    $this->actingAs($this->moderator)
        ->get(route('events.page', $draft))
        ->assertOk()
        ->assertSee($draft->title);
});

it('prints the canonical URL in a screen-hidden handout footer', function () {
    // The URL half of TOG-6930's "QR-or-URL": a printed page points back at
    // the live one. `hidden` keeps it off the screen (the address bar already
    // shows the URL); the `@media print` sheet in app.css reveals it. Pinned
    // for a guest — the page is public — and the static half of this contract
    // (the markers, the rules) lives in tests/Unit/EventPrintSheetTest.php.
    $event = publishedEvent();

    $this->get(route('events.page', $event))
        ->assertOk()
        ->assertSeeHtml('data-testid="event-print-url" data-print="url"')
        ->assertSee(route('events.page', $event), false);
});
