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
# Naming: every audited runner host registers under a fixed name prefix, and
# that list of prefixes is the contract. A runner outside it fails here,
# deliberately: a host we cannot name is a host we have not audited.
#
#   coolify-vps-<n>  the Coolify VPS runners (ci/runner-ports.sh gives them a
#                    numeric port block)
#   ci-rbx1-<n>      operator-run LXD VM on the rbx1 controller host (KVM
#                    isolated, firewalled to internet-only egress; added
#                    2026-09-27 to lift the CI throughput ceiling)
#   ci-w2494-<n>     operator-run runners on worker host vps-2494bf63 (agent
#                    state removed before it joined the pool, 2026-09-26)
#
# Adding a host means adding its prefix here, in the selftest, and in
# docs/ci.md — in review, never by relaxing the match.
#
# Usage: ./ci/attest-runner.sh   (no arguments; reads the runner env)

[ "$#" -eq 0 ] || { echo "usage: $0" >&2; exit 2; }

readonly AUDITED_PREFIXES=('coolify-vps-' 'ci-rbx1-' 'ci-w2494-')
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

audited=0
for prefix in "${AUDITED_PREFIXES[@]}"; do
  suffix="${runner_name#"$prefix"}"
  # Prefix plus a bare number only: `coolify-vps-evil` or `ci-rbx1-` alone is
  # not a registration we made.
  if [ "$suffix" != "$runner_name" ] && [[ "$suffix" =~ ^[0-9]+$ ]]; then
    audited=1
    break
  fi
done

case "$audited" in
  1) ;;
  *)
    echo "::error::attest-runner: job \`${job}\` ran on self-hosted runner \`${runner_name}\`, which is not one of the audited hosts (${AUDITED_PREFIXES[*]/%/<n>}). Either an unaudited runner has joined the \`${EXPECTED_LABEL}\` group, or a runner was renamed — ci/runner-ports.sh also derives its port block from this prefix. Do not relax this check; fix the runner registration."
    exit 1
    ;;
esac

# The evidence line. `::notice` becomes a check-run annotation, which is
# readable on `checks=read` via
#   GET /repos/{owner}/{repo}/check-runs/{id}/annotations
# and that is the per-job runner_name record TOG-2847 asks for.
echo "::notice title=runner::job=${job} runner_name=${runner_name} environment=${runner_env}"
echo "attest-runner: job '${job}' ran on private runner '${runner_name}'"
