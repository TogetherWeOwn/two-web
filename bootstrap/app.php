<?php

use App\Http\Middleware\AddContentSecurityPolicy;
use App\Http\Middleware\CompressStaticAssets;
use App\Http\Middleware\RecordMemberDataAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // The machine ingress (TOG-5510/web): stateless `api` group, so no
        // session, no CSRF and no StartSession database connection before the
        // controller runs. One route today; nothing human belongs in here.
        api: __DIR__.'/../routes/api.php',
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
        // Gzips the text assets PHP serves itself. In practice that is one file and
        // it is the reason this exists: Livewire's 162 KB runtime comes from a PHP
        // route rather than from `public/`, so nginx never sees it as a file and
        // nothing in front of the application can compress it. It went over the
        // wire uncompressed, which is most of the /events LCP breach on TOG-53.
        //
        // Global rather than on `web`, because the package registers
        // `/livewire/livewire.min.js` outside every group — putting it on `web`
        // would miss the only asset it is for. It is also the reason this is safe
        // to run globally: it touches nothing but text-typed responses to clients
        // that asked for gzip, and the funnel routes answer with redirects.
        // CompressStaticAssets carries the measurements and the reasoning.
        $middleware->append(CompressStaticAssets::class);

        // The site's CSP (TOG-6770) goes on `web`, not globally: the funnel
        // routes answer redirects/JSON that carry no body to protect, and the
        // deliberately empty funnel stack must stay empty so `/discord` keeps
        // answering during a database outage. Admin has its own middleware
        // stack (see AdminPanelProvider) and gets the same class there.
        $middleware->web(append: [AddContentSecurityPolicy::class]);

        // `/discord` is the break-glass route during a deploy. The one-click
        // `/join` flow needs a session and the bot, so maintenance mode must not
        // pretend it can complete; the plain invite remains available here.
        $middleware->preventRequestsDuringMaintenance(except: ['discord']);

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

        // URL generation uses the request host, including X-Forwarded-Host.
        // Only this deployment's APP_URL host may reach routes: otherwise an
        // attacker can poison canonical links and Discord callback URLs. Do not
        // implicitly trust sibling/subdomains. Laravel exempts local/testing.
        $middleware->trustHosts(
            at: fn (): array => ['^'.preg_quote((string) parse_url(config('app.url'), PHP_URL_HOST)).'$'],
            subdomains: false,
        );

        // Goes on the admin panel and member-profile routes, not on `web`.
        // Every member-data screen must carry it — see docs/member-data-access-log.md.
        $middleware->alias([
            'member-access-log' => RecordMemberDataAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
