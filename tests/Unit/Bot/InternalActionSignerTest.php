<?php

use App\Services\Bot\InternalActionSigner;

/**
 * The signature is the one part of TOG-470 that cannot be checked by reading it.
 * A canonical string with the fields in the wrong order, or `hash_hmac`'s data
 * and key swapped, produces a perfectly well-formed lowercase hex string that
 * the bot rejects as `unauthorized` — the same answer it gives for an unknown
 * key id, so the logs will not tell you which mistake you made.
 *
 * So these are not self-consistency checks. Every expected value below was
 * produced by *the reference implementation itself* — two-bot
 * `scripts/internal-actions-acceptance.ts` at `origin/tog-463/acceptance-harness`
 * (`b42037c`), the `sign()` at line 56 — run over the inputs named here. If this
 * file goes red, the PHP has drifted from the TypeScript that the endpoint was
 * actually accepted against, and the prose in docs/INTERNAL_ACTIONS.md §1 is not
 * the tiebreaker.
 */
$secret = 'test-secret-do-not-use';
$timestamp = '1787173135';
$nonce = '9f1c0d3e5a7b9c1d3e5f7a9b0c2d4e6f';

$roleBody = '{"action":"role.assign","discord_id":"900000000000009999","role_key":"rocketleague"}';
$roleHash = '84ba3c5c16706c5f9e4b8e6a4bc79eb9a36c55b823193e1e94a743a44a071afc';
$roleSig = 'sha256=a2159435259369a4460b5d94202f44219f3e22fb17ed24c8a4394948bc6251a0';

// Deliberately carries a slash and non-ASCII: those are the two characters
// PHP's json_encode escapes by default and JavaScript's JSON.stringify does not.
$annBody = '{"action":"announcement.post","channel_key":"qa-throwaway","body":"héllo / world «ok»"}';
$annHash = '17ce0c9cdc32c0d08457e1482da370e70f6bfb71d4cf23db1d3aa4d7d7a1c1c8';
$annSig = 'sha256=d75833b1faf35524dbce628cf26a14bc71084f9eea37d645c2160cc9039afd51';

it('hashes the raw body the way the reference signer does', function () use ($roleBody, $roleHash, $annBody, $annHash) {
    expect(InternalActionSigner::bodyHash($roleBody))->toBe($roleHash);
    expect(InternalActionSigner::bodyHash($annBody))->toBe($annHash);
});

it('builds the canonical string as five newline-separated fields', function () use ($timestamp, $nonce, $roleBody, $roleHash) {
    $canonical = InternalActionSigner::canonical($timestamp, $nonce, $roleBody);

    expect($canonical)->toBe("POST\n/internal/actions\n{$timestamp}\n{$nonce}\n{$roleHash}");

    // Spelled out separately, because "it equals this long string" does not say
    // which part moved when it breaks.
    expect(explode("\n", $canonical))->toBe(['POST', '/internal/actions', $timestamp, $nonce, $roleHash]);
});

it('signs exactly what the reference implementation signs', function () use ($secret, $timestamp, $nonce, $roleBody, $roleSig, $annBody, $annSig) {
    expect(InternalActionSigner::sign($secret, $timestamp, $nonce, $roleBody))->toBe($roleSig);
    expect(InternalActionSigner::sign($secret, $timestamp, $nonce, $annBody))->toBe($annSig);
});

it('would notice hash_hmac being called with the key and data the wrong way round', function () use ($secret, $timestamp, $nonce, $roleBody, $roleSig) {
    $canonical = InternalActionSigner::canonical($timestamp, $nonce, $roleBody);
    $swapped = 'sha256='.hash_hmac('sha256', $secret, $canonical);

    // Both are 71-character `sha256=`-prefixed hex strings and neither looks
    // wrong. Only the vector separates them.
    expect($swapped)->not->toBe($roleSig);
    expect(InternalActionSigner::sign($secret, $timestamp, $nonce, $roleBody))->toBe($roleSig);
});

it('encodes bodies byte-for-byte the way the reference does', function () use ($annBody, $roleBody) {
    // Neither the slash nor the accented characters may come back escaped: the
    // vectors above hash the unescaped bytes.
    expect(InternalActionSigner::encode([
        'action' => 'announcement.post',
        'channel_key' => 'qa-throwaway',
        'body' => 'héllo / world «ok»',
    ]))->toBe($annBody);

    expect(InternalActionSigner::encode([
        'action' => 'role.assign',
        'discord_id' => '900000000000009999',
        'role_key' => 'rocketleague',
    ]))->toBe($roleBody);
});

it('mints a fresh 32-character hex nonce every time', function () {
    // §1: 128 bits, fresh on every attempt including retries. A nonce that
    // repeats is a `409 replayed` that never becomes anything else.
    $nonces = array_map(fn () => InternalActionSigner::nonce(), range(1, 200));

    expect(array_unique($nonces))->toHaveCount(200);

    foreach ($nonces as $value) {
        expect($value)->toMatch('/^[0-9a-f]{32}$/');
    }
});
