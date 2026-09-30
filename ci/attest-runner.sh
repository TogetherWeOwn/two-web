#!/usr/bin/env bash
set -uo pipefail

# Every job must land on a GitHub-hosted runner, and must prove it landed on one.
#
# Two separate problems, one step.
#
# 1. The gate. `runs-on: ubuntu-latest` is a *request*. If the label is
#    misspelled, or dropped in a merge, GitHub does not fail the job — it
#    queues it until a runner picks it up, and a private runner group that
#    still offers the old label would happily run it there. Post-flip this
#    repository is public and the private `two-selfhosted` group cannot serve
#    public repos, so a job that is not on a hosted runner is a misrouted job.
#    The only place that knows where a job really ran is the job itself, so it
#    asserts it. (TOG-8909 flipped this gate: before the public flip it
#    required self-hosted to avoid billing — TOG-2847 — after the flip it
#    requires GitHub-hosted because the private runners cannot serve a public
#    repo at all.)
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

# `github-hosted` is the value GitHub sets for its own runners; runners we
# operate report `self-hosted`. Checking the environment rather than the name
# means a self-hosted runner that happened to be named like a hosted one still
# fails — the name is just a string, the environment is set by GitHub.
if [ "$runner_env" != "github-hosted" ]; then
  echo "::error::attest-runner: job \`${job}\` ran on a ${runner_env} runner (RUNNER_NAME=${runner_name}). Every job in this repository must run on GitHub-hosted runners — this repository is public and the private runner group cannot serve public repos, so a non-hosted job is misrouted. Check \`runs-on:\` for this job."
  exit 1
fi

# The evidence line. `::notice` becomes a check-run annotation, which is
# readable on `checks=read` via
#   GET /repos/{owner}/{repo}/check-runs/{id}/annotations
# and that is the per-job runner_name record TOG-2847 asks for.
echo "::notice title=runner::job=${job} runner_name=${runner_name} environment=${runner_env}"
echo "attest-runner: job '${job}' ran on GitHub-hosted runner '${runner_name}'"
