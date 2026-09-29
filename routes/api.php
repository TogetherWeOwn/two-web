<?php

use App\Http\Controllers\AgentEventController;
use Illuminate\Support\Facades\Route;

/*
 | The machine ingress (TOG-5510/web, Gate 2).
 |
 | One route: typed JSON in, typed JSON out, bearer credential on the
 | Authorization header. Registered through the `api` group in
 | bootstrap/app.php, so no session, no CSRF and no browser middleware ever
 | touches a machine call. Human routes are unchanged in routes/web.php.
 |
 | When the grant is disabled in every environment this answers 404 from the
 | service, so a staging dump restored somewhere unconfigured exposes nothing.
 |
 | TOG-8402: the outer route shield in front of the service's per-grant
 | budgets. `throttle:agent-events` counts every hit per credential before
 | auth, the grant lookup and the audit write, so an unauthenticated flood is
 | refused by a cache check instead of spending a database row per hit. It
 | throws the same ThrottleRequestsException the service limiter throws, so
 | the 429 envelope (TOG-6788) is one shape whichever layer fires.
 */

Route::post('/agent-events', [AgentEventController::class, 'store'])
    ->middleware('throttle:agent-events')
    ->name('api.agent-events');
