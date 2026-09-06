#!/usr/bin/env bash
#
# Deterministic selftest for the Access-protected staging gate (TOG-1284).
#
# The unit fixtures cover success and each security-relevant failure without
# depending on live DNS, Cloudflare or the staging app. A small real resolver
# control remains because the production implementation shells out to getent.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

fail() { printf '\033[31mFAIL\033[0m %s\n' "$*" >&2; exit 1; }
pass() { printf '\033[32mok\033[0m   %s\n' "$*"; }

node ci/staging-access-selftest.mjs

v6_only_host='ipv6.google.com'
if getent ahostsv4 "$v6_only_host" >/dev/null 2>&1; then
  fail "premise broken: $v6_only_host now has an A record — choose another AAAA-only control"
fi

seen="$(node --input-type=module - <<'JS'
import { resolveAddressFamilies } from './ci/staging-access.mjs';
const result = resolveAddressFamilies('ipv6.google.com');
console.log(result.ipv6.length);
JS
)"
[ "$seen" -gt 0 ] || fail "resolver did not see the AAAA-only control host"
pass "real resolver sees the AAAA-only control ($seen address(es))"

for file in ci/staging-exposure-check.mjs ci/cutover-check.mjs; do
  grep -q "./staging-access.mjs" "$file" \
    || fail "$file no longer imports the shared Access-protected staging gate"
  pass "$file uses the shared Access-protected staging gate"
done

printf '\n14/14 ok\n'
