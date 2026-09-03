<?php

use App\Http\Controllers\Auth\DiscordLoginController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventStatusController;
use App\Http\Controllers\RsvpController;
use App\Livewire\EventsCalendar;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

// The events calendar (TOG-53). Deliberately outside the `auth` group below: the
// events list is the second thing this funnel has to show a stranger, after the
// join link, and making somebody sign in before they can see whether there is
// anything worth signing in for is the wrong way round. Nothing is leaked by it —
// EventPolicy still keeps drafts to moderators, and a guest gets a sign-in link
// where a member gets RSVP buttons.
Route::get('/events', EventsCalendar::class)->name('events');

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
    // The JSON listing moved to /api/events when the calendar page took /events.
    // It stays because it is the shape a client that is not our own page reads, and
    // because the UI and it go through the same EventService rather than each
    // growing a copy of the rules.
    Route::get('/api/events', [EventController::class, 'index'])->name('events.index');
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
