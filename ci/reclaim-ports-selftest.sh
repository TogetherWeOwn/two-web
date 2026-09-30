#!/usr/bin/env bash
#
# Tests for the cleanup that makes a persistent runner behave like a fresh one.
#
# The bug this is about is worth restating, because the symptom pointed nowhere
# near the cause. On the first post-merge `main` run after the self-hosted
# migration, `budgets` failed at "could not mint a session cookie — /admin
# cannot be measured", 57 seconds in. The identical tree had passed in 4m34 on
# the pull request, on the same runner. Nothing about sessions was broken: the
# previous run had leaked `artisan serve` on a port that ci/runner-ports.sh
# hands out to that same runner every time, the new server lost the bind and
# exited, `curl /up` was answered by the *old* process, and the job went on to
# measure an app holding a stale APP_KEY — so the cookie it minted decrypted to
# nothing and `/admin` saw a guest.
#
# Two defects, and the second is the one that made it hard: the readiness probe
# could not tell our server from a stranger's. Both are fixed, so both are
# pinned here.
#
# What is pinned:
#
#   frees-a-held-port    a listener on the port is killed and the port freed
#   leaves-others-alone  a listener on a *different* port is untouched
#   quiet-when-free      an unheld port is a silent no-op, not an error
#   multiple             every port in the block is reclaimed in one call
#   no-args              called with no ports              -> exit 2
#   bad-port             called with a non-number          -> exit 2
#   inspection-fails     ss exits 42, even with PID output  -> exit 1, no signals
#   inspection-empty     ss succeeds with no listeners     -> exit 0, no signals
#
# And the two workflow assertions, which are what actually keep the leak fixed:
#
#   budgets-reclaims     the budgets job reclaims its ports before starting
#   budgets-tears-down   the budgets job stops its servers with `if: always()`
#
# Usage: ./ci/reclaim-ports-selftest.sh

set -uo pipefail

cd "$(dirname "$0")/.."

rc=0
n=0
fail() { printf '\033[31mFAIL: %s\033[0m\n' "$*" >&2; rc=1; }
pass() { printf '\033[32mPASS: %s\033[0m\n' "$*"; }

# High, odd ports so a developer running this locally cannot collide with
# anything real. Not from runner-ports.sh: this suite must not depend on the
# block layout it is protecting.
PORT_A=39411
PORT_B=39412

PIDS=""
STUB_DIR="$(mktemp -d "${TMPDIR:-/tmp}/reclaim-ports-selftest.XXXXXX")" || exit 1
cleanup() {
  for p in $PIDS; do kill "$p" 2>/dev/null || true; done
  rm -rf "$STUB_DIR"
}
trap cleanup EXIT

# These cases do not touch the network or signal a process. BASH_ENV replaces
# even bash's builtin kill, so unexpected PID parsing cannot escape the fixture.
cat > "$STUB_DIR/ss" <<'STUB'
#!/usr/bin/env bash
printf '%s' "${STUB_SS_OUTPUT:-}"
exit "${STUB_SS_STATUS:-0}"
STUB
cat > "$STUB_DIR/effects.sh" <<'STUB'
kill() { printf 'kill %s\n' "$*" >> "$STUB_EFFECTS"; return 1; }
ps() { printf 'ps %s\n' "$*" >> "$STUB_EFFECTS"; return 1; }
sleep() { printf 'sleep %s\n' "$*" >> "$STUB_EFFECTS"; }
STUB
chmod +x "$STUB_DIR/ss"

run_inspection_fixture() {
  PATH="$STUB_DIR:$PATH" BASH_ENV="$STUB_DIR/effects.sh" \
    STUB_EFFECTS="$STUB_DIR/effects.log" ./ci/reclaim-ports.sh "$PORT_A" 2>&1
}

printf '\n\033[1m==> Listener inspection fails closed\033[0m\n'

