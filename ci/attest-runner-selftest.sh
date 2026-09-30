#!/usr/bin/env bash
#
# Tests for the guard that requires a GitHub-hosted runner.
#
# ci/attest-runner.sh is the only thing in this repository that can tell where a
# job actually ran. It is also the kind of check that is invisible when it stops
# working: it lives in the happy path, it passes on every normal run, and a
# version that returns 0 unconditionally looks identical in review and in the
# logs. The failure it exists to catch — a job silently rescheduled onto a
# private runner after a label typo — would then queue forever (the private
# group cannot serve this public repo), with a green attestation step sitting
# on top of it.
#
# So each case runs the real script with a synthetic runner environment and pins
# the exit code *and* the reason. No network, no Actions, no runner: the whole
# input is two environment variables.
#
# What is pinned:
#
#   hosted           a github-hosted runner               -> exit 0
#   notice           ...and it emits the ::notice that carries runner_name into
#                    the annotations API, which is the only place an auditor
#                    without `actions:read` can read it (TOG-2847)
#   private          RUNNER_ENVIRONMENT=self-hosted       -> exit 1  (the misrouted case)
#   private-spoofed  self-hosted *named* like a hosted runner -> exit 1  (name alone is not proof)
#   env-unset        named runner, RUNNER_ENVIRONMENT absent -> exit 1 (unknown)
#   env-unsupported  named runner, unsupported environment -> exit 1 (names the value)
#   rejected cases   ...and none emits a success ::notice
#   unset            RUNNER_NAME absent, i.e. not in CI   -> exit 1  (refuse, do not guess)
#   empty            RUNNER_NAME set but empty            -> exit 1
#   args             called with an argument              -> exit 2
#
# And the assertion that is about the workflows rather than about this script —
# that a job cannot quietly stop attesting:
#
#   every-job-attests  every job in every workflow runs ci/attest-runner.sh
#
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
  if [ "$want" -ne 0 ] && grep -qF -- "::notice" <<< "$out"; then
    fail "$slug: rejected runner emitted a success notice"
    printf '%s\n' "$out" | sed 's/^/        /'
    return
  fi
  pass "$slug"
}

printf '\n\033[1m==> A GitHub-hosted runner is accepted\033[0m\n'

run_case hosted 0 "ran on GitHub-hosted runner 'gh-ubuntu-abc123'" \
  RUNNER_NAME=gh-ubuntu-abc123 RUNNER_ENVIRONMENT=github-hosted GITHUB_JOB=pest

# The ::notice is not decoration. With `actions:read` refused by this repo's
# token broker, the Actions `jobs` endpoint — the one carrying runner_name — is
# closed, and a `::notice` surfacing in the check-run annotations endpoint is
# the only machine-readable record of where a job ran. If this line is ever
# dropped the attestation still passes and the evidence silently disappears,
# which is the exact shape of defect this suite exists for.
run_case notice 0 "::notice title=runner::job=dusk runner_name=gh-ubuntu-def456 environment=github-hosted" \
  RUNNER_NAME=gh-ubuntu-def456 RUNNER_ENVIRONMENT=github-hosted GITHUB_JOB=dusk

printf '\n\033[1m==> A private runner is rejected\033[0m\n'

run_case private 1 "ran on a self-hosted runner" \
  RUNNER_NAME=coolify-vps-3 RUNNER_ENVIRONMENT=self-hosted GITHUB_JOB=static

# Two independent signals, and the weaker one must not be able to carry the
# check on its own. GitHub sets RUNNER_ENVIRONMENT; the name is just a string.
run_case private-spoofed 1 "ran on a self-hosted runner" \
  RUNNER_NAME=gh-ubuntu-abc123 RUNNER_ENVIRONMENT=self-hosted GITHUB_JOB=static

printf '\n\033[1m==> A named runner without a supported environment is rejected\033[0m\n'

run_case env-unset 1 "ran on a unknown runner (RUNNER_NAME=gh-ubuntu-abc123)" \
  RUNNER_NAME=gh-ubuntu-abc123 GITHUB_JOB=static
run_case env-unsupported 1 "ran on a unsupported runner (RUNNER_NAME=gh-ubuntu-abc123)" \
  RUNNER_NAME=gh-ubuntu-abc123 RUNNER_ENVIRONMENT=unsupported GITHUB_JOB=static

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
  fail "every-job-attests: these jobs do not run ci/attest-runner.sh and could run on a misrouted runner unnoticed:${missing}"
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
