<?php

use App\Http\Controllers\HealthCheckController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The health probe (TOG-8711)
|--------------------------------------------------------------------------
|
| `/up` is what the deploy pipeline and CI poll, so it answers with the
| database signal, not just the HTTP signal: a DB ping plus the pending
| migration count as JSON, 503 when either is wrong. See HealthCheckController
| for the contract.
|
| It lives here rather than in routes/web.php for the same reason the funnel
| routes live in routes/funnel.php: SESSION_DRIVER=database in every
| environment we ship, so a `web`-group route opens Postgres in StartSession
| before the controller runs — and the day Postgres is down that throws a 500
| before the probe can answer its structured 503. This stack stays empty so
| the controller is the thing that touches the database, catches the failure,
| and reports it.
|
| It is NOT Laravel's `health: '/up'` route: the framework renders a fixed
| HTML page with no database signal, so bootstrap/app.php leaves `health`
| unset and this explicit route is the only `/up`. The maintenance-mode
| exemption the framework would have added is carried explicitly instead
| (`preventRequestsDuringMaintenance` in bootstrap/app.php), so deploys can
| still poll while the site is down for maintenance.
*/

Route::get('/up', HealthCheckController::class);