for listing in '' 'users:(("php",pid=1234,fd=3))'; do
  n=$((n + 1))
  : > "$STUB_DIR/effects.log"
  out="$(STUB_SS_STATUS=42 STUB_SS_OUTPUT="$listing" run_inspection_fixture)"
  status=$?
  if [ "$status" -ne 1 ] || ! grep -qF "listener inspection failed for port ${PORT_A} (ss exited 42)" <<< "$out"; then
    fail "inspection-fails: expected exit 1 and an inspection diagnostic, got ${status} (${listing:-empty listing})"
  elif [ -s "$STUB_DIR/effects.log" ] || grep -qF "killing it" <<< "$out"; then
    fail "inspection-fails: attempted cleanup after a failed inspection"
  else
    pass "inspection-fails (${listing:-empty listing})"
  fi
done

n=$((n + 1))
: > "$STUB_DIR/effects.log"
out="$(STUB_SS_STATUS=0 STUB_SS_OUTPUT='' run_inspection_fixture)"
status=$?
if [ "$status" -eq 0 ] && [ -z "$out" ] && [ "$(<"$STUB_DIR/effects.log")" = 'sleep 1' ]; then
  pass "inspection-empty"
else
  fail "inspection-empty: expected a silent successful no-op without signals, got ${status}"
fi

# Start a trivial listener and return its pid. node is a hard dependency of the
# repo, so this needs nothing that is not already here.
listen_on() {
  # stdout and stderr go to /dev/null, and not for tidiness: this function is
  # called inside `$( )`, and a background child that inherits the captured
  # stdout keeps the pipe open, so the command substitution blocks until the
  # listener exits — which it never does. The suite hangs instead of running.
  node -e "require('node:http').createServer((_q,s)=>s.end('ok')).listen($1,'127.0.0.1')" > /dev/null 2>&1 &
  local pid=$!
  PIDS="$PIDS $pid"
  # Wait for the bind rather than sleeping a fixed amount: a fixture that is not
  # actually listening makes the case below vacuous rather than failing.
  for _ in $(seq 1 50); do
    if ss -lntH "sport = :$1" 2>/dev/null | grep -q .; then echo "$pid"; return 0; fi
    sleep 0.1
  done
  echo "" ; return 1
}

port_is_held() { ss -lntH "sport = :$1" 2>/dev/null | grep -q .; }

# The live cases need `ss` to observe a port, exactly as the script under test
# does. The runners have iproute2 and so does every CI job, so this branch is
# not the one that runs where it matters — but a developer sandbox without it
# would otherwise see six red cases that say nothing about the code. Skipping is
# announced and the workflow assertions below still run: a suite that quietly
# tested nothing is the failure mode this whole card keeps running into.
if command -v ss > /dev/null 2>&1; then
  HAVE_SS=1
else
  HAVE_SS=0
  printf '\n\033[33mSKIP: `ss` (iproute2) is not installed here, so the six live port cases cannot run.\n'
  printf '      They run in CI, where iproute2 is present. The workflow assertions below still run.\033[0m\n'
fi

if [ "$HAVE_SS" = 1 ]; then

printf '\n\033[1m==> A leaked listener is reclaimed\033[0m\n'

n=$((n + 1))
pid_a="$(listen_on "$PORT_A")"
pid_b="$(listen_on "$PORT_B")"
if [ -z "$pid_a" ] || [ -z "$pid_b" ]; then
  fail "frees-a-held-port: the fixture never bound — the case would be vacuous"
else
  out="$(./ci/reclaim-ports.sh "$PORT_A" 2>&1)"
  sleep 0.5
  if port_is_held "$PORT_A"; then
    fail "frees-a-held-port: ${PORT_A} is still held after reclaim"
    printf '%s\n' "$out" | sed 's/^/        /'
  elif ! grep -qF -- "leaked by an earlier run, killing it" <<< "$out"; then
    fail "frees-a-held-port: the port was freed but the script did not say why"
    printf '%s\n' "$out" | sed 's/^/        /'
  else
    pass "frees-a-held-port"
  fi

  # The blast radius. A script that kills by port must kill only that port, or
  # a parallel job on the same host loses its server to a neighbour's cleanup —
  # which would be a worse bug than the leak.
  n=$((n + 1))
  if port_is_held "$PORT_B"; then
    pass "leaves-others-alone"
  else
    fail "leaves-others-alone: reclaiming ${PORT_A} also killed the listener on ${PORT_B}"
  fi
