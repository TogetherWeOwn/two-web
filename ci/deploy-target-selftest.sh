#!/usr/bin/env bash
#
# Tests for the guard that refuses to deploy nothing.
#
# ci/deploy-target.sh exists because its predecessor passed when there was no
# deploy target, and that green was reported to the owner as "live on staging"
# (TOG-48, TOG-913). Replacing a guard that could not fail with a guard that
# *should* fail is only worth anything if somebody watches it fail — otherwise the
# next reader has the same evidence they had before, which is a file that looks
# right. So this executes the guard once per configuration and pins the exit code
# and the reason.
#
# Nothing here needs the network, a token, GitHub, or a deploy target. That is the
# point: the interesting case is precisely the one where no target exists, and it
# is the case that is true right now and will stay true until Coolify is stood up
# (TOG-780). This runs in the `static` job in well under a second.
#
# What is pinned:
#
#   nothing-set        no secret, no variable      -> exit 1  (the TOG-913 case)
#   hook-only          secret without STAGING_URL  -> exit 1  (partial is not ready)
#   url-only           variable without the secret -> exit 1
#   empty-hook         secret set to ""            -> exit 1
#   whitespace-hook    secret set to "  \n"        -> exit 1  (a copy-paste artifact)
#   ready              both set                    -> exit 0
#   ready-trailing     STAGING_URL with a "/"      -> exit 0, and the slash is gone
#   production-warn    a production hook exists    -> warns, does not fail
#   args               called with an argument     -> exit 2
#
# And the two assertions that are really about TOG-913 rather than about this
# script — that the workflow cannot quietly go back to skipping:
#
#   workflow-calls-guard    deploy.yml still runs ci/deploy-target.sh
#   workflow-has-no-skip    no step in deploy.yml is gated on a secret/target being
#                           set, which is the shape of the original defect
#
# Usage: ./ci/deploy-target-selftest.sh

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
GUARD="${REPO_ROOT}/ci/deploy-target.sh"
WORKFLOW="${REPO_ROOT}/.github/workflows/deploy.yml"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/deploy-target-selftest.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

rc=0
n=0

fail() { printf '\033[31mFAIL: %s\033[0m\n' "$*" >&2; }
pass() { printf '\033[32mPASS: %s\033[0m\n' "$*"; }

# A fake token-bearing URL. Never contacted: the guard only ever prints or exports
# it, and every case that would reach the curl lives in deploy.yml, not here.
FAKE_HOOK="https://coolify.example.invalid/api/v1/deploy?uuid=abc&token=NOT-A-REAL-TOKEN"

# run_guard <exit-var> <out-var> [ENV=VAL ...]
# Runs the guard in a clean environment, with GITHUB_OUTPUT and
# GITHUB_STEP_SUMMARY pointed at per-case files so the exported values and the
# rendered summary can be asserted rather than assumed.
OUT=""; STATUS=0; GH_OUTPUT_FILE=""; GH_SUMMARY_FILE=""
run_guard() {
  local slug="$1"; shift
  GH_OUTPUT_FILE="${WORK}/${slug}.output"
  GH_SUMMARY_FILE="${WORK}/${slug}.summary"
  : > "$GH_OUTPUT_FILE"
  : > "$GH_SUMMARY_FILE"
  # `env -i` so a variable that happens to be set in the caller's shell — a real
  # STAGING_URL, say — cannot make a "nothing is set" case quietly pass.
  OUT="$(env -i PATH="$PATH" \
        GITHUB_OUTPUT="$GH_OUTPUT_FILE" \
        GITHUB_STEP_SUMMARY="$GH_SUMMARY_FILE" \
        "$@" bash "$GUARD" 2>&1)"
  STATUS=$?
}

