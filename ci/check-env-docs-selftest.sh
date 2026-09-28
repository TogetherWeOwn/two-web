#!/usr/bin/env bash
#
# Tests for ci/check-env-docs.sh.
#
# A checker that always passes and a checker that works look identical from
# the outside. This one guards two files that drift silently — a new var lands
# in one file, nobody updates the other, and nothing breaks until a fresh
# checkout or deploy does — which is the combination where a broken check goes
# unnoticed longest. So its ability to fail is asserted here, on every pull
# request, in both directions, plus the two ways its parser can hand back
# an answer about files it never read.
#
# No network, no credential. Fixtures are throwaway copies of the real files
# with one deliberate mutation each.
#
# Usage: ./ci/check-env-docs-selftest.sh

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPT="$REPO_ROOT/ci/check-env-docs.sh"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/env-docs-selftest.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

pass() { printf '\033[32mPASS\033[0m  %s\n' "$*"; }
fail() { printf '\033[31mFAIL\033[0m  %s\n' "$*" >&2; }

rc=0
n=0

[ -x "$SCRIPT" ] || { printf '%s is not executable\n' "$SCRIPT" >&2; exit 1; }

# expect <slug> <expected exit> <expected substring> <example> <docs>
expect() {
  local slug="$1" want_rc="$2" want_text="$3" example="$4" docs="$5"
  n=$((n + 1))

  local out got_rc
  if [ -n "$example" ] && [ -n "$docs" ]; then
    out="$("$SCRIPT" "$example" "$docs" 2>&1)"; got_rc=$?
  else
    out="$("$SCRIPT" 2>&1)"; got_rc=$?
  fi

  if [ "$got_rc" -ne "$want_rc" ]; then
    fail "$slug: expected exit $want_rc, got $got_rc"
    printf '%s\n' "$out" | sed 's/^/        /' >&2
    rc=1
    return
  fi
  if ! printf '%s' "$out" | grep -qF "$want_text"; then
    fail "$slug: exit $got_rc was right, but the output never said '$want_text'"
    printf '%s\n' "$out" | sed 's/^/        /' >&2
    rc=1
    return
  fi
  pass "$slug (exit $got_rc)"
}

cp "$REPO_ROOT/.env.example" "$WORK/base.example"
cp "$REPO_ROOT/docs/env.md" "$WORK/base.docs"

# --- the clean tree --------------------------------------------------------

expect clean-tree 0 "agree" "" ""

# --- direction 1: a key in the example with no documented entry -------------

printf 'SNEAKY_NEW_KEY=1\n' >> "$WORK/base.example"
expect undocumented-key 1 "undocumented" "$WORK/base.example" "$WORK/base.docs"

# --- direction 1 via the other door: a docs entry is deleted ---------------
# Removing every documented mention of a real example key must read as that
# key being undocumented, not as a clean tree.

sed 's/`TWO_WEB_STAGING_QA_AUTH_TOKEN`/REMOVED_TOKEN/g' "$WORK/base.docs" > "$WORK/doc-entry-removed.docs"
cp "$REPO_ROOT/.env.example" "$WORK/doc-entry-removed.example"
expect doc-entry-removed 1 "undocumented" "$WORK/doc-entry-removed.example" "$WORK/doc-entry-removed.docs"

# --- direction 2: a documented key missing from the example ----------------
# SESSION_DOMAIN is documented and deliberately not allowlisted, so dropping
# it from the example must fail as missing rather than pass.

grep -v '^SESSION_DOMAIN=' "$REPO_ROOT/.env.example" > "$WORK/example-key-removed.example"
expect example-key-removed 1 "missing" "$WORK/example-key-removed.example" "$WORK/base.docs"

# --- stale allowlist: a "deliberately absent" key arrives in the example ---
# DISCORD_REDIRECT_URI documents that it must never exist. If it ever does,
# the allowlist entry claiming its absence is deliberate is stale — and the
# check must say that, not pass on the grounds that the key is documented.

printf 'DISCORD_REDIRECT_URI=https://example.invalid/callback\n' >> "$WORK/example-key-removed.example"
cp "$WORK/example-key-removed.example" "$WORK/stale-allowlist.example"
expect stale-allowlist 1 "stale allowlist" "$WORK/stale-allowlist.example" "$WORK/base.docs"

# --- unreadable inputs are exit 2, never a verdict --------------------------

n=$((n + 1))
if out="$("$SCRIPT" "$WORK/does-not-exist.example" "$WORK/base.docs" 2>&1)"; then
  fail "missing-example: expected a non-zero exit, got 0"
  rc=1
elif [ "$?" -ne 2 ]; then
  fail "missing-example: expected exit 2 (could not read), got $?"
  printf '%s\n' "$out" | sed 's/^/        /' >&2
  rc=1
else
  pass "missing-example (exit 2)"
fi

n=$((n + 1))
if out="$("$SCRIPT" "$WORK/base.example" "$WORK/does-not-exist.docs" 2>&1)"; then
  fail "missing-docs: expected a non-zero exit, got 0"
  rc=1
elif [ "$?" -ne 2 ]; then
  fail "missing-docs: expected exit 2 (could not read), got $?"
  printf '%s\n' "$out" | sed 's/^/        /' >&2
  rc=1
else
  pass "missing-docs (exit 2)"
fi

# --- a file the parser reads nothing from is exit 2, not agreement ---------
# Without this, a parser change that matches nothing would report PASS on
# every tree while comparing nothing — the same quiet-death shape the
# codeowners check guards with its response-shape assertion.

printf 'nothing backticked here\n' > "$WORK/empty.docs"
expect empty-docs 2 "no keys parsed" "$WORK/base.example" "$WORK/empty.docs"

printf '\n'
if [ "$rc" -ne 0 ]; then
  fail "the env-docs check does not say what it claims"
else
  printf '\033[1m%d/%d — the env-docs check accepts and rejects the right trees.\033[0m\n' "$n" "$n"
fi
exit "$rc"