fi

printf '\n\033[1m==> An unheld port is a no-op\033[0m\n'

n=$((n + 1))
out="$(./ci/reclaim-ports.sh "$PORT_A" 2>&1)"
status=$?
if [ "$status" -ne 0 ]; then
  fail "quiet-when-free: expected exit 0 on an unheld port, got ${status}"
  printf '%s\n' "$out" | sed 's/^/        /'
elif grep -q "killing it" <<< "$out"; then
  fail "quiet-when-free: claimed to kill something on a free port"
  printf '%s\n' "$out" | sed 's/^/        /'
else
  pass "quiet-when-free"
fi

printf '\n\033[1m==> The whole block is reclaimed at once\033[0m\n'

n=$((n + 1))
pid_a="$(listen_on "$PORT_A")"
pid_b="$(listen_on "$PORT_B")"
if [ -z "$pid_a" ] || [ -z "$pid_b" ]; then
  fail "multiple: the fixture never bound — the case would be vacuous"
else
  ./ci/reclaim-ports.sh "$PORT_A" "$PORT_B" > /dev/null 2>&1
  sleep 0.5
  if port_is_held "$PORT_A" || port_is_held "$PORT_B"; then
    fail "multiple: one of ${PORT_A}/${PORT_B} survived a two-port reclaim"
  else
    pass "multiple"
  fi
fi

fi  # HAVE_SS — the live port cases end here

# Usage errors are checked before the script looks at the network, so these run
# everywhere and are not part of the skip above.
printf '\n\033[1m==> Bad usage is refused\033[0m\n'

n=$((n + 1))
out="$(./ci/reclaim-ports.sh 2>&1)"; status=$?
if [ "$status" -eq 2 ] && grep -qF "usage:" <<< "$out"; then pass "no-args"; else
  fail "no-args: expected exit 2 and a usage line, got ${status}"
fi

n=$((n + 1))
out="$(./ci/reclaim-ports.sh not-a-port 2>&1)"; status=$?
if [ "$status" -eq 2 ] && grep -qF "is not a port number" <<< "$out"; then pass "bad-port"; else
  fail "bad-port: expected exit 2 and a reason, got ${status}"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

printf '\n\033[1m==> The budgets job still cleans up after itself\033[0m\n'

# Enumerated from the workflow, because the script being correct is worth
# nothing if the job stops calling it. Both halves matter: reclaim covers the
# run after a crash, teardown covers the ordinary case.
budgets="$(awk '/^  budgets:/{j=1;next} j && /^  [a-zA-Z0-9_-]+:[[:space:]]*$/{exit} j{print}' .github/workflows/ci.yml)"

n=$((n + 1))
if [ -z "$budgets" ]; then
  fail "budgets-reclaims: could not find the budgets job — this assertion is vacuous"
elif grep -qE '^[[:space:]]*\./ci/reclaim-ports\.sh ' <<< "$budgets"; then
  pass "budgets-reclaims"
else
  fail "budgets-reclaims: the budgets job does not run ci/reclaim-ports.sh, so a leaked server from an earlier run on the same persistent runner will be measured instead of a fresh one"
fi

n=$((n + 1))
# The teardown step and its `if: always()` — a teardown that is skipped on a red
# build is a teardown that leaks on exactly the runs that most need it.
teardown="$(awk '/^      - name: Stop the application server/{s=1} s{print} s && /storage\/logs\/proxy.pid/{exit}' <<< "$budgets")"
if [ -z "$teardown" ]; then
  fail "budgets-tears-down: the budgets job has no 'Stop the application server' step"
elif ! grep -qE '^[[:space:]]*if:[[:space:]]*always\(\)' <<< "$teardown"; then
  fail "budgets-tears-down: the teardown step is not guarded by \`if: always()\`, so a failing budget still leaks its server onto the runner"
else
  pass "budgets-tears-down"
fi

printf '\n'
if [ "$rc" -eq 0 ]; then
  printf '\033[32m%s/%s checks passed\033[0m\n' "$n" "$n"
else
  printf '\033[31ma leaked server will break the next run on this runner\033[0m\n'
fi
exit "$rc"
