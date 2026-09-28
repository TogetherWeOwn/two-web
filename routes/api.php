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
 */

Route::post('/agent-events', [AgentEventController::class, 'store'])->name('api.agent-events');
