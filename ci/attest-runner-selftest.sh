#!/usr/bin/env bash
#
# Publication policy: accept GitHub-hosted evidence, reject self-hosted and
# unknown environments, and preserve the per-job runner annotation. A runner's
# name alone is not proof. This is a regression check, not a security boundary
# against untrusted workflow edits; repository runner access is an external gate.
# Usage: ./ci/attest-runner-selftest.sh

set -uo pipefail

cd "$(dirname "$0")/.."

rc=0
n=0
fail() { printf '\033[31mFAIL: %s\033[0m\n' "$*" >&2; rc=1; }
pass() { printf '\033[32mPASS: %s\033[0m\n' "$*"; }

# run_case <slug> <expected status> <expected substring of output> [VAR=VAL...]
# Anything after the substring is the environment. `env -i` so a real Actions
# run (this suite executes inside one) cannot leak its own RUNNER_NAME in and
# make a negative case pass for the wrong reason.
run_case() {
  local slug="$1" want="$2" expected="$3"; shift 3
  local out status
  n=$((n + 1))
  out="$(env -i PATH="$PATH" "$@" ./ci/attest-runner.sh 2>&1)"
  status=$?

  if [ "$status" -ne "$want" ]; then
    fail "$slug: expected exit ${want}, got ${status}"
    printf '%s\n' "$out" | sed 's/^/        /'
    return
  fi
  if [ -n "$expected" ] && ! grep -qF -- "$expected" <<< "$out"; then
    fail "$slug: exit ${status} was right but the reason was not. Wanted: ${expected}"
    printf '%s\n' "$out" | sed 's/^/        /'
    return
  fi
  pass "$slug"
}

printf '\n\033[1m==> A GitHub-hosted runner is accepted\033[0m\n'

run_case hosted 0 "ran on GitHub-hosted runner 'GitHub Actions 1'" \
  'RUNNER_NAME=GitHub Actions 1' RUNNER_ENVIRONMENT=github-hosted GITHUB_JOB=pest
run_case notice 0 "::notice title=runner::job=dusk runner_name=GitHub Actions 2 environment=github-hosted" \
  'RUNNER_NAME=GitHub Actions 2' RUNNER_ENVIRONMENT=github-hosted GITHUB_JOB=dusk

printf '\n\033[1m==> Private and unknown environments are rejected\033[0m\n'

run_case private 1 "ran on a self-hosted runner" \
  RUNNER_NAME=coolify-vps-1 RUNNER_ENVIRONMENT=self-hosted GITHUB_JOB=static
run_case name-is-not-proof 1 "ran on a self-hosted runner" \
  'RUNNER_NAME=GitHub Actions 1' RUNNER_ENVIRONMENT=self-hosted GITHUB_JOB=static
run_case unknown 1 "ran on a unknown runner" \
  'RUNNER_NAME=GitHub Actions 1' GITHUB_JOB=budgets

printf '\n\033[1m==> Outside Actions it refuses rather than guesses\033[0m\n'

run_case unset 1 "RUNNER_NAME is unset" \
  RUNNER_ENVIRONMENT=self-hosted GITHUB_JOB=static
run_case empty 1 "RUNNER_NAME is unset" \
  RUNNER_NAME= RUNNER_ENVIRONMENT=self-hosted GITHUB_JOB=static

# Not routed through run_case: that helper puts everything after the substring
# into the *environment*, and an argument is not an environment entry. Spelled
# out here so the ordering is visible rather than encoded in a helper.
n=$((n + 1))
args_out="$(env -i PATH="$PATH" RUNNER_NAME=coolify-vps-1 RUNNER_ENVIRONMENT=self-hosted \
  GITHUB_JOB=static ./ci/attest-runner.sh --force 2>&1)"
args_status=$?
if [ "$args_status" -ne 2 ]; then
  fail "args: expected exit 2, got ${args_status}"
  printf '%s\n' "$args_out" | sed 's/^/        /'
elif ! grep -qF -- "usage:" <<< "$args_out"; then
  fail "args: exit 2 was right but the reason was not. Wanted: usage:"
  printf '%s\n' "$args_out" | sed 's/^/        /'
else
  pass "args"
fi

printf '\n\033[1m==> Every job still attests\033[0m\n'

# The guard is per-job, so coverage is per-job too: one job that skips the step
# is one job that can be moved back onto a private runner without anything
# going red. Enumerated from the files rather than from a stored list, so a new
# job is covered the moment it exists instead of when someone remembers.
n=$((n + 1))
missing=""
jobs_seen=0
for f in .github/workflows/*.yml; do
  [ -f "$f" ] || continue
  while read -r job; do
    [ -n "$job" ] || continue
    jobs_seen=$((jobs_seen + 1))
    blk="$(awk -v id="$job" '$0 ~ "^  " id ":[[:space:]]*$" {j=1;next} j && /^  [a-zA-Z0-9_-]+:[[:space:]]*$/{exit} j{print}' "$f")"
    # Anchored to `run:`, not to the bare path. Every one of these steps is
    # introduced by a comment that names the script, so matching the path alone
    # matches the comment — delete the step, leave the comment, and this check
    # reports full coverage on a job that has stopped attesting. That is not
    # hypothetical: it survived the mutation run that was supposed to kill it.
    grep -qE '^[[:space:]]*run:[[:space:]]*\./ci/attest-runner\.sh[[:space:]]*$' <<< "$blk" \
      || missing="${missing} $(basename "$f"):${job}"
  done <<< "$(awk '/^jobs:/{j=1;next} j && /^  [a-zA-Z0-9_-]+:[[:space:]]*$/{gsub(/[ :]/,"");print}' "$f")"
done

if [ "$jobs_seen" -eq 0 ]; then
  fail "every-job-attests: found no jobs at all — this assertion is vacuous, not passing"
elif [ -n "$missing" ]; then
  fail "every-job-attests: these jobs do not run ci/attest-runner.sh and could run on a private runner unnoticed:${missing}"
else
  pass "every-job-attests: all ${jobs_seen} jobs across $(ls .github/workflows/*.yml | wc -l | tr -d ' ') workflows attest their runner"
fi

printf '\n'
if [ "$rc" -eq 0 ]; then
  printf '\033[32m%s/%s checks passed\033[0m\n' "$n" "$n"
else
  printf '\033[31mattest-runner is not the guard it claims to be\033[0m\n'
fi
exit "$rc"
