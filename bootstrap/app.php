<?php

use App\Http\Middleware\AddContentSecurityPolicy;
use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\CompressStaticAssets;
use App\Http\Middleware\RecordMemberDataAccess;
use App\Support\ErrorAlertRateLimit;
use App\Support\ExpiredSessionEnvelope;
use App\Support\ThrottleEnvelope;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        // One 429 shape for every throttle (TOG-6788): the auth callbacks, the
        // RSVP writes and the machine ingress all throw
        // ThrottleRequestsException, but the framework's default rendering
        // answers JSON with a stack trace when debug is on and HTML with an
        // unbranded page. ThrottleEnvelope normalises both.
        $exceptions->render(
            fn (ThrottleRequestsException $exception, Request $request) => ThrottleEnvelope::render($request, $exception)
        );

        // An expired session mid-write (TOG-8560): the `auth` middleware throws
        // before any controller runs, so nothing downstream can flash a reason.
        // Unsafe browser submits keep the login redirect but store a durable
        // notice the callback reflashes as `auth_error` for the landing page.
        // Returning null falls through to the framework's default — JSON keeps
        // its 401 (TOG-6944), guest GETs and /admin keep the silent handoff.
        $exceptions->render(
            fn (AuthenticationException $exception, Request $request) => ExpiredSessionEnvelope::render($request, $exception)
        );

        // The log-based error alert (TOG-8730). The framework already logs
        // every reported exception through its own channel; what is missing
        // is the surfacing — a 500 on staging reads as silence until a member
        // reports it. This listener is the alert: one operator-greppable
        // critical line per distinct failure, with the class, route and
        // exception message as structured context, so whatever tails the log
        // on the box sees it — the same tradition as the `Queue::failing`
        // listener in AppServiceProvider (TOG-6948). No paid service, no
        // webhook, no credential: the log line is the channel, and
        // `bin/error-log-watch.sh` is the pager on the box side.
        //
        // `report` runs after the framework decides the exception is worth
        // reporting — the internal dont-report list (404s, 403s, validation,
        // throttles) never reaches us, so ordinary client errors stay quiet
        // and only genuine 500s alert. ErrorAlertRateLimit then mutes repeats
        // of the same fingerprint (one alert per 5 minutes per class+route),
        // so a crashing deploy produces one line, not thousands.
        $exceptions->report(function (Throwable $exception): void {
            $route = app('router')->current()?->getName()
                ?? request()->route()?->getName()
                ?? request()->path();

            $fingerprint = ErrorAlertRateLimit::fingerprint($exception, $route);

            if (! ErrorAlertRateLimit::shouldAlert($fingerprint)) {
                return;
            }

            Log::critical('Unhandled exception.', [
                'exception' => get_class($exception),
                'route' => $route,
                'message' => $exception->getMessage(),
            ]);
        });
    })->create();
