<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