# expect_refusal <slug> <expected substring> [ENV=VAL ...]
expect_refusal() {
  local slug="$1" expected="$2"; shift 2
  n=$((n + 1))
  run_guard "$slug" "$@"

  if [ "$STATUS" -eq 0 ]; then
    fail "$slug: the guard PASSED with no usable deploy target. This is the TOG-913 defect."
    printf '%s\n' "$OUT" | sed 's/^/        /'
    rc=1
    return
  fi
  if [ "$STATUS" -ne 1 ]; then
    fail "$slug: expected exit 1, got ${STATUS}"
    printf '%s\n' "$OUT" | sed 's/^/        /'
    rc=1
    return
  fi
  if ! grep -qF -- "$expected" <<< "$OUT"; then
    fail "$slug: refused, but not for the stated reason. Wanted: ${expected}"
    printf '%s\n' "$OUT" | sed 's/^/        /'
    rc=1
    return
  fi
  # A refusal that does not say what to do next is how an expected red becomes
  # noise, and noise is what the original skip-and-pass was trying to avoid.
  if ! grep -qF 'TOG-780' <<< "$OUT"; then
    fail "$slug: the refusal never names TOG-780, so nobody reading it learns why it is red or how to clear it"
    rc=1
    return
  fi
  if ! grep -q 'staging was NOT deployed' "$GH_SUMMARY_FILE"; then
    fail "$slug: nothing was written to the step summary; the run would be red with no explanation in the UI"
    rc=1
    return
  fi
  pass "$slug"
}

# expect_ready <slug> [ENV=VAL ...]
expect_ready() {
  local slug="$1"; shift
  n=$((n + 1))
  run_guard "$slug" "$@"

  if [ "$STATUS" -ne 0 ]; then
    fail "$slug: expected the guard to resolve a target, got exit ${STATUS}"
    printf '%s\n' "$OUT" | sed 's/^/        /'
    rc=1
    return
  fi
  if ! grep -q '^hook=' "$GH_OUTPUT_FILE"; then
    fail "$slug: resolved, but never exported \`hook\` — the deploy step would POST to an empty URL"
    rc=1
    return
  fi
  if ! grep -q '^url=' "$GH_OUTPUT_FILE"; then
    fail "$slug: resolved, but never exported \`url\` — the health poll would curl nothing and pass"
    rc=1
    return
  fi
  # The token must not reach a log. This is the one assertion here whose failure
  # would be a security incident rather than a reliability one.
  if grep -qF 'NOT-A-REAL-TOKEN' <<< "$OUT"; then
    fail "$slug: the guard echoed the deploy hook, which carries the token, into its own output"
    rc=1
    return
  fi
  pass "$slug"
}

printf '\n\033[1m==> No target must not be a pass (TOG-913)\033[0m\n'

# The live configuration, today, and the exact case that used to report green.
expect_refusal nothing-set 'COOLIFY_STAGING_DEPLOY_HOOK (secret) and STAGING_URL (variable) is not set'

# Half a target. A hook with no URL would POST and then have no way to learn
# whether anything came back up — the same false green in a smaller box, and
# precisely what `if: ... && vars.STAGING_URL != ''` used to allow.
expect_refusal hook-only 'STAGING_URL (variable) is not set' \
  "COOLIFY_STAGING_DEPLOY_HOOK=${FAKE_HOOK}"

expect_refusal url-only 'COOLIFY_STAGING_DEPLOY_HOOK (secret) is not set' \
  "STAGING_URL=https://staging.togetherweown.com"

# An Actions secret that exists but is empty is not a target. `-n` on the raw
# value would call this ready and curl to nowhere.
expect_refusal empty-hook 'COOLIFY_STAGING_DEPLOY_HOOK (secret)' \
  "COOLIFY_STAGING_DEPLOY_HOOK=" \
  "STAGING_URL=https://staging.togetherweown.com"

# Same, for the newline a paste into the secrets UI leaves behind.
expect_refusal whitespace-hook 'COOLIFY_STAGING_DEPLOY_HOOK (secret)' \
  "COOLIFY_STAGING_DEPLOY_HOOK=$(printf '  \n ')" \
  "STAGING_URL=https://staging.togetherweown.com"

printf '\n\033[1m==> A real target still deploys\033[0m\n'

# The negative control. Without this the whole file could pass by refusing
# everything, which is a guard that is broken in the opposite direction — and the
# day Coolify is provisioned, that would block every deploy instead.
expect_ready ready \
  "COOLIFY_STAGING_DEPLOY_HOOK=${FAKE_HOOK}" \
  "STAGING_URL=https://staging.togetherweown.com"

n=$((n + 1))
run_guard ready-trailing \
  "COOLIFY_STAGING_DEPLOY_HOOK=${FAKE_HOOK}" \
  "STAGING_URL=https://staging.togetherweown.com/"
if [ "$STATUS" -eq 0 ] && grep -qx 'url=https://staging.togetherweown.com' "$GH_OUTPUT_FILE"; then
  pass ready-trailing
else
  fail "ready-trailing: a trailing slash survived, so the health poll would request '…com//up'"
  grep '^url=' "$GH_OUTPUT_FILE" | sed 's/^/        /'
  rc=1
