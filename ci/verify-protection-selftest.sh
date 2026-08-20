#!/usr/bin/env bash
#
# Tests for ci/verify-protection.sh.
#
# Same argument as ci/verify-lint-selftest.sh: a checker that always passes and a
# checker that works look identical from the outside, and this one will be run
# rarely — after a protection change, before a release — which is exactly when
# nobody notices it has gone quiet.
#
# Every case is a rule this repo could plausibly end up with. The `stale-context`
# and `unrequired-check` cases have both already happened once (TWO-36: `ci`
# required, no job by that name; leaves reporting but not required).
#
# No network, no gh, no credential: each case is a saved protection response with
# one field changed.
#
# Usage: ./ci/verify-protection-selftest.sh

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPT="$REPO_ROOT/ci/verify-protection.sh"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/protection-selftest.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

pass() { printf '\033[32mPASS\033[0m  %s\n' "$*"; }
fail() { printf '\033[31mFAIL\033[0m  %s\n' "$*" >&2; }

rc=0
n=0

command -v jq >/dev/null || { printf 'jq is required\n' >&2; exit 1; }

# The rule as TWO-36 applied it, in the shape GitHub returns. The contexts are
# read from ci/verify-pipeline.sh so this fixture cannot drift from the list the
# script under test compares against — a hand-copied list here would make every
# case pass by agreeing with itself.
required=$(sed -n 's/^REQUIRED_CHECKS=(\(.*\))$/\1/p' "$REPO_ROOT/ci/verify-pipeline.sh")
[ -n "$required" ] || { printf 'could not read REQUIRED_CHECKS from ci/verify-pipeline.sh\n' >&2; exit 1; }

good() {
  jq -n --arg checks "$required" '{
    required_status_checks: {
      strict: true,
      checks: ($checks | split(" ") | map({context: ., app_id: null}))
    },
    required_pull_request_reviews: {
      required_approving_review_count: 1,
      dismiss_stale_reviews: true,
      require_last_push_approval: true
    },
    enforce_admins: { enabled: true },
    allow_force_pushes: { enabled: false },
    allow_deletions: { enabled: false }
  }'
}

# expect_fail <slug> <expected substring> <jq mutation>
expect_fail() {
  local slug="$1" expected="$2" mutation="$3"
  local file out status
  n=$((n + 1))
  file="$WORK/$slug.json"
  good | jq "$mutation" > "$file" || { fail "$slug: the mutation itself failed to apply"; rc=1; return; }

  out="$("$SCRIPT" "$file" 2>&1)"
  status=$?

  if [ "$status" -eq 0 ]; then
    fail "$slug: passed. It should have caught this."
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
    return
  fi
  if ! grep -qF -- "$expected" <<< "$out"; then
    fail "$slug: failed, but not for the stated reason. Wanted: ${expected}"
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
    return
  fi
  pass "$slug"
}

printf '\n\033[1m==> The rule does not require the checks\033[0m\n'

expect_fail no-required-checks \
  "Every job in ci.yml is advisory" \
  'del(.required_status_checks)'

# The finding from the TWO-22 acceptance run, inverted. On `ci-verify/gate` the
# aggregate was SKIPPED and the PR was blocked only because `static` is required
# in its own right. Drop that and the same branch merges clean.
expect_fail unrequired-check \
  "are not required on \`main\`" \
  '.required_status_checks.checks |= map(select(.context != "static"))'

# `ci` is the workflow name. No job reports it, so requiring it blocks every pull
# request forever — pending, not red, which reads as a slow runner.
expect_fail stale-context \
  "blocks every pull request forever" \
  '.required_status_checks.checks += [{context: "ci", app_id: null}]'

# The deprecated shape. A rule written through the older API populates `contexts`
# and leaves `checks` empty; reading only `checks` reports a correct rule as an
# empty one, so this case must PASS.
n=$((n + 1))
legacy="$WORK/legacy.json"
good | jq '.required_status_checks |= {strict: .strict, contexts: (.checks | map(.context))}' > "$legacy"
if "$SCRIPT" "$legacy" >/dev/null 2>&1; then
  pass "legacy-contexts-shape: a rule using the deprecated \`contexts\` field is read, not reported as empty"
else
  fail "legacy-contexts-shape: a valid rule in the deprecated shape was reported as broken"
  "$SCRIPT" "$legacy" 2>&1 | sed 's/^/        /'
  rc=1
fi

printf '\n\033[1m==> The rule does not stop a self-merge\033[0m\n'

expect_fail no-approval \
  "no self-merge" \
  '.required_pull_request_reviews.required_approving_review_count = 0'

expect_fail no-review-block \
  "no self-merge" \
  'del(.required_pull_request_reviews)'

expect_fail stale-reviews-kept \
  "An approval survives a force-push" \
  '.required_pull_request_reviews.dismiss_stale_reviews = false'

printf '\n\033[1m==> The rule does not apply, or can be gone round\033[0m\n'

expect_fail admins-exempt \
  "Red stops nobody" \
  '.enforce_admins.enabled = false'

expect_fail force-push-allowed \
  "without passing a single check" \
  '.allow_force_pushes.enabled = true'

expect_fail deletions-allowed \
  "Deleting \`main\` is allowed" \
  '.allow_deletions.enabled = true'

printf '\n\033[1m==> Tripwires (warn, do not block)\033[0m\n'

n=$((n + 1))
lax="$WORK/lax.json"
good | jq '.required_status_checks.strict = false' > "$lax"
out="$("$SCRIPT" "$lax" 2>&1)"
if [ $? -eq 0 ] && grep -qF "can still land a red \`main\`" <<< "$out"; then
  pass "strict-off: warns, does not block"
else
  fail "strict-off: expected a warning and a zero exit"
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
fi

printf '\n\033[1m==> The rule as applied still passes\033[0m\n'

n=$((n + 1))
clean="$WORK/clean.json"
good > "$clean"
if "$SCRIPT" "$clean" >/dev/null 2>&1; then
  pass "clean: the rule TWO-36 applied verifies green"
else
  fail "clean: the intended rule was reported as broken"
  "$SCRIPT" "$clean" 2>&1 | sed 's/^/        /'
  rc=1
fi

if [ "$rc" -eq 0 ]; then
  printf '\n\033[1m%d/%d — the protection check catches every hole it claims to.\033[0m\n' "$n" "$n"
else
  printf '\n\033[31mThe protection check does not catch what it claims to. Fix it before trusting it.\033[0m\n' >&2
fi
exit "$rc"
