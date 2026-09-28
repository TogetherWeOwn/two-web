<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emits the static security headers on every response (TOG-7328).
 *
 * The same four headers live statically in nginx.template.conf, so why emit
 * them here too? Because nothing in CI can see the nginx config — there is no
 * test harness for it — while a middleware is what the feature suite can
 * assert on. The app copy is the tested one; nginx is the edge one. The two
 * must agree byte for byte, and tests/Feature/SecurityHeadersTest.php pins
 * both sides so drift fails the build instead of silently halving the
 * protection. Duplicate identical headers at the edge are harmless.
 *
 * Registered globally, not on `web`: the Filament admin stack does not use
 * `web` (see AdminPanelProvider) and the funnel routes run with a deliberately
 * empty middleware stack (routes/funnel.php), but the join link and the admin
 * panel are exactly the responses that most need framing and sniffing
 * protection. Global middleware still runs for those routes — the funnel's
 * `gatherMiddleware() === []` pin only sees route-level middleware, so it
 * keeps passing unchanged.
 *
 * Values are class constants read from nowhere: no config, no session, no
 * cache, no database. That is what keeps the funnel's zero-query guarantee
 * (DiscordFunnelTest: `/discord` answers with a database-backed session
 * configured) and the maintenance-mode exemption intact — this layer cannot
 * query or throw, on any route, in any outage.
 *
 * Deliberately NOT set here: Content-Security-Policy (AddContentSecurityPolicy
 * owns it — the Vite hot-reload allowance is per-request state only PHP can
 * see, and the policy is HTML-only while these headers protect every
 * response), Strict-Transport-Security (meaningless over plaintext and
 * dangerous from `php artisan serve`, which would pin the developer's whole
 * machine to HTTPS for a year — nginx owns it), and X-Robots-Tag
 * (environment-conditional indexing policy, a separate concern).
 */
class AddSecurityHeaders
{
    /**
     * The headers, byte-identical to the server block in nginx.template.conf.
     *
     * - `X-Content-Type-Options: nosniff` stops a browser second-guessing a
     *   declared content type, which is how an uploaded file gets treated as
     *   script.
     * - `Referrer-Policy: strict-origin-when-cross-origin` sends the origin
     *   only cross-origin and the full path same-origin.
     * - `X-Frame-Options: DENY`, not SAMEORIGIN: nothing frames this site, and
     *   DENY is what the CISO session-handling bar (TOG-5469) pins. Mirrored
     *   by `frame-ancestors 'none'` in the CSP.
     * - `Permissions-Policy` denies the three sensors anything embedded could
     *   otherwise request.
     *
     * @var array<string, string>
     */
    public const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Frame-Options' => 'DENY',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $header => $value) {
            $response->headers->set($header, $value);
        }

        return $response;
    }
}
