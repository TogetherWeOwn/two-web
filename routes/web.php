<?php

use App\Http\Controllers\Auth\DiscordLoginController;
use App\Http\Controllers\Auth\StagingQaLoginController;
use App\Http\Controllers\DesignLab\HallmarkController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventIcsController;
use App\Http\Controllers\EventPageController;
use App\Http\Controllers\EventRssController;
use App\Http\Controllers\EventStatusController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RsvpController;
use App\Livewire\EventsCalendar;
use App\Livewire\PastEvents;
use Illuminate\Support\Facades\Route;

// The landing page reads the bot's counts and the featured rows moderators
// publish from /admin (TOG-54), so it is a controller rather than the
// `Route::view` it used to be. It stays in the `web` group: unlike `/discord` it
// is an ordinary page, and it needs the session to know whether to offer "your
// profile" or "log in with Discord".
Route::get('/', HomeController::class)->name('home');

// Non-production visual experiments. These routes share the homepage's real
// counts, featured content and join flow, but never replace the production page.
Route::get('/design-lab/hallmark', HallmarkController::class)->name('design-lab.hallmark');
Route::get('/design-lab/taste', [HomeController::class, 'taste'])->name('design-lab.taste');

// Public pages only. Keep this explicit: auth callbacks, signed-in profiles and
// event-detail URLs do not belong in the index, while the event collection does.
Route::get('/sitemap_index.xml', function () {
    $urls = [
        ['loc' => route('home'), 'changefreq' => 'weekly', 'priority' => '1.0'],
        ['loc' => route('join'), 'changefreq' => 'monthly', 'priority' => '0.9'],
        ['loc' => route('events.index'), 'changefreq' => 'daily', 'priority' => '0.8'],
        ['loc' => route('about'), 'changefreq' => 'monthly', 'priority' => '0.7'],
        ['loc' => route('rules'), 'changefreq' => 'monthly', 'priority' => '0.7'],
    ];

    return response()
        ->view('sitemap', ['urls' => $urls])
        ->header('Content-Type', 'application/xml; charset=UTF-8');
})->name('sitemap');

// Static about page. Dependency-free leaf (TOG-5310): no controller, no
// database, no Livewire — Route::view only, so it renders even when the bot's
// database is down.
Route::view('/about', 'about')->name('about');

// Static house rules. Dependency-free leaf (TOG-5147): no controller, no
// database, no Livewire — Route::view only, so it renders even when the bot's
// database is down.
Route::view('/rules', 'rules')->name('rules');

// One-click join needs the web session for OAuth state and for signing the new
// member in after Discord adds them. `/discord` remains the database-free invite
// fallback in routes/funnel.php.
Route::get('/join', [JoinController::class, 'show'])->name('join');
Route::get('/join/discord', [JoinController::class, 'redirect'])
    ->middleware('throttle:10,1')
    ->name('join.redirect');
Route::get('/join/callback', [JoinController::class, 'callback'])
    ->middleware('throttle:10,1')
    ->name('join.callback');

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

// The past-events archive. Public like the calendar: history is not a
// signed-in privilege, and the empty state pitches joining to guests. A
// literal, registered before the `auth` group below — otherwise `/events/past`
// would fall through to the `events.show` wildcard there and 302 a guest to
// the Discord handoff instead of showing them history.
Route::get('/events/past', PastEvents::class)->name('events.past');

// The shareable event page. A link passed around Discord lands here, so it is
// public: a guest sees the event plus a join pitch, never the OAuth handoff.
// `/e/{event}`, not `/events/{event}` — that path is the JSON show route in the
// `auth` group below, and one URL must not serve two media types (see the
// comment on `/events` above). The wildcard binds on `event_key`, same as the
// JSON route (Event::getRouteKeyName()); an unknown key is a 404 from the
// implicit binding, and a draft 403s for non-moderators via the policy.
Route::get('/e/{event}', EventPageController::class)->name('events.page');

// The per-event calendar download, on the same `event_key` binding. Public like
// the page: a calendar client fetching the URL has no session, so a login wall
// would make the download useless. The visibility rule is the same `view` policy
// the JSON route and the page enforce — published (and cancelled/past) for
// everyone, drafts for moderators only; an unknown key is a 404 from the
// implicit binding. Registered before the `auth` group below so `/events/{key}.ics`
// matches here instead of falling through to the JSON show route.
Route::get('/events/{event}.ics', EventIcsController::class)->name('events.ics');

// The collection as an RSS 2.0 feed. Same `.suffix` trick as the `.ics`
// download above: one URL, one media type, public like the page because a
// feed reader has no session.
Route::get('/events.rss', EventRssController::class)->name('events.rss');

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

// Staging's QA route is deliberately absent from every other environment. The
// controller repeats the environment check so a cached or manually registered
// route still fails closed, and it owns the secret comparison before fixture lookup.
if (app()->environment('staging')) {
    Route::get('/auth/qa/{identity}', StagingQaLoginController::class)
        ->middleware('throttle:10,1')
        ->name('qa.login');
}

// POST only. A logout on GET can be fired by any <img src> a member loads.
Route::post('/logout', [DiscordLoginController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    // The singular URL remains the post-login destination. Canonical member
    // profile URLs carry the user id so any signed-in member can share one with
    // another member without exposing the directory to logged-out visitors.
    Route::get('/profile', [ProfileController::class, 'mine'])->name('profile');
    Route::get('/members/{user}', [ProfileController::class, 'show'])->name('profiles.show');
    Route::patch('/members/{user}', [ProfileController::class, 'update'])->name('profiles.update');

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
