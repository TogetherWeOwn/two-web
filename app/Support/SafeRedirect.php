<?php

namespace App\Support;

/**
 * The `?next=` return-to-page guard (TOG-9254).
 *
 * After Discord sends a member back, join and login may return them to the
 * page they came from instead of always landing on `/profile`. That page
 * arrives as a `?next=` query value on the guest CTA, crosses the OAuth round
 * trip in the session (same as `join_source`), and is consumed on the
 * callback. This class owns the one rule that keeps that from becoming an
 * open redirect: only a relative path may pass, never a URL.
 *
 * A pure function over the raw value — the controllers own the session, the
 * views own the links; this owns the yes/no, so a change to the rule has one
 * place to land.
 */
final class SafeRedirect
{
    /**
     * The value when it is a safe relative path, null for everything else.
     *
     * Accepts `/e/abc123`, `/events`, `/` (query strings ride along). Rejects
     * absolute URLs (`https://evil.test`), protocol-relative hosts
     * (`//evil.test/x`), backslashes (some browsers treat `/\` as `//`),
     * anything with whitespace or control characters, and anything parse_url
     * reads a scheme, host, user or port out of. Non-strings (e.g. `?next[]=x`
     * arriving as an array) are rejected, never cast.
     */
    public static function safe(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        // A single leading slash. No leading slash is a bare word or a
        // scheme (`javascript:alert(1)`); two slashes is someone else's host.
        if (! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }

        if (str_contains($value, '\\') || preg_match('/[\s\x00-\x1f\x7f]/', $value) === 1) {
            return null;
        }

        $parts = parse_url($value);

        if (! is_array($parts)) {
            return null;
        }

        if (($parts['scheme'] ?? null) !== null
            || ($parts['host'] ?? null) !== null
            || ($parts['user'] ?? null) !== null
            || ($parts['port'] ?? null) !== null) {
            return null;
        }

        return $value;
    }
}
