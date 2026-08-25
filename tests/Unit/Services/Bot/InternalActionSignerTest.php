<?php

use App\Services\Bot\InternalActionSigner;

// The signature is the whole security boundary between this site and the bot, so
// it gets a test with numbers in it rather than a test that agrees with itself.
//
// Every expected value below was produced *outside* this codebase, with openssl:
//
//   BODY='{"action":"event.upsert","event_key":"movie-night-2026-09-01"}'
//   DIGEST=$(printf '%s' "$BODY" | openssl dgst -sha256 -r | cut -d' ' -f1)
//   CANON=$(printf 'POST\n/internal/actions\n%s\n%s\n%s' "$TS" "$NONCE" "$DIGEST")
//   printf '%s' "$CANON" | openssl dgst -sha256 -hmac "$SECRET" -r
//
// That matters. A test that recomputes the signature the way the code does passes
// just as happily when both are wrong, and "both are wrong" is the only failure
// mode here that reaches production — the bot answers `unauthorized` for a bad
// signature and for an unknown key id identically and deliberately, so a signing
// bug arrives as an unexplained 401 with nothing in it to grep for.
//
// Constants are file-scoped but PHP constants are global, so everything here is
// prefixed: Pest runs every test file in one process.

const SIGNER_SECRET = 'two-web-test-secret-at-least-32-characters';
const SIGNER_KEY_ID = 'web-test';
const SIGNER_TIMESTAMP = 1787173135;
const SIGNER_NONCE = '9f1c0a2b3d4e5f60718293a4b5c6d7e8';
const SIGNER_BODY = '{"action":"event.upsert","event_key":"movie-night-2026-09-01"}';

const SIGNER_EXPECTED_SIGNATURE = 'sha256=3604fc650acae867427205ec8da5dc6dc7e379e264c9014a2d947368d95a1224';
const SIGNER_EXPECTED_BODY_DIGEST = 'aa7823cc4a81eb30b2b6ecb1ffa1d59c13c7d25ac7eec14422d9a03131446536';

it('signs a known request to a known hex string', function () {
    $headers = (new InternalActionSigner(SIGNER_KEY_ID, SIGNER_SECRET))
        ->headers(SIGNER_BODY, SIGNER_TIMESTAMP, SIGNER_NONCE);

    expect($headers['X-TWO-Signature'])->toBe(SIGNER_EXPECTED_SIGNATURE);
});

it('hashes the raw body to the digest the bot will recompute', function () {
    // Pinned separately from the signature so a failure says which half moved:
    // the body hash, or the canonical string wrapped around it.
    expect(hash('sha256', SIGNER_BODY))->toBe(SIGNER_EXPECTED_BODY_DIGEST);
});

it('sends the key id, the unix-second timestamp and the nonce it signed', function () {
    $headers = (new InternalActionSigner(SIGNER_KEY_ID, SIGNER_SECRET))
        ->headers(SIGNER_BODY, SIGNER_TIMESTAMP, SIGNER_NONCE);

    expect($headers['X-TWO-Key-Id'])->toBe(SIGNER_KEY_ID)
        // Seconds, not milliseconds. Milliseconds are about a thousand times the
        // ±120s skew window, so every request would come back `stale_request` and
        // the doc's advice — "check your NTP" — would send us the wrong way.
        ->and($headers['X-TWO-Timestamp'])->toBe('1787173135')
        ->and($headers['X-TWO-Nonce'])->toBe(SIGNER_NONCE);
});

it('emits the signature as lowercase hex behind an sha256= prefix', function () {
    $headers = (new InternalActionSigner(SIGNER_KEY_ID, SIGNER_SECRET))
        ->headers(SIGNER_BODY, SIGNER_TIMESTAMP, SIGNER_NONCE);

    expect($headers['X-TWO-Signature'])->toMatch('/^sha256=[0-9a-f]{64}$/');
});

it('signs the body, so a swapped body under a captured signature cannot verify', function () {
    $signer = new InternalActionSigner(SIGNER_KEY_ID, SIGNER_SECRET);

    $one = $signer->headers(SIGNER_BODY, SIGNER_TIMESTAMP, SIGNER_NONCE);
    $other = $signer->headers('{"action":"event.upsert","event_key":"something-else"}', SIGNER_TIMESTAMP, SIGNER_NONCE);

    expect($one['X-TWO-Signature'])->not->toBe($other['X-TWO-Signature']);
});

it('produces a different signature for a different secret, timestamp or nonce', function () {
    $base = (new InternalActionSigner(SIGNER_KEY_ID, SIGNER_SECRET))
        ->headers(SIGNER_BODY, SIGNER_TIMESTAMP, SIGNER_NONCE);

    $otherSecret = (new InternalActionSigner(SIGNER_KEY_ID, 'a-completely-different-shared-secret-value'))
        ->headers(SIGNER_BODY, SIGNER_TIMESTAMP, SIGNER_NONCE);
    $otherTimestamp = (new InternalActionSigner(SIGNER_KEY_ID, SIGNER_SECRET))
        ->headers(SIGNER_BODY, SIGNER_TIMESTAMP + 1, SIGNER_NONCE);
    $otherNonce = (new InternalActionSigner(SIGNER_KEY_ID, SIGNER_SECRET))
        ->headers(SIGNER_BODY, SIGNER_TIMESTAMP, '00000000000000000000000000000000');

    expect($otherSecret['X-TWO-Signature'])->not->toBe($base['X-TWO-Signature'])
        ->and($otherTimestamp['X-TWO-Signature'])->not->toBe($base['X-TWO-Signature'])
        ->and($otherNonce['X-TWO-Signature'])->not->toBe($base['X-TWO-Signature']);
});

it('returns the four signing headers and nothing else', function () {
    // The key id is not optional and not derivable. The bot rotates one caller's
    // secret by adding a second key id, so a request without one is unauthorized
    // in exactly the same way a forged one is.
    $headers = (new InternalActionSigner(SIGNER_KEY_ID, SIGNER_SECRET))
        ->headers(SIGNER_BODY, SIGNER_TIMESTAMP, SIGNER_NONCE);

    expect(array_keys($headers))
        ->toEqualCanonicalizing(['X-TWO-Key-Id', 'X-TWO-Timestamp', 'X-TWO-Nonce', 'X-TWO-Signature']);
});
