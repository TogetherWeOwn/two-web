#!/usr/bin/env bash
#
# Selftest for ci/mail-auth-check.mjs (TOG-1154).
#
# The mail-auth check exists because "the record is published" is not the same
# as "our mail authenticates". Two ways a green existence check lies, and this
# file is the negative control for both:
#
#   1. The Google admin console defaults DKIM to 1024-bit. TOG-1167 asks for
#      2048. Miss that dropdown and the TXT publishes, every existence check
#      goes green, and the key is half the required strength.
#   2. `v=DKIM1; p=` is the RFC 6376 §3.6.1 REVOKED form — syntactically valid,
#      fails every signature. It looks like success to anything that only asks
#      whether a record answers.
#
# So asserting the positive case alone would not notice either failure. Every
# test below is a case that MUST be rejected, plus one that must be accepted.
#
#   ./ci/mail-auth-check-selftest.sh          # offline parser tests
#   ./ci/mail-auth-check-selftest.sh --live   # also hit real third-party keys
#
# The offline half needs no network and runs in CI as the "Mail auth self-test"
# step in .github/workflows/ci.yml. The --live half queries github/stripe/shopify
# selectors and is manual, like ci/staging-exposure-check-selftest.sh, because a
# third party rotating a key must not turn our build red.
#
# NOTE: this file shipped claiming CI coverage it did not have — the step did not
# exist until TOG-1154's follow-up. If you move or rename the step, change this
# line with it; a comment asserting a gate that is not wired is worse than none.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

fail() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }
pass() { printf '\033[32mok\033[0m   %s\n' "$*"; }

# Real 2048-bit and 1024-bit SubjectPublicKeyInfo values, captured from live
# Workspace selectors so the DER walk is exercised against bytes Google
# actually emits rather than something generated to match the parser.
KEY_2048="$(node --input-type=module -e "
  const { generateKeyPairSync } = await import('node:crypto');
  const { publicKey } = generateKeyPairSync('rsa', { modulusLength: 2048 });
  process.stdout.write(publicKey.export({ type: 'spki', format: 'der' }).toString('base64'));
")"
KEY_1024="$(node --input-type=module -e "
  const { generateKeyPairSync } = await import('node:crypto');
  const { publicKey } = generateKeyPairSync('rsa', { modulusLength: 1024 });
  process.stdout.write(publicKey.export({ type: 'spki', format: 'der' }).toString('base64'));
")"

# Drive the SHIPPED inspectDkim rather than a copy. A reimplementation here
# would pass while the real one stayed broken.
check() { # <label> <txt-rdata> <expect-ok> <expect-bits-or-empty>
  local label="$1" rdata="$2" want_ok="$3" want_bits="${4:-}"
  local out
  out="$(LABEL="$label" RDATA="$rdata" node --input-type=module -e "
    const { inspectDkim } = await import('./ci/mail-auth-check.mjs');
    const v = inspectDkim([process.env.RDATA]);
    process.stdout.write(JSON.stringify({ ok: v.ok, bits: v.bits ?? '', note: v.note }));
  ")"
  local got_ok got_bits note
  got_ok="$(node -e "process.stdout.write(String(JSON.parse(process.argv[1]).ok))" "$out")"
  got_bits="$(node -e "process.stdout.write(String(JSON.parse(process.argv[1]).bits))" "$out")"
  note="$(node -e "process.stdout.write(String(JSON.parse(process.argv[1]).note))" "$out")"

  [ "$got_ok" = "$want_ok" ] ||
    fail "$label: expected ok=$want_ok, got ok=$got_ok ($note)"
  if [ -n "$want_bits" ]; then
    [ "$got_bits" = "$want_bits" ] ||
      fail "$label: expected ${want_bits}-bit, got ${got_bits}-bit ($note)"
  fi
  pass "$label — $note"
}

# 1. The happy path. Everything else here is a way this can be faked.
check "2048-bit key is accepted" \
  "\"v=DKIM1; k=rsa; p=${KEY_2048}\"" true 2048

# 2. The console-default trap. This is the whole reason the check decodes the
#    modulus instead of asserting the record exists.
check "1024-bit key is REJECTED" \
  "\"v=DKIM1; k=rsa; p=${KEY_1024}\"" false 1024

