#!/usr/bin/env bash
#
# Post the staging deploy result to Discord. (TOG-7326)
#
# deploy.yml used to send no notification on success or failure: a deploy that
# failed overnight waited until somebody loaded the page or opened the Actions
# tab. This posts one short message per staging run — success, failure, or
# cancelled — to a Discord webhook whose URL lives in secrets and never in
# this repo.
#
# Two deliberate non-properties, both load-bearing:
#
#   1. This step never fails the job. A missing webhook is a warning, not a
#      verdict; a Discord outage is a warning, not a verdict. The deploy's
#      result was decided by the steps above — a notifier that can redden a
#      green deploy (or green a red one) is the TOG-913 defect in a new box.
#   2. The webhook URL is never printed. It carries the token, the same as
#      the Coolify hook in ci/deploy-target.sh, so the same rule holds: the
#      self-test fails the pull request that echoes it.
#
# Usage:
#   ./ci/deploy-notify.sh            # takes no arguments; reads the environment
#
# Reads from the environment, all optional except the webhook:
#
#   STAGING_DEPLOY_NOTIFY_WEBHOOK  Discord webhook URL, token included (secret)
#   DEPLOY_STATUS                  the job's result: success | failure | cancelled
#                                  (the workflow passes ${{ job.status }})
#   STAGING_URL                    public staging URL, when the target resolved
#                                  (empty when the target step itself failed)
#   GITHUB_REPOSITORY, GITHUB_SHA, GITHUB_RUN_ID
#                                  standard Actions context; absent locally
#
# Exit codes are the contract the self-test pins:
#   0  the notification was posted, or skipped with a warning, or failed to
#      post with a warning — never a verdict on the deploy
#   2  the script was called wrongly
#
# Proven by ci/deploy-notify-selftest.sh, which runs in `static` against a
# stub receiver: success and failure posts observed, the missing-secret
# warning, the unreachable-Discord warning, and the proof the URL never
# reaches the logs. Live proof on a real staging deploy is deferred to
# Coolify provisioning (TOG-780): today `staging` is red with no target, so
# the offline self-test is the evidence, not a staging run that cannot exist.

set -uo pipefail

if [ "$#" -gt 0 ]; then
  printf 'deploy-notify: takes no arguments, got: %s\n' "$*" >&2
  exit 2
fi

# `${VAR:-}` and then a whitespace strip, because an Actions secret that exists
# but is empty, and one that holds a stray newline from a copy-paste, are both
# "unset" for every purpose that matters here. Same treatment as
# ci/deploy-target.sh gives the Coolify hook.
strip() { printf '%s' "${1:-}" | tr -d '[:space:]'; }

WEBHOOK="$(strip "${STAGING_DEPLOY_NOTIFY_WEBHOOK:-}")"
STATUS="${DEPLOY_STATUS:-unknown}"
STAGING_URL="$(strip "${STAGING_URL:-}")"
REPO="${GITHUB_REPOSITORY:-two-web}"
SHA="${GITHUB_SHA:-unknown}"
SHORT_SHA="${SHA:0:7}"
RUN_ID="${GITHUB_RUN_ID:-}"

if [ -z "$WEBHOOK" ]; then
  printf '::warning::STAGING_DEPLOY_NOTIFY_WEBHOOK is not set, so no deploy notification was posted. Set the repository secret to get staging success/failure posts in Discord (TOG-7326).\n'
  exit 0
fi

RUN_LINE=""
if [ -n "$REPO" ] && [ -n "$RUN_ID" ]; then
  RUN_LINE="Run: https://github.com/${REPO}/actions/runs/${RUN_ID}"
fi

TARGET_LINE=""
if [ -n "$STAGING_URL" ]; then
  TARGET_LINE="Staging: ${STAGING_URL}"
fi

case "$STATUS" in
  success)
    HEADLINE=":white_check_mark: two-web staging deploy succeeded — ${REPO}@${SHORT_SHA}"
    ;;
  failure)
    HEADLINE=":x: two-web staging deploy FAILED — ${REPO}@${SHORT_SHA}. If /up is down, roll back from the Coolify dashboard."
    ;;
  cancelled)
    HEADLINE=":warning: two-web staging deploy cancelled — ${REPO}@${SHORT_SHA}."
    ;;
  *)
    HEADLINE=":question: two-web staging deploy ended with status '${STATUS}' — ${REPO}@${SHORT_SHA}."
    ;;
esac

MSG="$HEADLINE"
[ -n "$TARGET_LINE" ] && MSG="${MSG}
${TARGET_LINE}"
[ -n "$RUN_LINE" ] && MSG="${MSG}
${RUN_LINE}"

# JSON-encoded through python3 rather than interpolated, so a quote in a URL
# cannot break the payload. python3 is already a hard dependency of `static`
# (verify-pipeline.sh check 11 reads .gitleaks.toml through tomllib).
if ! PAYLOAD="$(MSG="$MSG" python3 -c 'import json, os; print(json.dumps({"username": "two-web staging", "content": os.environ["MSG"]}))' 2>/dev/null)"; then
  printf '::warning::deploy-notify: could not encode the notification payload; the deploy result stands, only the notification failed.\n'
  exit 0
fi

# --fail so an HTTP error is an exit code, not a cheerful log line.
# The URL carries the token, so the response is the only thing echoed.
if curl --fail --silent --show-error --max-time 20 \
    --retry 2 --retry-delay 3 --retry-all-errors \
    -H 'Content-Type: application/json' \
    -d "$PAYLOAD" "$WEBHOOK" > /dev/null; then
  printf 'deploy-notify: posted %s notification.\n' "$STATUS"
else
  printf '::warning::deploy-notify: could not POST the deploy notification (Discord unreachable?). The deploy result stands; only the notification failed.\n'
  exit 0
fi
