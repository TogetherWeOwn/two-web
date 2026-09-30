<?php

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Say why an unsafe write bounced to the login handoff (TOG-8560).
 *
 * The `auth` middleware 302s every guest to `route('login')` — the Discord
 * OAuth handoff, which answers with a 302 of its own. A member who hit submit
 * past SESSION_LIFETIME watched their words vanish into the dead POST body
 * with no sentence: the handoff carries no message, and a flash would die in
 * the callback before the landing page.
 *
 * Rendered from the `AuthenticationException` handler in bootstrap/app.php, so
 * it fires wherever the `auth` middleware throws. Unsafe browser submits
 * (POST/PATCH/PUT/DELETE, never GET, never JSON, never /admin) keep the login
 * redirect but store a durable `expired_session_notice` — `put`, not flash,
 * so it survives the OAuth round trip. The login callback reflashes
 * `auth_error=expired` (the home banner's key) for the landing page, which
 * renders it via `partials/auth-error`. JSON callers keep the 401 the gate
 * already answers (TOG-6944); guest GETs and the Filament panel keep the
 * silent handoff.
 */
final class ExpiredSessionEnvelope
{
    public static function render(Request $request, AuthenticationException $exception): ?Response
    {
        if ($request->expectsJson()) {
            return null;
        }

        if ($request->isMethodCacheable()) {
            return null;
        }

        // Filament has its own auth flow (/admin, no login form, 403 for
        // non-moderators). Keep its handoff byte-identical; this envelope is
        // for member pages only.
        if ($request->is('admin*')) {
            return null;
        }

        if (! $request->hasSession()) {
            return null;
        }

        // Durable, not flashed: a flash ages on the login-redirect request and
        // dies in the callback before the landing page. `put` survives until
        // the callback pulls it and reflashes `auth_error` for the landing.
        $request->session()->put('expired_session_notice', true);

        // redirect()->guest(), not a bare RedirectResponse: guest() records the
        // previous page as url.intended first, which is what sends the member
        // back after re-login (redirect()->intended(route('profile')) in the
        // Discord callback). A bare redirect would answer the same 302 while
        // silently dropping that return trip.
        return redirect()->guest($exception->redirectTo($request) ?? route('login'));
    }
}
