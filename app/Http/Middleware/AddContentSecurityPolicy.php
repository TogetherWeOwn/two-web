<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Emits the site's Content-Security-Policy on HTML responses (TOG-6770).
 *
 * The other security headers live statically in nginx.template.conf, but CSP
 * cannot live there: the development allowance below (`npm run dev` serves the
 * bundle from a hot-reload server instead of `public/build`) is per-request
 * state PHP can see and nginx cannot, and a middleware is what the feature
 * suite can assert on — there is no test harness for nginx config.
 *
 * The policy is deliberately conservative (`default-src 'self'`) with exactly
 * three classes of exception, each earned by something the site actually does:
 *
 * - `script-src 'unsafe-inline'`: Livewire renders `@livewireScriptConfig`
 *   (its CSRF/update-URI bootstrap) and every `@script` block (e.g. the RSVP
 *   focus-restore handler) as inline `<script>` tags with no nonce hook, and
 *   the app layout defers the Livewire runtime behind a small inline loader
 *   so it stays out of the LCP path. Nonces would be stronger, but Livewire
 *   gives us nowhere to put one on these tags short of vendoring its asset
 *   pipeline — so inline scripts stay allowed and the protection comes from
 *   `default-src 'self'` plus `object-src 'none'` keeping plugins out.
 * - `script-src 'unsafe-eval'`: Livewire's embedded Alpine evaluates
 *   `wire:click="..."` / `wire:submit="..."` expressions via `new Function`,
 *   which CSP counts as eval. Without this every calendar and RSVP
 *   interaction throws and dies silently. The eval surface is Alpine's own
 *   expression parser, not arbitrary page script.
 * - `style-src 'unsafe-inline'`: Livewire injects its loading/offline `<style>`
 *   block without a nonce, and the featured-content admin preview renders
 *   inline `style=""` attributes. There are no inline `<style>` tags in our
 *   own views and no `style=` attributes outside the admin preview.
 * - `img-src ... https:`: member avatars come from `cdn.discordapp.com` and
 *   moderators paste arbitrary https photo URLs into featured content, so
 *   images cannot be pinned to `'self'`. `https:` still bars `http:` (no
 *   mixed-content downgrade) and, with `object-src 'none'`, images cannot
 *   become plugin execution.
 *
 * `frame-ancestors 'none'` mirrors the `X-Frame-Options: DENY` nginx already
 * sends (nothing frames this site). There is deliberately NO `form-action`
 * directive, and this is an explicit risk-accepted tradeoff, not an oversight:
 * an explicit `form-action 'self'` was tried and reverted (TOG-7095) because
 * Chrome 131 blocks the admin logout POST with it — same scheme, host and
 * port on both ends, single served header, no meta policy, no base tag —
 * while the mechanically identical site sign-out POST passes, and Dusk proves
 * it (red with the directive, green without). Root cause undetermined
 * (suspect: the topbar's teleported duplicate logout form interacting with
 * the directive). The CISO sign-out bar (TOG-5469 V4) requires a working
 * logout journey, and a security header that breaks the security-critical
 * logout flow is worse than the narrow gap its absence leaves.
 *
 * The gap, stated plainly so nobody has to re-derive it: unlike the fetch
 * directives, `form-action` does NOT fall back to `default-src` (CSP3 §6.6.1.3
 * — the first version of this comment claimed otherwise, and the reviewer was
 * right to reject it), so without the directive forms may submit anywhere.
 * The bound on that gap: our own forms are same-origin by construction
 * (`route()` URLs; Discord OAuth leaves via 302 redirects, not form posts),
 * and a script-injection attacker — the only party who could plant a foreign
 * form — already holds better exfil channels under this same policy:
 * `img-src ... https:` (required by Discord CDN avatars and moderator-pasted
 * photo URLs) permits a beacon to any https host, and `connect-src 'self'`
 * still bars fetch/XHR exfil. `form-action` would close only form-POST exfil,
 * which is not the cheapest channel on offer. Revisit if the policy ever
 * tightens img-src or script-src, or if the Chrome behaviour gets a root
 * cause and a targeted fix.
 *
 * `upgrade-insecure-requests` is emitted on https requests only. HSTS already
 * forces https on staging/production, so nothing is lost there.
 *
 * Report-only mode (TOG-8403): `CSP_REPORT_ONLY=true` swaps the enforcing
 * header for `Content-Security-Policy-Report-Only` with the same policy plus
 * `report-uri /csp-reports`, so violations are logged (see
 * CspReportController) without blocking. Enforcement behaviour with the flag
 * off is byte-identical to before — the toggle only changes the header name
 * and appends the report directive.
 *
 * Registered on the `web` group and on the Filament admin stack (which does
 * not use `web`), so every HTML page in both stacks carries it. Anything that
 * is not HTML — redirects, JSON, the ICS feed, Livewire's own JS route — is
 * left alone: a policy header on a 302 or a JSON body protects nothing and
 * only confuses caches.
 */
class AddContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Never double-set: an inner layer that already spoke wins.
        if ($response->headers->has('Content-Security-Policy')
            || $response->headers->has('Content-Security-Policy-Report-Only')) {
            return $response;
        }

        if (! str_contains((string) $response->headers->get('Content-Type', ''), 'text/html')) {
            return $response;
        }

        // Report-only mode (TOG-8403): same policy, non-enforcing header, plus
        // the report directive so violations land in POST /csp-reports.
        // `config()` reads env with no session/cache/database, so this stays
        // safe on the funnel's dependency-free routes.
        if ((bool) config('csp.report_only', false)) {
            $response->headers->set('Content-Security-Policy-Report-Only', $this->policy($request).'; report-uri /csp-reports');

            return $response;
        }

        $response->headers->set('Content-Security-Policy', $this->policy($request));

        return $response;
    }

    private function policy(Request $request): string
    {
        $script = ["'self'", "'unsafe-inline'", "'unsafe-eval'"];
        $connect = ["'self'"];

        if (Vite::isRunningHot()) {
            // `npm run dev`: the bundle and its HMR channel come from the Vite
            // server, not from this origin. The hot file only exists while a
            // developer is actively running the dev server, so production and
            // CI (which build for real) always get the strict policy. Without
            // this allowance the dev layout is a blank page with console
            // errors, which is how the exception gets silently widened next
            // time someone is in a hurry.
            $script[] = 'http://localhost:5173';
            $script[] = 'http://[::1]:5173';
            $connect[] = 'http://localhost:5173';
            $connect[] = 'http://[::1]:5173';
            $connect[] = 'ws://localhost:5173';
            $connect[] = 'ws://[::1]:5173';
        }

        $directives = [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            'connect-src '.implode(' ', $connect),
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ];

        // https only (see the class docblock). `$request->isSecure()`
        // honours the trusted-proxy `X-Forwarded-Proto` nginx sets, so this
        // fires on staging/production where TLS terminates at the proxy.
        if ($request->isSecure()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }
}
