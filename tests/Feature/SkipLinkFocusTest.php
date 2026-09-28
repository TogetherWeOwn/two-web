<?php

use App\Enums\EventStatus;
use App\Models\Event;

/*
 * The public focus pass (TOG-6932), served half. Pins the skip-to-content
 * link at the rendered-page level on all six public pages: home, /events,
 * the event share page, /join, /about, /rules. Needs Postgres (RefreshDatabase
 * + the event fixture); the static half — #main tabindex, the app.css ring
 * override, the calendar region role — lives in
 * tests/Unit/SkipLinkFocusContractTest.php and runs without a database.
 */

it('links skip-to-content at #main on every public page', function (string $url) {
    $this->get($url)
        ->assertOk()
        ->assertSee('Skip to content', escape: false)
        ->assertSee('href="#main"', escape: false)
        ->assertSee('id="main"', escape: false);
})->with([
    'home' => ['/'],
    'about' => ['/about'],
    'rules' => ['/rules'],
    'join' => ['/join'],
    'events' => ['/events'],
]);

it('links skip-to-content at #main on a published event page', function () {
    $event = Event::factory()->create(['status' => EventStatus::Published]);

    $this->get(route('events.page', $event))
        ->assertOk()
        ->assertSee('Skip to content', escape: false)
        ->assertSee('href="#main"', escape: false)
        ->assertSee('id="main"', escape: false);
});
