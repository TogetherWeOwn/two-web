#!/usr/bin/env bash
#
# Tests for the staging deploy notifier.
#
# ci/deploy-notify.sh exists because deploy.yml used to send no notification
# on success or failure: a failed overnight deploy waited until somebody
# loaded the page or opened the Actions tab (TOG-7326). This script posts one
# short Discord message per staging run, and it must obey two rules that are
# load-bearing rather than stylistic:
#
#   1. It never fails the job. The deploy's verdict was decided by the steps
#      above; a notifier that reddens a green deploy (missing secret, Discord
#      down) is the TOG-913 false-verdict defect in a new box.
#   2. The webhook URL is never printed. It carries the token, like the
#      Coolify hook in ci/deploy-target.sh — echoing it would be a security
#      incident, not a reliability one.
#
# Nothing here needs the network, a token, GitHub, or a deploy target. The
# Discord side is a stub receiver on 127.0.0.1 that records POST bodies, so
# success and failure notifications are *observed*, not assumed. Port 18742:
# adjacent to bin/smoke-staging.sh's 18731, verified free of every other
# self-test by grep at authoring time.
#
# What is pinned:
#
#   success-posted      status=success reaches the stub, content names the SHA
#   failure-posted      status=failure reaches the stub, names the SHA, says rollback
#   cancelled-posted    status=cancelled reaches the stub as a warning, not a failure
#   missing-secret      no webhook -> warning, exit 0 (never a verdict)
#   empty-secret        webhook set to "" -> same as missing
#   unreachable         Discord down -> warning, exit 0 (never a verdict)
#   secret-never-echoed no case prints the token-bearing URL
#   args                called with an argument -> exit 2
#
# And the three assertions that are really about TOG-913/TOG-7326 rather than
# about this script:
#
#   workflow-calls-notify   deploy.yml still runs ci/deploy-notify.sh
#   notify-runs-always      the step carries `if: always()` — without it the
#                           notifier is skipped on exactly the runs (failures)
#                           it exists to report, a silent twin of the old skip
#   notify-never-decides    no `continue-on-error: false` removal risk: the
#                           script itself exits 0 on every notify failure, so a
#                           Discord outage cannot redden a green deploy
#
# Usage: ./ci/deploy-notify-selftest.sh

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NOTIFY="${REPO_ROOT}/ci/deploy-notify.sh"
WORKFLOW="${REPO_ROOT}/.github/workflows/deploy.yml"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/deploy-notify-selftest.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

rc=0
n=0

fail() { printf '\033[31mFAIL: %s\033[0m\n' "$*" >&2; rc=1; }
pass() { printf '\033[32mPASS: %s\033[0m\n' "$*"; }

# A fake token-bearing URL. Never contacted except at the stub below: every
# case that would reach real Discord lives in deploy.yml, not here. Shaped to
# NOT match our own `discord-webhook` gitleaks rule (that pattern only fires
# on discord.com/api/webhooks), so this file is not a finding in itself.
PORT=18742
FAKE_HOOK="http://127.0.0.1:${PORT}/hook?token=NOT-A-REAL-TOKEN"

OUT=""; STATUS=0
run_notify() {
  local slug="$1"; shift
  # `env -i` so a variable set in the caller's shell cannot make a "missing"
  # case quietly pass — same reason as in deploy-target-selftest.sh.
  OUT="$(env -i PATH="$PATH" "$@" bash "$NOTIFY" 2>&1)"
  STATUS=$?
  printf '%s' "$OUT" > "${WORK}/${slug}.out"
}

# Start the stub Discord receiver: 204 per POST (what Discord answers), every
# body appended to $WORK/received. Prints its pid.
STUB_PID=""
start_stub() {
  : > "${WORK}/received"
  WORK_DIR="$WORK" PORT="$PORT" python3 - "$PORT" > /dev/null 2>&1 <<'EOF' &
import http.server, os
work = os.environ["WORK_DIR"]
class H(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get("Content-Length", 0)))
        with open(os.path.join(work, "received"), "ab") as fh:
            fh.write(body + b"\n---\n")
        self.send_response(204)
        self.end_headers()
    def log_message(self, *a):
        pass
