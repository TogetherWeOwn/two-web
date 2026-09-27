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
 * directive: form submissions fall back to `default-src 'self'`, so they stay
 * same-origin (Discord OAuth leaves via 302 redirects, not form posts, so it
 * is unaffected either way). An explicit `form-action 'self'` was tried and
 * reverted (TOG-7095): Chrome 131 blocks the admin logout POST — same scheme,
 * host and port on both ends, single served header, no meta policy, no base
 * tag — while the mechanically identical site sign-out POST passes. Root
 * cause undetermined (suspect: the topbar's teleported duplicate logout form
 * interacting with the directive); the CISO sign-out bar (TOG-5469 V4) requires
 * a working logout journey, which outranks a directive whose fallback already
 * enforces the same bound. `upgrade-insecure-requests` is emitted on https
 * requests only: on an http origin it upgrades the page while forms still
 * target http. HSTS already forces https on staging/production, so nothing is
 * lost there.
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
        if ($response->headers->has('Content-Security-Policy')) {
            return $response;
        }

        if (! str_contains((string) $response->headers->get('Content-Type', ''), 'text/html')) {
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

        // https only (see the class docblock): on an http origin this
        // directive upgrades the page while forms still target http, and
        // `form-action 'self'` then blocks the POST. `$request->isSecure()`
        // honours the trusted-proxy `X-Forwarded-Proto` nginx sets, so this
        // fires on staging/production where TLS terminates at the proxy.
        if ($request->isSecure()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }
}
