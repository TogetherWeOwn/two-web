<?php

use App\Http\Controllers\DiscordInviteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The funnel
|--------------------------------------------------------------------------
|
| Two routes, and they are the most important two in the application: they are
| the only web-to-Discord conversion path TWO has (TOG-77, docs/dns.md).
|
| **These are not in routes/web.php on purpose.** bootstrap/app.php loads this
| file with an empty middleware stack, so nothing in the `web` group runs here.
| That is not tidiness — it is the requirement. `SESSION_DRIVER=database` and
| `CACHE_STORE=database` in every environment we ship, so a route in the `web`
| group opens a Postgres connection in `StartSession` before the controller is
| reached. Put `/discord` in that group and the day Postgres is down is the day
| the join link returns a 500. Same reasoning rules out `throttle`, which reads
| the cache store: there is no user input on these routes to abuse, and the
| edge already rate-limits.
|
| tests/Feature/DiscordFunnelTest.php pins this — it asserts zero database
| queries with a database-backed session configured, so moving these into
| `web.php` for neatness fails the build instead of failing a member.
|
| Nothing else belongs in this file. Anything that needs a session, a member, or
| the database goes in routes/web.php where it can have them.
*/

Route::get('/discord', DiscordInviteController::class)->name('discord');

// 301, unlike /discord above: this one really is permanent. `/join` on the
// vanity domain is already in stream titles and DMs and currently lands on the
// WordPress soft 404, and docs/dns.md settles the name — one door, and it is
// called `/discord`. A permanent redirect is how the browsers and the crawlers
// are told, and there is no future in which /join means anything else.
//
// The vanity domain's `/join` redirect rule is a founder click we cannot make
// (docs/dns.md, "Who can actually change these records"). This route is what
// makes that click optional: whether the rule forwards the path unchanged or
// rewrites it to `/discord`, the person ends up in the same place.
Route::permanentRedirect('/join', '/discord')->name('join');
