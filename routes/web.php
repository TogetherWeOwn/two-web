<?php

use App\Http\Controllers\Auth\DiscordLoginController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventStatusController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\RsvpController;
use App\Livewire\EventsCalendar;
use Illuminate\Support\Facades\Route;

// The landing page reads the bot's counts and the featured rows moderators
// publish from /admin (TOG-54), so it is a controller rather than the
// `Route::view` it used to be. It stays in the `web` group: unlike `/discord` it
// is an ordinary page, and it needs the session to know whether to offer "your
// profile" or "log in with Discord".
Route::get('/', HomeController::class)->name('home');

// The events page, and it is deliberately outside the `auth` group below.
//
// A signed-out visitor arriving from a link in Discord must land on the calendar,
// not on the OAuth handoff: the empty state is written as a pitch to join
// (two-design docs/COMPONENTS.md §8) and it cannot do that job behind a login.
// What the page will not show them is an RSVP button — the component asks them to
// log in instead, which is a different thing from a 302.
//
// The JSON collection keeps its own route below. One path cannot honestly be both
// a document and an API: content-negotiating `/events` on the Accept header gives
// crawlers and curl different answers, which is a trap this repo has already been
// bitten by once on the apex.
Route::get('/events', EventsCalendar::class)->name('events.index');

// Discord is the only way in, so the route Laravel redirects guests to *is* the
// Discord handoff. There is no login form to design because there is nothing to
// type. The callback path is fixed at /auth/discord/callback in every environment
// and is registered as a redirect URI on the one OAuth application.
Route::get('/auth/discord/redirect', [DiscordLoginController::class, 'redirect'])
    ->middleware('throttle:10,1')
    ->name('login');

Route::get('/auth/discord/callback', [DiscordLoginController::class, 'callback'])
    ->middleware('throttle:10,1')
    ->name('login.callback');

// POST only. A logout on GET can be fired by any <img src> a member loads.
Route::post('/logout', [DiscordLoginController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    // Placeholder. The real profile is TWO-29 (backend) and TWO-30 (UI); this
    // exists so signing in has somewhere to land. Delete it when they ship.
    Route::view('/profile', 'profile')->name('profile');

    // Events. The wildcard binds on `event_key`, not the autoincrement id — see
    // Event::getRouteKeyName(). That is the same string the bot keys its Discord
    // mirror on, so a URL a member shares and a row the bot updates name the same
    // thing, and nothing outside the database ever has to know the id exists.
    //
    // Publish and cancel are their own routes rather than a `status` field on the
    // update: announcing an event to the guild is a different act from correcting
    // its description, and cancelling is the one transition that cannot be undone.
    // The JSON collection. `/events.json`, not `/events`, because that path now
    // serves the HTML page (see above) and one URL answering with two media types
    // is how you end up with a crawler and a browser seeing different sites.
    Route::get('/events.json', [EventController::class, 'index'])->name('events.json');
    Route::post('/events', [EventController::class, 'store'])->name('events.store');
    Route::get('/events/{event}', [EventController::class, 'show'])->name('events.show');
    Route::patch('/events/{event}', [EventController::class, 'update'])->name('events.update');
    Route::post('/events/{event}/publish', [EventStatusController::class, 'publish'])->name('events.publish');
    Route::post('/events/{event}/cancel', [EventStatusController::class, 'cancel'])->name('events.cancel');

    // One answer per member per event, so the RSVP is a singular sub-resource:
    // there is no collection to list and no id to hand back.
    Route::put('/events/{event}/rsvp', [RsvpController::class, 'update'])->name('events.rsvp.update');
    Route::delete('/events/{event}/rsvp', [RsvpController::class, 'destroy'])->name('events.rsvp.destroy');
});

Route::fallback(fn () => response('Page Not Found'));