http.server.HTTPServer(("127.0.0.1", int(os.environ["PORT"])), H).serve_forever()
EOF
  STUB_PID=$!
  # Wait for the bind rather than sleeping blind: a slow runner that has not
  # bound yet fails the first POST with "connection refused", which reads as
  # a notifier defect and is really a test-harness race.
  for _ in $(seq 1 50); do
    if command -v python3 >/dev/null 2>&1 && python3 -c "import socket; socket.create_connection(('127.0.0.1', $PORT), timeout=1).close()" 2>/dev/null; then
      return 0
    fi
    sleep 0.1
  done
  fail "stub receiver never bound to 127.0.0.1:${PORT}"
  return 1
}

stop_stub() {
  if [ -n "$STUB_PID" ]; then kill "$STUB_PID" 2>/dev/null || true; STUB_PID=""; fi
  sleep 0.2
}
trap 'stop_stub; rm -rf "$WORK"' EXIT

# expect_posted <slug> <status> <must-contain...>
# Runs the notifier against the live stub and asserts the message arrived.
expect_posted() {
  local slug="$1" status="$2"; shift 2
  n=$((n + 1))
  run_notify "$slug" \
    "STAGING_DEPLOY_NOTIFY_WEBHOOK=${FAKE_HOOK}" \
    "DEPLOY_STATUS=${status}" \
    "STAGING_URL=https://staging.togetherweown.com" \
    "GITHUB_REPOSITORY=TogetherWeOwn/two-web" \
    "GITHUB_SHA=abc1234567890abcdef" \
    "GITHUB_RUN_ID=12345"
  if [ "$STATUS" -ne 0 ]; then
    fail "$slug: notifier exited ${STATUS} — it must never fail the job, whatever Discord says"
    printf '%s\n' "$OUT" | sed 's/^/        /'
    return
  fi
  for want in "$@"; do
    if ! grep -qF -- "$want" "${WORK}/received"; then
      fail "$slug: the stub never received a message containing: ${want}"
      sed 's/^/        /' "${WORK}/received" 2>/dev/null
      return
    fi
  done
  if grep -qF 'NOT-A-REAL-TOKEN' <<< "$OUT"; then
    fail "$slug: the notifier echoed the webhook URL, which carries the token, into its own output"
    return
  fi
  pass "$slug"
}

printf '\n\033[1m==> Notifications are observed, not assumed\033[0m\n'

start_stub || { printf '\033[31mSome cases failed (of %s).\033[0m\n' "$n"; exit 1; }

: > "${WORK}/received"
expect_posted success-posted success "staging deploy succeeded" "abc1234"

: > "${WORK}/received"
expect_posted failure-posted failure "staging deploy FAILED" "roll back" "abc1234"

: > "${WORK}/received"
expect_posted cancelled-posted cancelled "cancelled" "abc1234"

stop_stub

printf '\n\033[1m==> The notifier never decides the deploy\033[0m\n'

# No webhook configured: a warning and exit 0. Missing notification tooling
# must not turn a green deploy red — that is the whole of rule 1.
n=$((n + 1))
run_notify missing-secret \
  "DEPLOY_STATUS=success" \
  "STAGING_URL=https://staging.togetherweown.com"
if [ "$STATUS" -eq 0 ] && grep -qF 'STAGING_DEPLOY_NOTIFY_WEBHOOK is not set' <<< "$OUT"; then
  pass missing-secret
else
  fail "missing-secret: expected a warning and exit 0, got exit ${STATUS}"
  printf '%s\n' "$OUT" | sed 's/^/        /'
  rc=1
fi

# An Actions secret that exists but is empty is not a webhook.
n=$((n + 1))
run_notify empty-secret \
  "STAGING_DEPLOY_NOTIFY_WEBHOOK=" \
  "DEPLOY_STATUS=failure"
if [ "$STATUS" -eq 0 ] && grep -qF 'STAGING_DEPLOY_NOTIFY_WEBHOOK is not set' <<< "$OUT"; then
  pass empty-secret
else
  fail "empty-secret: expected a warning and exit 0, got exit ${STATUS}"
  printf '%s\n' "$OUT" | sed 's/^/        /'
  rc=1