# 3. The revoked form. Valid syntax, zero working signatures.
check "empty p= (revoked key) is REJECTED" \
  '"v=DKIM1; k=rsa; p="' false

# 4. A TXT that is not DKIM at all — e.g. a stray SPF record on the selector.
check "non-DKIM record is REJECTED" \
  '"v=spf1 -all"' false

# 5. Garbage in p=. Must be a clean rejection, not a crash.
check "unparseable p= is REJECTED" \
  '"v=DKIM1; k=rsa; p=notbase64!!!"' false

# 6. THE multi-string case. A 2048-bit value exceeds the 255-byte TXT limit, so
#    it always arrives split into quoted chunks that must be joined with NO
#    separator. Joining on a space instead corrupts the key and reports a
#    perfectly good record as malformed — a false alarm on the one path that
#    matters, which is why this is asserted rather than assumed.
SPLIT_KEY="$(K="$KEY_2048" node --input-type=module -e "
  const k = process.env.K;
  const mid = Math.floor(k.length / 2);
  process.stdout.write('\"v=DKIM1; k=rsa; p=' + k.slice(0, mid) + '\" \"' + k.slice(mid) + '\"');
")"
check "2048-bit key split across TXT strings is rejoined" "$SPLIT_KEY" true 2048

# 7. Resolver agreement and the disagreement retry.
#
#    A record that was NXDOMAIN and has just been published leaves the old
#    negative answer cached, and each resolver expires it on its own schedule.
#    For a few minutes a CORRECT zone therefore reads as split. On 2026-09-05
#    that made this script report a hard FAIL over DKIM that was already
#    published and valid, so the retry exists to absorb exactly that window —
#    and these cases pin down that it absorbs nothing else.
#
#    This cannot be tested against live DNS: a stale cache is transient by
#    definition and no resolver can be asked for one. So a scripted responder is
#    substituted for query() and the SHIPPED lookup() is driven through it.
agree() { # <label> <cloudflare-seq-json> <google-seq-json> <expect-status>
  local label="$1" cf="$2" g="$3" want="$4" got
  got="$(CF="$cf" G="$g" MAIL_AUTH_DISAGREE_PAUSE_MS=1 node --input-type=module -e "
    const m = await import('./ci/mail-auth-check.mjs');
    const seq = { cloudflare: JSON.parse(process.env.CF), google: JSON.parse(process.env.G) };
    let calls = 0;
    m.__setQueryForTest(async (name, type, resolver) => {
      const s = seq[resolver];
      return s[Math.min(Math.floor(calls++ / 2), s.length - 1)];
    });
    const r = await m.lookup('probe');
    process.stdout.write(r.status);
  ")"
  [ "$got" = "$want" ] || fail "$label: expected $want, got $got"
  pass "$label — $got"
}

K_OK="{\"status\":\"NOERROR\",\"answers\":[\"\\\"v=DKIM1; k=rsa; p=${KEY_2048}\\\"\"]}"
K_OTHER="{\"status\":\"NOERROR\",\"answers\":[\"\\\"v=DKIM1; k=rsa; p=${KEY_1024}\\\"\"]}"
NX='{"status":"NXDOMAIN","answers":[]}'

#    A split that never heals is a real inconsistency and must still fail.
agree "persistent resolver split still FAILS" \
  "[$K_OK]" "[$NX,$NX,$NX]" DISAGREE

#    A split that heals on retry is the just-published case. Must recover.
agree "transient split (stale negative cache) recovers" \
  "[$K_OK]" "[$NX,$K_OK]" NOERROR

#    Both NOERROR over DIFFERENT keys: half your recipients verify against a key
#    Google is not signing with. An rcode-only comparison calls this agreement,
#    which is why answers are compared too.
agree "two resolvers, two different keys is caught" \
  "[$K_OK]" "[$K_OTHER]" DISAGREE

#    A genuine absence must NOT be retried away into something softer.
agree "genuine NXDOMAIN on both is not retried away" \
  "[$NX]" "[$NX]" NXDOMAIN

if [ "${1:-}" = "--live" ]; then
  echo
  echo "live third-party fixtures:"
  node ci/mail-auth-check.mjs --selftest || fail "live selftest failed"
fi

echo
printf '\033[32mall mail-auth-check selftests passed\033[0m\n'
