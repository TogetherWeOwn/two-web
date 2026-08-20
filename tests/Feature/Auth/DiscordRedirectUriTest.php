<?php

/**
 * Discord compares `redirect_uri` against its registered list character for
 * character. One wrong character and the member never reaches our code — they
 * stop at Discord's own error screen, where we cannot even apologise. So the
 * string we send is pinned by a test rather than by a line in an environment
 * file somebody has to remember to set correctly.
 *
 * These are the URIs registered on the OAuth application (added by the founder,
 * 2026-08-20). They cannot be read back from Discord — a registered URI and a
 * bogus one are indistinguishable from outside — so this list is a copy, and
 * changing it means changing the application settings too.
 *
 * `localhost` and `127.0.0.1` are different hosts to Discord, which is why both
 * are here. `http` is for local only.
 */
const REGISTERED_REDIRECT_URIS = [
    'http://localhost:8000/auth/discord/callback',
    'http://127.0.0.1:8000/auth/discord/callback',
    'https://staging.togetherweown.com/auth/discord/callback',
];

/**
 * The `redirect_uri` this application sends when the login page is opened at a
 * given address. `$forwardedProto` is what nginx tells us the member's browser
 * used, which is the only way we can know we are behind TLS.
 */
function redirectUriSentAt(string $url, ?string $forwardedProto = null): string
{
    $headers = $forwardedProto === null ? [] : ['X-Forwarded-Proto' => $forwardedProto];

    $location = (string) test()->get($url, $headers)->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return (string) ($query['redirect_uri'] ?? '');
}

it('sends a redirect_uri Discord has registered, from every address we serve', function (string $url, string $expected) {
    expect(redirectUriSentAt($url))->toBe($expected)->toBeIn(REGISTERED_REDIRECT_URIS);
})->with([
    'local' => ['http://localhost:8000/auth/discord/redirect', 'http://localhost:8000/auth/discord/callback'],
    'local, loopback' => ['http://127.0.0.1:8000/auth/discord/redirect', 'http://127.0.0.1:8000/auth/discord/callback'],
]);

it('says https behind nginx, even though the request reaches PHP as plain http', function () {
    // Staging and production terminate TLS at nginx, so PHP is handed an http
    // request for an https page. Untrusted, we would send Discord
    // `http://staging.togetherweown.com/...` — one character off the registered
    // URI, and every member is stopped at Discord's error screen. This is the
    // failure that only appears once the site is behind a real certificate.
    expect(redirectUriSentAt('http://staging.togetherweown.com/auth/discord/redirect', 'https'))
        ->toBe('https://staging.togetherweown.com/auth/discord/callback')
        ->toBeIn(REGISTERED_REDIRECT_URIS);
});

it('takes the callback path from the route, so the route and Discord cannot drift apart', function () {
    // Rename the route and this fails here rather than at Discord's error
    // screen. The path is the half of the URI that is ours to get wrong.
    expect(parse_url(route('login.callback'), PHP_URL_PATH))->toBe('/auth/discord/callback');
});
