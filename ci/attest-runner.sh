#!/usr/bin/env bash
set -uo pipefail

# Publication CI must use disposable GitHub-hosted runners, never the VPS.
# `runs-on` is the scheduling boundary; this annotation supplies per-job evidence
# through the checks API. Environment values are evidence, not a sandbox against
# malicious workflow edits. Repository runner access must also exclude the VPS.
# Usage: ./ci/attest-runner.sh (no arguments; reads the Actions runner env)

[ "$#" -eq 0 ] || { echo "usage: $0" >&2; exit 2; }

runner_name="${RUNNER_NAME:-}"
runner_env="${RUNNER_ENVIRONMENT:-unknown}"
job="${GITHUB_JOB:-unknown}"

if [ -z "$runner_name" ]; then
  echo "::error::attest-runner: RUNNER_NAME is unset. This step must run inside a GitHub Actions job."
  exit 1
fi

if [ "$runner_env" != "github-hosted" ]; then
  echo "::error::attest-runner: job ${job} ran on a ${runner_env} runner. Publication CI requires GitHub-hosted runners; check runs-on and repository runner access."
  exit 1
fi

echo "::notice title=runner::job=${job} runner_name=${runner_name} environment=${runner_env}"
echo "attest-runner: job '${job}' ran on GitHub-hosted runner '${runner_name}'"
