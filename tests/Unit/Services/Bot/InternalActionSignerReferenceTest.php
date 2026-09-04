<?php

use App\Services\Bot\Announcement;
use App\Services\Bot\InternalActionSigner;
use App\Services\Bot\RoleAssignment;

/**
 * The cross-implementation check on the signature (TOG-470, kept through TOG-929).
 *
 * InternalActionSignerTest pins this class to vectors computed with `openssl`.
 * This file pins it to vectors computed by *the reference implementation the
 * endpoint was actually accepted against*: two-bot
 * `scripts/internal-actions-acceptance.ts` at `origin/tog-463/acceptance-harness`
 * (`b42037c`), the `sign()` at line 56. The two are independent of each other and
 * of this codebase, which is the point — a test that recomputes the signature the
 * way the code does passes just as happily when both are wrong, and "both are
 * wrong" is the only failure mode here that reaches production. The bot answers a
 * bad signature and an unknown key id identically, on purpose, so a signing bug
 * arrives as an unexplained `unauthorized` with nothing in it to grep for.
 *
 * If this file goes red, the PHP has drifted from the TypeScript. The prose in
 * `docs/INTERNAL_ACTIONS.md` §1 is not the tiebreaker; these numbers are.
 *
 * The bodies are the two actions TOG-470 added, and they are the two the other
 * signer test does not cover — it pins `event.upsert`.
 *
 * Constants are global in PHP and Pest runs every test file in one process, so
 * everything here is prefixed.
 */
const REF_SECRET = 'test-secret-do-not-use';
const REF_KEY_ID = 'web-test';
const REF_TIMESTAMP = 1787173135;
const REF_NONCE = '9f1c0d3e5a7b9c1d3e5f7a9b0c2d4e6f';

const REF_ROLE_BODY = '{"action":"role.assign","discord_id":"900000000000009999","role_key":"rocketleague"}';
const REF_ROLE_DIGEST = '84ba3c5c16706c5f9e4b8e6a4bc79eb9a36c55b823193e1e94a743a44a071afc';
const REF_ROLE_SIGNATURE = 'sha256=a2159435259369a4460b5d94202f44219f3e22fb17ed24c8a4394948bc6251a0';

// Deliberately carries a slash and non-ASCII: those are exactly the characters
// PHP's json_encode escapes by default and JavaScript's JSON.stringify does not.
const REF_ANNOUNCEMENT_BODY = '{"action":"announcement.post","channel_key":"qa-throwaway","body":"héllo / world «ok»"}';
const REF_ANNOUNCEMENT_DIGEST = '17ce0c9cdc32c0d08457e1482da370e70f6bfb71d4cf23db1d3aa4d7d7a1c1c8';
const REF_ANNOUNCEMENT_SIGNATURE = 'sha256=d75833b1faf35524dbce628cf26a14bc71084f9eea37d645c2160cc9039afd51';

function refSignature(string $body): string
{
    return (new InternalActionSigner(REF_KEY_ID, REF_SECRET))
        ->headers($body, REF_TIMESTAMP, REF_NONCE)['X-TWO-Signature'];
}

it('signs a role.assign exactly as the reference implementation does', function () {
    expect(refSignature(REF_ROLE_BODY))->toBe(REF_ROLE_SIGNATURE);
});

it('signs an announcement.post exactly as the reference implementation does', function () {
    expect(refSignature(REF_ANNOUNCEMENT_BODY))->toBe(REF_ANNOUNCEMENT_SIGNATURE);
});

it('hashes each reference body to the digest the bot will recompute', function () {
    // Pinned separately from the signature so a failure says which half moved:
    // the body hash, or the canonical string wrapped around it.
    expect(hash('sha256', REF_ROLE_BODY))->toBe(REF_ROLE_DIGEST)
        ->and(hash('sha256', REF_ANNOUNCEMENT_BODY))->toBe(REF_ANNOUNCEMENT_DIGEST);
});

it('would notice hash_hmac being called with the key and data the wrong way round', function () {
    $canonical = implode("\n", ['POST', '/internal/actions', (string) REF_TIMESTAMP, REF_NONCE, REF_ROLE_DIGEST]);

    // Both are 71-character `sha256=`-prefixed hex strings and neither looks
    // wrong. Only the vector separates them.
    $swapped = 'sha256='.hash_hmac('sha256', REF_SECRET, $canonical);

    expect($swapped)->not->toBe(REF_ROLE_SIGNATURE)
        ->and(refSignature(REF_ROLE_BODY))->toBe(REF_ROLE_SIGNATURE);
});

it('encodes the action payloads to the exact bytes the vectors were signed over', function () {
    // The vectors above hash unescaped bytes. If the client's json_encode flags
    // lost JSON_UNESCAPED_SLASHES or JSON_UNESCAPED_UNICODE, the announcement
    // body would still *verify* — we sign what we send — but it would no longer
    // be byte-identical to what the reference harness exercised the bot with,
    // and these vectors would stop meaning anything. So the encoding is pinned
    // here too, through the value objects that actually build the payloads.
    $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    expect(json_encode((new RoleAssignment('900000000000009999', 'rocketleague'))->toPayload(), $flags))
        ->toBe(REF_ROLE_BODY)
        ->and(json_encode((new Announcement('qa-throwaway', 'héllo / world «ok»'))->toPayload(), $flags))
        ->toBe(REF_ANNOUNCEMENT_BODY);
});
