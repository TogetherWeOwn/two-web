<?php

use App\Http\Middleware\RecordMemberDataAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The funnel, with a deliberately empty middleware stack. `/discord` has
        // to answer when the database is down, and every route in the `web` group
        // opens a database connection inside StartSession before the controller
        // runs, because SESSION_DRIVER=database everywhere we ship.
        // routes/funnel.php carries the full reasoning.
        then: function (): void {
            Route::middleware([])->group(__DIR__.'/../routes/funnel.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The join link is the only web-to-Discord path TWO has, so it stays up
        // through a deploy. Without this, `php artisan down` — an ordinary step
        // in a release — answers it with a 503, which is the outage TOG-77 exists
        // to prevent. `/up` is already excepted by `health:` above.
        $middleware->preventRequestsDuringMaintenance(except: ['discord', 'join']);

        // nginx terminates TLS and hands PHP-FPM a plain http request, so without
        // this the application believes every https page is http. That breaks the
        // Discord login outright: the `redirect_uri` we send has to match the
        // registered one character for character, and `http://staging...` does
        // not match `https://staging...`. It would also put http URLs in every
        // link we generate.
        //
        // Trusting any proxy is safe here only because nginx is the one thing
        // that can reach PHP-FPM — it listens on a local socket, not the network.
        // If that ever stops being true, name the proxy address here instead.
        $middleware->trustProxies(at: '*');

        // Goes on the admin panel's stack, not on `web`. Every screen that reads
        // member data must carry it — see docs/member-data-access-log.md.
        $middleware->alias([
            'member-access-log' => RecordMemberDataAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
