<?php

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Say why an unsafe write bounced to the login handoff (TOG-8560).
 *
 * The `auth` middleware 302s every guest to `route('login')` — the Discord
 * OAuth handoff, which answers with a 302 of its own. A member who composed a
 * profile edit past SESSION_LIFETIME and hit submit watched their
 * bio/games/timezone vanish into the dead POST body with no sentence: the
 * handoff page carries no expired-session message, and nothing survives the
 * round trip to Discord and back.
 *
 * Rendered from the `AuthenticationException` handler in bootstrap/app.php, so
 * it fires wherever the `auth` middleware throws — including the dead session
 * the middleware never returns a response for. Unsafe browser submits
 * (POST/PATCH/PUT/DELETE, never GET, never JSON) keep the login redirect but
 * carry `auth_error=expired` — the same key the home banner reads — plus the
 * submitted input, so the message lands and the words survive re-login. JSON
 * callers keep the 401 the gate already answers (TOG-6944).
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

        // redirect()->guest(), not a bare RedirectResponse: guest() records the
        // previous page as url.intended first, which is what sends the member
        // back after re-login (redirect()->intended(route('profile')) in the
        // Discord callback). A bare redirect would answer the same 302 while
        // silently dropping that return trip.
        $response = redirect()->guest($exception->redirectTo($request) ?? route('login'));

        $response->with('auth_error', 'expired');
        $response->withInput($request->except(['_token', '_method', 'password', 'password_confirmation']));

        return $response;
    }
}
