#!/usr/bin/env bash
#
# Selftest for ci/staging-exposure-check.mjs (TOG-1156).
#
# The exposure check answers one question: is staging walled off by a control we
# own, or merely broken? Its whole verdict hangs on `resolve()`. If that returns
# [] the script reports "NXDOMAIN — the name is gone, which is the strongest
# control available" and exits 0.
#
# So a resolver that under-reports is not a cosmetic bug, it is a green light on
# an exposed name. It shipped that way once: `getent ahostsv4` only, against a
# record that had an A *and* an AAAA (TOG-1160). Delete just the A and the check
# congratulates you while staging still answers over IPv6.
#
# Test 1 is the negative control for exactly that, and it is the reason this file
# exists — a name with an AAAA and no A must read as resolving. Nothing in a
# unit test can prove this, because the bug lives in the shell out to the system
# resolver; it has to be a real lookup against a real name.
#
#   ./ci/staging-exposure-check-selftest.sh
#
# Network is required, deliberately. See ci/cutover-check-selftest.sh's header.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

fail() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }
pass() { printf '\033[32mok\033[0m   %s\n' "$*"; }

# A name published with an AAAA and no A. If this ever grows an A record the
# test goes vacuous rather than red, so test 1 asserts the premise first.
V6_ONLY_HOST='ipv6.google.com'
APEX='togetherweown.com'

# ---------------------------------------------------------------------------
# 1. Negative control: an AAAA-only name must not read as NXDOMAIN

if getent ahostsv4 "$V6_ONLY_HOST" >/dev/null 2>&1; then
  fail "premise broken: $V6_ONLY_HOST now has an A record, so it no longer tests the v4-only blind spot — pick another AAAA-only name"
fi

if ! getent ahostsv6 "$V6_ONLY_HOST" >/dev/null 2>&1; then
  fail "$V6_ONLY_HOST does not resolve over IPv6 either — no IPv6 resolution here, so this selftest cannot run (control: getent ahostsv6 $APEX)"
fi
pass "control host $V6_ONLY_HOST is AAAA-only, as this test needs"

# Drive the real resolve() rather than a copy of it. A reimplementation here
# would pass while the shipped one stayed broken.
v6_seen="$(node --input-type=module -e "
  import { readFileSync } from 'node:fs';
  import { execFileSync } from 'node:child_process';
  const src = readFileSync('ci/staging-exposure-check.mjs', 'utf8');
  const start = src.indexOf('function resolve(');
  if (start < 0) throw new Error('resolve() not found in ci/staging-exposure-check.mjs');
  const body = src.slice(start, src.indexOf('\nfunction ', start + 1));
  const resolve = new Function('execFileSync', body + '; return resolve;')(execFileSync);
  console.log(resolve('$V6_ONLY_HOST').length);
")"

[ "$v6_seen" -gt 0 ] \
  || fail "resolve() reports $V6_ONLY_HOST as NXDOMAIN — it is IPv4-only, so an AAAA-only staging record would pass this gate while staging is still reachable"
pass "resolve() sees the AAAA-only host ($v6_seen address(es)) — both families are queried"

# ---------------------------------------------------------------------------
# 2. Positive control: a name that truly does not exist still reads as empty
#
# Without this, "always return something" would pass test 1.

absent_seen="$(node --input-type=module -e "
  import { readFileSync } from 'node:fs';
  import { execFileSync } from 'node:child_process';
  const src = readFileSync('ci/staging-exposure-check.mjs', 'utf8');
  const start = src.indexOf('function resolve(');
  const body = src.slice(start, src.indexOf('\nfunction ', start + 1));
  const resolve = new Function('execFileSync', body + '; return resolve;')(execFileSync);
  console.log(resolve('nonexistent-probe-selftest-tog1156.$APEX').length);
")"

[ "$absent_seen" -eq 0 ] \
  || fail "resolve() returned $absent_seen address(es) for a name that does not exist — NXDOMAIN detection is broken, so the gate can never fail"
pass "resolve() reports a nonexistent name as empty — NXDOMAIN detection still works"

# ---------------------------------------------------------------------------
# 3. The same blind spot must not come back in ci/cutover-check.mjs
#
# It has its own copy of resolve() and had the same bug. Two copies of one
# constant drift; this is the cheap pin.

for f in ci/staging-exposure-check.mjs ci/cutover-check.mjs; do
  grep -q "ahostsv6" "$f" \
    || fail "$f no longer queries ahostsv6 — an AAAA-only record would read as NXDOMAIN there"
  pass "$f queries both address families"
done

printf '\n\033[32mall checks passed\033[0m\n'
