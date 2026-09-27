<?php

use App\Http\Controllers\DiscordInviteController;
use App\Http\Middleware\AddContentSecurityPolicy;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The funnel
|--------------------------------------------------------------------------
|
| `/discord` is the database-free floor under the web-to-Discord funnel
| (TOG-77, docs/dns.md). `/about` lives here too (TOG-6853): it is a static
| `Route::view` leaf with no controller, no query and no Livewire, and it must
| stay 200 when the app database is down.
|
| **These are not in routes/web.php on purpose.** bootstrap/app.php loads this
| file with an empty middleware stack, so nothing in the `web` group runs here.
| That is not tidiness — it is the requirement. `SESSION_DRIVER=database` and
| `CACHE_STORE=database` in every environment we ship, so a route in the `web`
| group opens a Postgres connection in `StartSession` before the controller is
| reached. Put `/discord` or `/about` in that group and the day Postgres is down
| is the day the join link — or the about page — returns a 500. Same reasoning
| rules out `throttle`, which reads the cache store: there is no user input on
| these routes to abuse, and the edge already rate-limits.
|
| tests/Feature/DiscordFunnelTest.php and tests/Feature/AboutPageTest.php pin
| this — they assert zero database queries with a database-backed session
| configured, so moving these into `web.php` for neatness fails the build
| instead of failing a member.
|
| Nothing else belongs in this file. Anything that needs a session, a member, or
| the database goes in routes/web.php where it can have them.
*/

Route::get('/discord', DiscordInviteController::class)->name('discord');

// Static about page (TOG-5310, TOG-6853). Dependency-free leaf: no controller,
// no database, no Livewire — Route::view only, so it renders even when the
// app's database is down. It lives here rather than in routes/web.php because
// every route in the `web` group opens Postgres in StartSession
// (SESSION_DRIVER=database everywhere shipped) before the view runs.
//
// The CSP middleware (TOG-6770) is attached to this route alone, not to the
// file's stack: unlike `/discord`'s redirect, `/about` answers with an HTML
// document, and leaving the `web` group must not strip its document policy.
// It reads no session, cache or database — `Vite::isRunningHot()` checks only
// the hot file on disk — so it stays safe during an app-DB outage.
Route::view('/about', 'about')->middleware(AddContentSecurityPolicy::class)->name('about');
