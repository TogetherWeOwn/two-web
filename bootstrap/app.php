<?php

use App\Http\Middleware\AddContentSecurityPolicy;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\CompressStaticAssets;
use App\Http\Middleware\RecordMemberDataAccess;
use App\Support\ThrottleEnvelope;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // The machine ingress (TOG-5510/web): stateless `api` group, so no
        // session, no CSRF and no StartSession database connection before the
        // controller runs. One route today; nothing human belongs in here.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        // No `health:` parameter on purpose (TOG-8711). The framework's
        // built-in route fires DiagnosingHealth and renders a fixed HTML page
        // with no database signal: it answers 200 while Postgres is down or
        // migrations are pending, so a deploy with a failed migrate looks
        // healthy. routes/health.php registers the explicit `/up` probe
        // instead — a DB ping plus the pending-migration count as JSON, 503
        // when either is wrong — and the maintenance-mode exemption the
        // framework would have added for it lives in withMiddleware below.
        // The funnel, with a deliberately empty middleware stack. `/discord` has
        // to answer when the database is down, and every route in the `web` group
        // opens a database connection inside StartSession before the controller
        // runs, because SESSION_DRIVER=database everywhere we ship.
        // routes/funnel.php carries the full reasoning.
        then: function (): void {
            Route::middleware([])->group(__DIR__.'/../routes/funnel.php');
            Route::middleware([])->group(__DIR__.'/../routes/health.php');
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

        // The four static headers (TOG-7328) go on globally: every response
        // needs framing and sniffing protection — the funnel redirect, the
        // join page, the admin panel (which does not use `web`), JSON. This
        // is safe for the funnel only because AddSecurityHeaders reads
        // nothing: no config, no session, no cache, no database — the same
        // guarantee the funnel's zero-query test pins.
        $middleware->append(AddSecurityHeaders::class);

        // The site's CSP (TOG-6770) goes on `web`, not globally: the funnel
        // routes answer redirects/JSON that carry no body to protect, and the
        // deliberately empty funnel stack must stay empty so `/discord` keeps
        // answering during a database outage. Admin has its own middleware
        // stack (see AdminPanelProvider) and gets the same class there.
        $middleware->web(append: [AddContentSecurityPolicy::class]);

        // `/discord` is the break-glass route during a deploy. The one-click
        // `/join` flow needs a session and the bot, so maintenance mode must not
        // pretend it can complete; the plain invite remains available here.
        // `/up` joins it (TOG-8711): replacing the framework's `health: '/up'`
        // route with the explicit probe in routes/health.php dropped the
        // exemption the framework would have added, and deploys poll `/up`
        // while the site is down for maintenance — `PreventRequestsDuringMaintenance`
        // matches on the `up` URI, not the leading slash.
        $middleware->preventRequestsDuringMaintenance(except: ['discord', 'up']);

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
        // One 429 shape for every throttle (TOG-6788): the auth callbacks, the
        // RSVP writes and the machine ingress all throw
        // ThrottleRequestsException, but the framework's default rendering
        // answers JSON with a stack trace when debug is on and HTML with an
        // unbranded page. ThrottleEnvelope normalises both.
        $exceptions->render(
            fn (ThrottleRequestsException $exception, Request $request) => ThrottleEnvelope::render($request, $exception)
        );
    })->create();
