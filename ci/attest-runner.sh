#!/usr/bin/env bash
set -uo pipefail

# Every job must land on a private runner, and must prove it landed on one.
#
# Two separate problems, one step.
#
# 1. The gate. `runs-on: [self-hosted, two-selfhosted]` is a *request*. If the
#    label is misspelled, or dropped in a merge, or the runner group stops
#    offering the label, GitHub does not fail the job — it queues it, and an
#    org with GitHub-hosted runners available will happily run it on one. That
#    bills, and billing is exactly what TOG-2847 exists to stop. The only place
#    that knows where a job really ran is the job itself, so it asserts it.
#
# 2. The evidence. The card asks for each job's `runner_name`, and the Actions
#    `jobs` endpoint that carries it needs `actions:read`, which this repo's
#    token broker does not issue (contents, pull_requests, issues, metadata,
#    checks, statuses, workflows). The check-run *annotations* endpoint needs
#    only `checks=read`, which we do hold — and a `::notice` emitted here shows
#    up there. So the job publishes its own runner name into the one API an
#    auditor can actually read, instead of the migration being unverifiable
#    from outside for want of a scope.
#
# Naming: the five Coolify VPS runners register as `coolify-vps-<n>`. That
# prefix is the contract, and ci/runner-ports.sh already derives its port block
# from it — a runner outside the prefix gets a checksum slot there and fails
# here, deliberately: a host we cannot name is a host we have not audited.
#
# Usage: ./ci/attest-runner.sh   (no arguments; reads the runner env)

[ "$#" -eq 0 ] || { echo "usage: $0" >&2; exit 2; }

readonly EXPECTED_PREFIX='coolify-vps-'
readonly EXPECTED_LABEL='two-selfhosted'

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

# `self-hosted` is the value GitHub sets for any runner we operate; its hosted
# images report `github-hosted`. Checking both this and the name means a hosted
# runner that happened to be named coolify-vps-something still fails.
if [ "$runner_env" != "self-hosted" ]; then
  echo "::error::attest-runner: job \`${job}\` ran on a ${runner_env} runner (RUNNER_NAME=${runner_name}). Every job in this repository must run on the private ${EXPECTED_LABEL} runners — a GitHub-hosted job bills Actions minutes, which is what TOG-2847 moved this repository off. Check \`runs-on:\` for this job."
  exit 1
fi

case "$runner_name" in
  "${EXPECTED_PREFIX}"*) ;;
  *)
    echo "::error::attest-runner: job \`${job}\` ran on self-hosted runner \`${runner_name}\`, which is not one of the audited \`${EXPECTED_PREFIX}*\` hosts. Either an unaudited runner has joined the \`${EXPECTED_LABEL}\` group, or a runner was renamed — ci/runner-ports.sh also derives its port block from this prefix. Do not relax this check; fix the runner registration."
    exit 1
    ;;
esac

# The evidence line. `::notice` becomes a check-run annotation, which is
# readable on `checks=read` via
#   GET /repos/{owner}/{repo}/check-runs/{id}/annotations
# and that is the per-job runner_name record TOG-2847 asks for.
echo "::notice title=runner::job=${job} runner_name=${runner_name} environment=${runner_env}"
echo "attest-runner: job '${job}' ran on private runner '${runner_name}'"
