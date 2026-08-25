#!/usr/bin/env bash
#
# Selftest for ci/cutover-check.mjs (TOG-85).
#
# The cutover check hardcodes the guild id and invite code it expects /discord to
# lead to. So does config/services.php. Two copies of the same constant drift,
# and the failure mode is the worst kind: the check goes green against an invite
# the application no longer hands out. This pins them together.
#
# It also runs the negative control, which is the only thing that proves the
# checks are not vacuous. `--phase after` describes the world after the DNS flip.
# Run today, before the flip, it MUST fail — the apex is still WordPress. A
# cutover check that passes in both phases is checking nothing, and that is a
# mistake you only find on the night.
#
#   ./ci/cutover-check-selftest.sh
#
# Network is required: both the constant pinning and the control talk to the
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
# 3. Negative control — `--phase after` must fail while the apex is WordPress

if node ci/cutover-check.mjs --phase after >/dev/null 2>&1; then
  fail "'--phase after' passed while the apex is still WordPress — the checks are vacuous"
fi
pass "negative control: '--phase after' fails before the flip, as it must"

# ---------------------------------------------------------------------------
# 4. `--phase before` describes today, so it must pass

if ! node ci/cutover-check.mjs --phase before >/dev/null 2>&1; then
  fail "'--phase before' failed — either the funnel is broken or the check is wrong. Run it directly."
fi
pass "'--phase before' passes against the live site"

printf '\n4/4 ok\n'
