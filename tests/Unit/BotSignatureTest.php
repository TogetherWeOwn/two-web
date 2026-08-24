<?php

use App\Services\Bot\BotClient;

/**
 * A known-answer test for the HMAC on the bot's internal actions endpoint.
 *
 * The expected value below was **not** produced by this codebase. It came out of
 * the bot's own signer — two-bot `src/internal/signing.ts`, run over the inputs
 * pinned here — so this test is two independent implementations agreeing on a
 * fixed vector rather than PHP agreeing with itself.
 *
 * That distinction is the whole point. A signature bug is invisible from this
 * side: every disagreement about newlines, hex case, key order or UTF-8 presents
 * as a flat 401 with no hint, because the bot answers a wrong signature and an
 * unknown key id identically on purpose. Catching it here costs nothing; catching
 * it in production costs an afternoon of "the join button is broken".
 *
 * Regenerate, from a checkout of two-bot, with:
 *
 *   node --experimental-strip-types -e '
 *     import("./src/internal/signing.ts").then(s => {
 *       const raw = Buffer.from(BODY, "utf8");
 *       console.log(s.sign(SECRET, TIMESTAMP, NONCE, raw));
 *     })'
 */
const VECTOR_SECRET = 'test-shared-secret-that-is-long-enough-32';
const VECTOR_TIMESTAMP = '1787173135';
const VECTOR_NONCE = '9f1c0b7e2d4a6f8c1e3b5d7f9a0c2e40';
const VECTOR_BODY = '{"action":"guild.add_member","discord_id":"111222333444555666","access_token":"stub-access-token"}';
const VECTOR_SIGNATURE = 'sha256=265cfc2082f580224d754f01083f075d481a9e95d6e86ab0c26c6604118c0160';

it('produces the signature the bot produces, for the same inputs', function () {
    expect(BotClient::signature(VECTOR_SECRET, VECTOR_TIMESTAMP, VECTOR_NONCE, VECTOR_BODY))
        ->toBe(VECTOR_SIGNATURE);
});

it('serialises the payload to the same bytes the vector was signed over', function () {
    // The other half of the agreement. Signing is only correct if both sides also
    // build the same body — PHP inserting a space after a colon, or reordering
    // keys, would change the hash while every line of the signing code stayed
    // right. The client signs what it sends, so this is what it sends.
    expect(json_encode([
        'action' => 'guild.add_member',
        'discord_id' => '111222333444555666',
        'access_token' => 'stub-access-token',
    ]))->toBe(VECTOR_BODY);
});

it('changes the signature when any signed input changes', function (string $secret, string $timestamp, string $nonce, string $body) {
    // Each row alters exactly one of the four signed inputs. If any of them stops
    // moving the signature, that input has fallen out of the canonical string and
    // the bot's replay, skew or tamper checks are no longer doing anything.
    expect(BotClient::signature($secret, $timestamp, $nonce, $body))->not->toBe(VECTOR_SIGNATURE);
})->with([
    'secret' => [VECTOR_SECRET.'x', VECTOR_TIMESTAMP, VECTOR_NONCE, VECTOR_BODY],
    'timestamp' => [VECTOR_SECRET, '1787173136', VECTOR_NONCE, VECTOR_BODY],
    'nonce' => [VECTOR_SECRET, VECTOR_TIMESTAMP, str_repeat('a', 32), VECTOR_BODY],
    'body' => [VECTOR_SECRET, VECTOR_TIMESTAMP, VECTOR_NONCE, VECTOR_BODY.' '],
]);
