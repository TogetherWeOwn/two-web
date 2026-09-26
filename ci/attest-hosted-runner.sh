#!/usr/bin/env bash
set -uo pipefail

# The mirror image of ci/attest-runner.sh, for the hosted-CI probe (TOG-5067).
#
# attest-runner.sh refuses `github-hosted` on billing grounds (TOG-2847). This
# probe exists to show a job *can* run on GitHub's own runners, so it refuses
# `self-hosted` instead: a `runs-on:` typo that silently rerouted the probe
# onto the private runners would report green while proving nothing.
#
# RUNNER_NAME and RUNNER_ENVIRONMENT are set by the runner itself, not by the
# workflow, so neither can be spoofed by an edit to a YAML file under review.
#
# Usage: ./ci/attest-hosted-runner.sh   (no arguments; reads the runner env)

[ "$#" -eq 0 ] || { echo "usage: $0" >&2; exit 2; }

runner_name="${RUNNER_NAME:-}"
runner_env="${RUNNER_ENVIRONMENT:-unknown}"
job="${GITHUB_JOB:-unknown}"

if [ -z "$runner_name" ]; then
  # Unset means this is not an Actions job at all. Refuse rather than guess: a
  # green here outside CI would be a check that passes where it cannot look.
  echo "::error::attest-hosted-runner: RUNNER_NAME is unset. This step must run inside a GitHub Actions job."
  exit 1
fi

# `github-hosted` is the value GitHub sets for its own runners; anything we
# operate reports `self-hosted`. Checking the environment rather than the name
# means a private runner that happened to be named like a hosted one still
# fails — the name is just a string.
if [ "$runner_env" != "github-hosted" ]; then
  echo "::error::attest-hosted-runner: job \`${job}\` ran on a ${runner_env} runner (RUNNER_NAME=${runner_name}). This probe proves GitHub-hosted CI works — it must not run on the private runners."
  exit 1
fi

# The evidence line, matching attest-runner.sh's shape: `::notice` becomes a
# check-run annotation, readable on `checks=read` without `actions:read`.
echo "::notice title=runner::job=${job} runner_name=${runner_name} environment=${runner_env}"
echo "attest-hosted-runner: job '${job}' ran on GitHub-hosted runner '${runner_name}'"