fi

printf '\n\033[1m==> The production tripwire warns and does not fail\033[0m\n'

# Nothing reads a production hook: production ships by hand (TWO-91, TOG-118). Its
# appearance means somebody is rebuilding that path, which is worth saying and not
# worth failing a staging deploy over.
n=$((n + 1))
run_guard production-warn \
  "COOLIFY_STAGING_DEPLOY_HOOK=${FAKE_HOOK}" \
  "STAGING_URL=https://staging.togetherweown.com" \
  "COOLIFY_PRODUCTION_DEPLOY_HOOK=https://coolify.example.invalid/prod"
if [ "$STATUS" -eq 0 ] && grep -qF '::warning::COOLIFY_PRODUCTION_DEPLOY_HOOK is set' <<< "$OUT"; then
  pass production-warn
else
  fail "production-warn: expected a warning and exit 0, got exit ${STATUS}"
  printf '%s\n' "$OUT" | sed 's/^/        /'
  rc=1
fi

# Exit 2 and exit 1 must stay distinguishable: "you called this wrongly" and "there
# is no deploy target" send a reader to different places, and a script that
# collapses them reports a configuration problem as a usage error.
n=$((n + 1))
OUT="$(env -i PATH="$PATH" bash "$GUARD" --force 2>&1)"; STATUS=$?
if [ "$STATUS" -eq 2 ]; then
  pass args-refused
else
  fail "args-refused: expected exit 2 for an unexpected argument, got ${STATUS}"
  rc=1
fi

printf '\n\033[1m==> The workflow cannot go back to skipping\033[0m\n'

# The guard above is only load-bearing while deploy.yml actually calls it. Deleting
# the step is a one-line edit that leaves every test above passing, so it needs its
# own assertion — the TWO-94 lesson applied to this file.
#
# Anchored to a `run:` line, not to the string appearing anywhere in the file.
# Written the loose way first, this case passed against a deploy.yml whose guard
# step had been deleted — the header comment above still says the words
# "ci/deploy-target.sh", so a plain grep matched prose and reported PASS while the
# workflow ran no guard at all. A self-test that can pass against the very defect
# it names is the TOG-913 shape one level up, so it is worth the regex.
n=$((n + 1))
if grep -qE '^[[:space:]]*run:[[:space:]]*\./ci/deploy-target\.sh[[:space:]]*$' "$WORKFLOW"; then
  pass workflow-calls-guard
else
  fail "workflow-calls-guard: no step in .github/workflows/deploy.yml runs ./ci/deploy-target.sh, so nothing checks that a deploy had a target"
  rc=1
fi

# The original defect's exact shape: a step condition that turns "the secret is
# missing" into "this step did not run", which GitHub reports as a passing job.
# Both historical forms are caught — `steps.guard.outputs.ready == 'true'` and
# `vars.STAGING_URL != ''`.
#
# Do not hide grep's status with `|| true`: exit 1 means the workflow is clean,
# while exit 2 means there was no readable workflow to inspect. Collapsing those
# two cases would let a deleted deploy.yml report PASS — this assertion's own
# TOG-913-shaped false green (TOG-934).
n=$((n + 1))
if [ ! -r "$WORKFLOW" ]; then
  fail "workflow-has-no-skip: .github/workflows/deploy.yml is missing or unreadable, so the suite cannot prove that deploy steps are never skipped"
  rc=1
else
  skips="$(grep -nE "^\s*if:.*(secrets\.|vars\.|outputs\.ready)" "$WORKFLOW")"
  grep_status=$?
  case "$grep_status" in
    0)
      fail "workflow-has-no-skip: a step in deploy.yml is gated on a secret or target being set. That is the TOG-913 defect: the job then reports success having deployed nothing."
      printf '%s\n' "$skips" | sed 's/^/        /'
      rc=1
      ;;
    1)
      pass workflow-has-no-skip
      ;;
    *)
      fail "workflow-has-no-skip: grep could not inspect .github/workflows/deploy.yml (exit ${grep_status})"
      rc=1
      ;;
  esac
fi

printf '\n'
if [ "$rc" -eq 0 ]; then
  printf '\033[32m%s cases passed.\033[0m A deploy with no target is red, and a real target still deploys.\n' "$n"
else
  printf '\033[31mSome cases failed (of %s).\033[0m\n' "$n"
fi
exit "$rc"
