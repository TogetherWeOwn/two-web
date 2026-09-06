#!/usr/bin/env bash
#
# Selftest for ci/cutover-check.mjs (TOG-85).
#
# The cutover check hardcodes the guild id and invite code it expects /discord to
# lead to. So does config/services.php. Two copies of the same constant drift,
# and the failure mode is the worst kind: the check goes green against an invite
# the application no longer hands out. This pins them together.
#
# It also pins both phase classifications to the live apex. Before cutover the
# WordPress classification must pass and the after phase must fail; after cutover
# those outcomes invert. Requiring one pass and one fail keeps the check useful on
# both sides of the one-way event instead of baking in a date that immediately
# becomes false.
#
#   ./ci/cutover-check-selftest.sh
#
# Network is required: both the constant pinning and the phase control talk to the
# live apex and to Discord. There is no offline mode, deliberately — this file
# exists to check the real world, and a mocked version of it would pass forever.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

fail() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }
pass() { printf '\033[32mok\033[0m   %s\n' "$*"; }

# ---------------------------------------------------------------------------
# 1. The invite code in the check matches the one the application ships

services_invite="$(grep -oE "https://discord\.gg/[A-Za-z0-9]+" config/services.php | head -1)"
[ -n "$services_invite" ] || fail "no discord.gg invite found in config/services.php"

check_code="$(grep -oE "^const INVITE_CODE = '[A-Za-z0-9]+'" ci/cutover-check.mjs | grep -oE "'[A-Za-z0-9]+'" | tr -d "'")"
[ -n "$check_code" ] || fail "no INVITE_CODE found in ci/cutover-check.mjs"

services_code="${services_invite##*/}"
[ "$services_code" = "$check_code" ] \
  || fail "invite drift: config/services.php has '$services_code', cutover-check.mjs has '$check_code'"
pass "invite code matches config/services.php ($check_code)"

# ---------------------------------------------------------------------------
# 2. That invite really resolves to the guild the check expects

check_guild="$(grep -oE "^const GUILD_ID = '[0-9]+'" ci/cutover-check.mjs | grep -oE "[0-9]+")"
[ -n "$check_guild" ] || fail "no GUILD_ID found in ci/cutover-check.mjs"

live_guild="$(curl -sS --max-time 25 \
  "https://discord.com/api/v10/invites/${check_code}?with_counts=true" \
  | python3 -c 'import json,sys; print((json.load(sys.stdin).get("guild") or {}).get("id",""))')"

[ "$live_guild" = "$check_guild" ] \
  || fail "invite $check_code resolves to guild '${live_guild:-<none>}', check expects $check_guild"
pass "invite resolves to guild $check_guild"

# ---------------------------------------------------------------------------
# 3. Exactly one phase must classify the live apex stack successfully

before_out="$(node ci/cutover-check.mjs --phase before 2>&1 || true)"
after_out="$(node ci/cutover-check.mjs --phase after 2>&1 || true)"

before_stack=0
after_stack=0
grep -qE 'PASS +apex-stack' <<<"$before_out" && before_stack=1
grep -qE 'PASS +apex-stack' <<<"$after_out" && after_stack=1

if [ "$before_stack" -eq 1 ] && [ "$after_stack" -eq 0 ]; then
  phase=before
elif [ "$before_stack" -eq 0 ] && [ "$after_stack" -eq 1 ]; then
  phase=after
else
  fail "expected exactly one apex-stack classification to pass"
fi
pass "live apex stack is classified as the '$phase' phase"

# ---------------------------------------------------------------------------
# 4. The opposite phase rejects that stack — the classification is not vacuous

opposite=after
opposite_out="$after_out"
if [ "$phase" = after ]; then
  opposite=before
  opposite_out="$before_out"
fi
grep -qE 'FAIL +apex-stack' <<<"$opposite_out" \
  || fail "the opposite '$opposite' phase did not reject the live apex stack"
pass "negative control: '$opposite' phase rejects the live apex stack"

# ---------------------------------------------------------------------------
# 5. The SEO checks are actually wired in, and are reading the probe

# The board decided on 2026-08-27 to carry the live SEO checks into the cutover
# checklist. That decision sat unimplemented for a week while the card read as
# covered, so pin the wiring rather than trusting it. Before the flip it is enough
# that the after phase emits the roll-up; after the flip its verdict must agree with
# the standalone probe rather than with a stale expectation about WordPress.
grep -q 'seo-no-regression' <<<"$after_out" \
  || fail "'--phase after' emitted no seo-no-regression line — the SEO checks are not wired in"
pass "SEO checks run in '--phase after'"

if node ci/live-seo-probe.mjs --json >/dev/null 2>&1; then
  grep -qE 'PASS +seo-no-regression' <<<"$after_out" \
    || fail "standalone SEO probe passes but cutover roll-up does not"
  pass "cutover SEO roll-up agrees with the passing standalone probe"
else
  grep -qE 'FAIL +seo-no-regression' <<<"$after_out" \
    || fail "standalone SEO probe fails but cutover roll-up does not"
  pass "cutover SEO roll-up agrees with the failing standalone probe"
fi

# The probe's own selftest is what makes those measurements trustworthy. If it
# regresses, every seo-* line above is untrustworthy too.
node ci/live-seo-probe.mjs --selftest >/dev/null 2>&1 \
  || fail "ci/live-seo-probe.mjs --selftest failed — the SEO measurements cannot be trusted"
pass "live-seo-probe selftest passes"

# ---------------------------------------------------------------------------
# 6. The staging cutover assertion is the same Access gate as the standalone check

for name in no-wildcard apex-ipv4 apex-ipv6 staging-ipv4 staging-ipv6 staging-own-record staging-access-redirect staging-authenticated-up; do
  grep -qE "(PASS|FAIL) +${name}" <<<"$after_out" \
    || fail "'--phase after' emitted no ${name} result — staging Access coverage drifted out of the cutover gate"
done
pass "cutover after-phase runs every Access-protected staging assertion"

printf '\n8/8 ok\n'
