<?php

namespace App\Services\Bot;

/**
 * The HMAC signature on every request to the bot's internal action endpoint.
 *
 * Wire format: two-bot `docs/INTERNAL_ACTIONS.md` §1, status v0.3.
 *
 *   canonical = "POST\n/internal/actions\n{timestamp}\n{nonce}\n{sha256_hex(raw_body)}"
 *   signature = "sha256=" + hex(hmac_sha256(shared_secret, canonical))
 *
 * Signing a *hash* of the body rather than the body keeps the canonical string
 * short and removes every argument about encoding, whitespace and key order. The
 * price is that the caller must hand over the bytes it is actually going to put
 * on the wire — see InternalActionClient, which builds the JSON once and sends
 * that same string.
 *
 * This class exists separately from the client so the signature can be pinned to
 * a hex value computed outside this codebase. The bot answers a bad signature and
 * an unknown key id with the identical response, on purpose, so a signing bug
 * arrives as an unexplained `unauthorized` with nothing in it to diagnose.
 *
 * Nothing here logs. The secret, the signature and the canonical string are never
 * written anywhere — the canonical string is not itself secret, but it contains
 * everything except the key needed to reason about a signature, and there is no
 * reason to have it in a log file.
 */
final readonly class InternalActionSigner
{
    /** The signed path. Not derived from the URL: it is part of the contract. */
    public const PATH = '/internal/actions';

    public function __construct(
        private string $keyId,
        private string $secret,
    ) {}

    /**
     * The four signing headers for one attempt.
     *
     * @param  string  $body  The exact bytes that will be transmitted.
     * @param  int  $timestamp  Unix seconds. Milliseconds are ~1000x the ±120s
     *                          skew window and every request would be rejected.
     * @param  string  $nonce  32 hex characters, fresh for every attempt.
     * @return array<string, string>
     */
    public function headers(string $body, int $timestamp, string $nonce): array
    {
        $canonical = implode("\n", [
            'POST',
            self::PATH,
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);

        return [
            'X-TWO-Key-Id' => $this->keyId,
            'X-TWO-Timestamp' => (string) $timestamp,
            'X-TWO-Nonce' => $nonce,
            'X-TWO-Signature' => 'sha256='.hash_hmac('sha256', $canonical, $this->secret),
        ];
    }
}
