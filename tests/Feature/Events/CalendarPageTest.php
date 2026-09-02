<?php

use App\Models\Event;
use App\Models\User;

/**
 * The route, as opposed to the component.
 *
 * Livewire::test() constructs the component directly: it never touches the
 * router, the middleware stack or the layout the component renders inside. Every
 * other test in this directory would still pass if /calendar were behind `auth`,
 * or 404, or never registered at all — the component would render fine and the
 * page would be unreachable.
 *
 * So this file asserts the two things only a real request can: that the URL
 * exists and lets a guest in, and that the guest/member split survives the real
 * session guard rather than only the one Livewire fakes.
 */
it('serves the calendar to a guest', function (): void {
    // Not behind `auth` on purpose. The empty state's job is converting a visitor
    // who arrived from Discord, and a login wall means the only people who can
    // read "join the Discord" are the ones who already have.
    Event::factory()->create(['title' => 'Helldivers night']);

    $this->get('/calendar')
        ->assertOk()
        ->assertSee('Helldivers night')
        ->assertSee('Sign in with Discord')
        ->assertDontSee('data-testid="rsvp-button-', escape: false);
});

it('serves the calendar to a member, with a seat to take', function (): void {
    $event = Event::factory()->create(['title' => 'Helldivers night']);

    $this->actingAs(User::factory()->create())
        ->get('/calendar')
        ->assertOk()
        ->assertSee(sprintf('data-testid="rsvp-button-%s"', $event->event_key), escape: false)
        ->assertDontSee('Sign in with Discord');
});

it('serves the empty calendar rather than an error page', function (): void {
    // The state this site spends its first months in has to be a 200. An empty
    // page that 500s reads as broken in the most literal way available.
    $this->get('/calendar')
        ->assertOk()
        ->assertSee('Nothing on the calendar yet');
});
