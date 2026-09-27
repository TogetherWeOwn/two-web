#!/usr/bin/env bash
set -uo pipefail

# Every job must land on a GitHub-hosted runner, and must prove it landed on one.
#
# Publication gate (TOG-4025, TOG-4818): public repositories must use
# GitHub-hosted runners only, never self-hosted. A `runs-on:` label is only a
# request — a typo silently reroutes rather than erroring — so each job asserts
# where it actually ran, and fails closed on anything else. Running untrusted
# pull-request code on our own hardware is exactly what this stops.
#
# The evidence line doubles as the audit record. The job publishes its own
# runner name as a `::notice`, which lands in the check-run annotations API —
# readable with `checks=read`, without the `actions:read` scope this repo's
# token broker does not issue.
#
# Usage: ./ci/attest-runner.sh   (no arguments; reads the runner env)

[ "$#" -eq 0 ] || { echo "usage: $0" >&2; exit 2; }

# RUNNER_NAME is set by the Actions runner itself, not by the workflow, so it
# cannot be spoofed by an edit to a YAML file under review.
runner_name="${RUNNER_NAME:-}"
runner_env="${RUNNER_ENVIRONMENT:-unknown}"
job="${GITHUB_JOB:-unknown}"

if [ -z "$runner_name" ]; then
  # Unset means this is not an Actions job at all. Refuse rather than guess: a
  # green here outside CI would be a check that passes where it cannot look.
  echo "::error::attest-runner: RUNNER_NAME is unset. This step must run inside a GitHub Actions job."
  exit 1
fi

# `github-hosted` is the value GitHub sets for its own images; anything we
# operate reports `self-hosted`. A self-hosted runner here means a job that
# would execute pull-request code on our hardware — refuse it.
if [ "$runner_env" != "github-hosted" ]; then
  echo "::error::attest-runner: job \`${job}\` ran on a ${runner_env} runner (RUNNER_NAME=${runner_name}). Every job in this repository must run on GitHub-hosted runners — public repositories must never execute on self-hosted hardware (TOG-4025). Check \`runs-on:\` for this job."
  exit 1
fi

# The evidence line. `::notice` becomes a check-run annotation, which is
# readable on `checks=read` via
#   GET /repos/{owner}/{repo}/check-runs/{id}/annotations
# and that is the per-job runner_name record the publication gate asks for.
echo "::notice title=runner::job=${job} runner_name=${runner_name} environment=${runner_env}"
echo "attest-runner: job '${job}' ran on GitHub-hosted runner '${runner_name}'"