fi

# Discord unreachable (nothing on 18749): a warning and exit 0. A Discord
# outage reddening a green deploy would teach the team to ignore the check —
# the noise the old skip-and-pass header feared, from the other direction.
n=$((n + 1))
run_notify unreachable \
  "STAGING_DEPLOY_NOTIFY_WEBHOOK=http://127.0.0.1:18749/hook?token=NOT-A-REAL-TOKEN" \
  "DEPLOY_STATUS=success"
if [ "$STATUS" -eq 0 ] && grep -qF 'could not POST' <<< "$OUT"; then
  pass unreachable
else
  fail "unreachable: expected a warning and exit 0, got exit ${STATUS}"
  printf '%s\n' "$OUT" | sed 's/^/        /'
  rc=1
fi

if grep -rqF 'NOT-A-REAL-TOKEN' "${WORK}/missing-secret.out" "${WORK}/empty-secret.out" "${WORK}/unreachable.out" 2>/dev/null; then
  n=$((n + 1))
  fail "secret-never-echoed: a warning path printed the token-bearing URL"
  rc=1
else
  n=$((n + 1))
  pass secret-never-echoed
fi

# Exit 2 and exit 0 must stay distinguishable: "you called this wrongly" and
# "the notification was handled" send a reader to different places.
n=$((n + 1))
OUT="$(env -i PATH="$PATH" bash "$NOTIFY" --force 2>&1)"; STATUS=$?
if [ "$STATUS" -eq 2 ]; then
  pass args-refused
else
  fail "args-refused: expected exit 2 for an unexpected argument, got ${STATUS}"
  rc=1
fi

printf '\n\033[1m==> The workflow cannot silently lose the notifier\033[0m\n'

# The script above is only load-bearing while deploy.yml actually calls it.
n=$((n + 1))
if grep -qE '^[[:space:]]*run:[[:space:]]*\./ci/deploy-notify\.sh[[:space:]]*$' "$WORKFLOW"; then
  pass workflow-calls-notify
else
  fail "workflow-calls-notify: no step in .github/workflows/deploy.yml runs ./ci/deploy-notify.sh"
  rc=1
fi

# The one sanctioned `if:` in deploy.yml. Every other step runs on success
# only, so without `always()` the notifier is skipped on exactly the runs —
# failures — it exists to report. That is the old skip-and-pass shape with
# the polarity reversed: silence instead of false green, same unreported
# deploy.
n=$((n + 1))
# No `-q` on the producing grep: `grep -q` exits the instant it matches, which
# closes the pipe on the consumer — the producer|grep -q race documented in
# ci/verify-pipeline.sh. The consumer keeps its `-q`; it reads, it does not feed.
if grep -B15 'deploy-notify\.sh' "$WORKFLOW" | grep -qE '^\s*if:\s*always\(\)\s*$'; then
  pass notify-runs-always
else
  fail "notify-runs-always: the deploy-notify step has no \`if: always()\` — it would be skipped on every failed deploy, which is the run it exists to report"
  rc=1
fi

# The notifier must be the LAST step. DEPLOY_STATUS reads `${{ job.status }}`,
# which only reflects every step above — including the smoke check — when
# nothing runs after it. A notify placed before the smoke step would post
# "success" for a deploy whose smoke check then fails: a false green from the
# notifier itself, the TOG-913 defect wearing a new badge.
n=$((n + 1))
LAST_RUN="$(grep -E '^[[:space:]]*run:' "$WORKFLOW" | tail -n 1)"
case "$LAST_RUN" in
  *deploy-notify.sh*)
    pass notify-is-last
    ;;
  *)
    fail "notify-is-last: the last \`run:\` step in deploy.yml is '${LAST_RUN}' — the notifier must run last so DEPLOY_STATUS covers the smoke check"
    rc=1
    ;;
esac

printf '\n'
if [ "$rc" -eq 0 ]; then
  printf '\033[32m%s cases passed.\033[0m Success and failure notify; nothing here can fail the deploy.\n' "$n"
else
  printf '\033[31mSome cases failed (of %s).\033[0m\n' "$n"
fi
exit "$rc"
