<?php

/**
 * The join journey's half of the OAuth application's redirect URI list.
 *
 * Discord compares `redirect_uri` against its registered list character for
 * character. One wrong character and the visitor never reaches our code — they
 * stop at Discord's own error screen, where we cannot even apologise. So the
 * string we send is pinned by a test rather than by a line in an environment
 * file somebody has to remember to set correctly.
 *
 * These are the *join* URIs registered on the OAuth application (added by the
 * founder, 2026-08-20). Six rows were registered in total: these three, plus the
 * three /auth/discord/callback rows, which belong to
 * tests/Feature/Auth/DiscordRedirectUriTest.php and not to this file.
 *
 * They cannot be read back from Discord — a registered URI and a bogus one are
 * indistinguishable from outside — so this list is a copy, and changing it means
 * changing the application settings too.
 */
const REGISTERED_JOIN_REDIRECT_URIS = [
    'http://localhost:8000/join/callback',
    'http://127.0.0.1:8000/join/callback',
    'https://staging.togetherweown.com/join/callback',
];

beforeEach(function () {
    config([
        'services.discord.client_id' => 'test-client-id',
        'services.discord.client_secret' => 'test-client-secret',
        // One-click has to look available or the controller short-circuits to the
        // invite link and never builds a Discord URL at all.
        'services.bot.url' => 'http://127.0.0.1:3001',
        'services.bot.secret' => 'test-shared-secret-that-is-long-enough-32',
        'services.bot.key_id' => 'web-test',
    ]);
});

/** The Discord authorize URL this application sends somebody to, as a query bag. */
function joinAuthorizeQuery(string $url, ?string $forwardedProto = null): array
{
    $headers = $forwardedProto === null ? [] : ['X-Forwarded-Proto' => $forwardedProto];

    $location = (string) test()->get($url, $headers)->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

it('sends a join redirect_uri Discord has registered, from every address we serve', function (string $url, string $expected) {
    expect(joinAuthorizeQuery($url)['redirect_uri'] ?? '')
        ->toBe($expected)
        ->toBeIn(REGISTERED_JOIN_REDIRECT_URIS);
})->with([
    'local' => ['http://localhost:8000/join/discord', 'http://localhost:8000/join/callback'],
    'local, loopback' => ['http://127.0.0.1:8000/join/discord', 'http://127.0.0.1:8000/join/callback'],
]);

it('says https behind nginx on the join journey too', function () {
    // Staging and production terminate TLS at nginx, so PHP is handed an http
    // request for an https page. Untrusted, we would send Discord
    // `http://staging.togetherweown.com/...` — one character off the registered
    // URI, and every visitor is stopped at Discord's error screen.
    expect(joinAuthorizeQuery('http://staging.togetherweown.com/join/discord', 'https')['redirect_uri'] ?? '')
        ->toBe('https://staging.togetherweown.com/join/callback')
        ->toBeIn(REGISTERED_JOIN_REDIRECT_URIS);
});

it('takes the join callback path from the route, so the route and Discord cannot drift apart', function () {
    // Rename the route and this fails here rather than at Discord's error screen.
    expect(parse_url(route('join.callback'), PHP_URL_PATH))->toBe('/join/callback');
});

it('asks for guilds.join, and asks for nothing it does not need', function () {
    $scopes = explode(' ', (string) (joinAuthorizeQuery('http://localhost:8000/join/discord')['scope'] ?? ''));

    // `identify` names the member for the bot; `guilds.join` is the permission to
    // add them. Nothing else earns a line on this consent screen, and every extra
    // line is a reason to press Cancel.
    expect($scopes)->toEqualCanonicalizing(['identify', 'guilds.join']);
});

it('never asks a returning member for guilds.join at login', function () {
    // The one rule that keeps the two journeys separate. If `guilds.join` ever
    // leaks into the login scopes, every profile visit starts asking permission
    // to add people to servers.
    $location = (string) test()->get('http://localhost:8000/auth/discord/redirect')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(explode(' ', (string) ($query['scope'] ?? '')))->not->toContain('guilds.join');
});
