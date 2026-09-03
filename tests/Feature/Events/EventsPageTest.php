<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

// The page itself: the route, who can reach it, and that the Livewire component is
// actually mounted on it. The component's own behaviour is
// tests/Feature/Livewire/EventsCalendarTest.php.

beforeEach(function () {
    Queue::fake();
});

it('shows the calendar to a visitor who is not signed in', function () {
    // The events list is the second thing the funnel has to offer a stranger, after
    // the join link. Putting it behind the login is asking someone to sign in
    // before they can see whether there is anything worth signing in for.
    Event::factory()->create(['title' => 'Helldivers Friday', 'status' => EventStatus::Published]);

    $this->get('/events')
        ->assertOk()
        ->assertSee('Helldivers Friday');
});

it('shows the calendar to a signed-in member', function () {
    // Asserting the rendered page, not just the title: `GET /events` used to be the
    // JSON listing, and a title assertion alone goes green against that too.
    $event = Event::factory()->create(['title' => 'Helldivers Friday', 'status' => EventStatus::Published]);

    $this->actingAs(User::factory()->create())
        ->get('/events')
        ->assertOk()
        ->assertSee('Helldivers Friday')
        ->assertSee('data-testid="rsvp-going-'.$event->event_key.'"', escape: false);
});

it('still answers when there is nothing scheduled', function () {
    $this->get('/events')
        ->assertOk()
        ->assertSee('being planned');
});

it('keeps the JSON listing available under its own path', function () {
    // The calendar page took `/events`; the JSON the bot and any future client read
    // moved rather than disappeared.
    Event::factory()->create(['title' => 'Helldivers Friday', 'status' => EventStatus::Published]);

    $this->actingAs(User::factory()->create())
        ->getJson('/api/events')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Helldivers Friday');
});
