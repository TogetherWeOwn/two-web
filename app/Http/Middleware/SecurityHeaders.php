<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The security headers from docs/dns.md, set in the application rather than in
 * nginx.
 *
 * docs/dns.md specifies these "set in nginx on the origin so they apply to
 * staging and the apex alike". That was written when the origin was assumed to
 * exist. It does not: there is no nginx configuration anywhere in this
 * repository, no provisioned VPS, and `.github/workflows/deploy.yml` still
 * no-ops on an unset deploy hook. A header that lives only in an unwritten
 * config file on an unprovisioned box protects nobody, and it is invisible to
 * CI — nothing can fail when it is missing.
 *
 * Here it ships with the code, is covered by the test suite, and travels to
 * whatever serves this application without a second artifact having to be
 * remembered. When the origin is finally provisioned, nginx may set these too;
 * duplicate identical headers are harmless, and the day nginx and this file
 * disagree the application is the one that is version-controlled and tested.
 *
 * Two headers named in that document are deliberately absent:
 *
 *   - **Content-Security-Policy.** It has to be written against the real asset
 *     origins with the Frontend Engineer, report-only first. A guessed CSP
 *     silently breaks the page it is meant to protect, which is worse than not
 *     having one yet.
 *   - **HSTS on plaintext.** See config/security.php.
 *
 * This is applied to the funnel routes as well as the `web` group, and that is
 * safe to do only because of what it does not touch. routes/funnel.php has a
 * deliberately empty middleware stack so `/discord` answers when Postgres is
 * down (TOG-77); anything added there must open no connection. Every value read
 * here comes from `config()`, which is loaded from files on disk — or from one
 * cached PHP file in production — and never from the database, the cache store
 * or the session. tests/Feature/DiscordFunnelTest.php pins that guarantee with
 * an assertion of zero queries, so if this class ever grows a database read the
 * build goes red rather than the funnel going down.
 */
class SecurityHeaders
{
    /**
     * Headers that cost nothing and are the same on every response.
     *
     * @var array<string, string>
     */
    public const STATIC_HEADERS = [
        // Stops a browser from second-guessing a declared content type, which
        // is how a user-uploaded file gets treated as script.
        'X-Content-Type-Options' => 'nosniff',

        // Referrers leak the page someone came from. Cross-origin gets the
        // origin only; same-origin keeps the full path.
        'Referrer-Policy' => 'strict-origin-when-cross-origin',

        // We are never framed. Clickjacking on a page with a "join" button that
        // adds you to a Discord server is a real attack, not a theoretical one.
        'X-Frame-Options' => 'DENY',

        // We ask for none of these. Saying so denies them to anything embedded.
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::STATIC_HEADERS as $header => $value) {
            $response->headers->set($header, $value);
        }

        if (! config('security.indexable')) {
            // Every form a crawler understands, because `noindex` alone still
            // permits following links onward into the rest of staging.
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        if ($request->isSecure()) {
            $hsts = 'max-age='.config('security.hsts.max_age');

            if (config('security.hsts.include_subdomains')) {
                $hsts .= '; includeSubDomains';
            }

            $response->headers->set('Strict-Transport-Security', $hsts);
        }

        return $response;
    }
}
