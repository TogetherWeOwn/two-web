<?php

use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DiscordInviteController;
use App\Http\Controllers\PrivacyController;
use App\Http\Middleware\AddContentSecurityPolicy;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The funnel
|--------------------------------------------------------------------------
|
| `/discord` is the database-free floor under the web-to-Discord funnel
| (TOG-77, docs/dns.md). `/about` lives here too (TOG-6853), as does `/faq`
| (TOG-8396): static `Route::view` leaves with no controller, no query and no
| Livewire, and they must stay 200 when the app database is down. `/privacy`
| (TOG-8609) lives here too: a controller rather than a static view because it
| renders the versioned content/privacy-policy-v*.md source, but its one read
| is a file from disk — no session, no cache, no database.
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
| tests/Feature/DiscordFunnelTest.php, tests/Feature/AboutPageTest.php and
| tests/Feature/FaqPageTest.php pin this — they assert zero database queries
| with a database-backed session configured, so moving these into `web.php`
| for neatness fails the build instead of failing a member.
|
| Nothing else belongs in this file. Anything that needs a session, a member, or
| the database goes in routes/web.php where it can have them.
*/

Route::get('/discord', DiscordInviteController::class)->name('discord');

// CSP violation sink (TOG-8403). Empty middleware stack like everything else
// here, on purpose: the browser fires this session-free from any page, and it
// must keep answering during an app-DB outage — a report is logged, never
// stored, so there is nothing to query. Deliberately no `throttle` middleware
// either (it reads the cache store, which is the database everywhere shipped).
// Flood control lives in CspReportController instead: 8 KB body cap, a fixed
// logged key set (never the raw body), sampling, and always-204.
Route::post('/csp-reports', CspReportController::class)->name('csp-reports');

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

// Static FAQ page (TOG-8396). Same dependency-free leaf pattern as `/about`
// above: Route::view only, no controller, no database, no Livewire — it serves
// the published questions from content/faq-preview.md and
// content/faq-preview-part-2.md and stays 200 during an app-DB outage. The CSP
// middleware is attached to the route alone for the same reason: it answers
// with an HTML document and reads no session, cache or database.
Route::view('/faq', 'faq')->middleware(AddContentSecurityPolicy::class)->name('faq');

// Public privacy policy (TOG-8609). Same session-free funnel placement as
// `/about` and `/faq` above: a controller rather than Route::view because the
// page renders the versioned content/privacy-policy-v*.md source, but the one
// read is a file from disk — no session, no cache, no database — so it stays
// 200 during an app-DB outage. The CSP middleware is attached to the route
// alone for the same reason: it answers with an HTML document.
Route::get('/privacy', PrivacyController::class)->middleware(AddContentSecurityPolicy::class)->name('privacy');
