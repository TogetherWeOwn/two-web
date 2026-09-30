#!/usr/bin/env bash
#
# Tests for the step that mints the moderator session /admin is measured with.
#
# TOG-3220: `budgets` is a required check on `protect-main`, so when it reds every
# two-web merge stops. It red at df1970e (2026-09-17 05:36:03Z) with
#
#   could not mint a session cookie — /admin cannot be measured
#
# and that was the entire diagnostic surface — the annotations carried the
# symptom, the exit code, and nothing else. The cause is still unknown, because
# the step discarded it. The same message had already cost one full misdiagnosis
# at TOG-2847, where it was produced by a leaked server and a stale APP_KEY and
# had nothing to do with sessions at all (see ci/reclaim-ports-selftest.sh).
#
# So the thing worth pinning is not "the step fails" — it already did that
# loudly. It is that the two distinct causes produce two distinguishable
# messages, and that both carry what the command actually said.
#
# Both PATH and CI_PHP_BIN select local stubs, so these are real executions of
# the real script against a controlled artisan, not reasoning about it. Ambient
# CI_PHP_BIN is cleared for every invocation. What is pinned:
#
#   unset-selects-path        inherited CI_PHP_BIN cannot bypass the PATH stub
#   explicit-selects-bin      explicit CI_PHP_BIN wins over the PATH stub
#   selection-exports        each branch exports its own synthetic cookie
#
#   nonzero-names-the-code    artisan exits 42 -> message names 42, not 0 or 1
#   nonzero-shows-stderr      ...and the stack trace Laravel wrote is printed
#   nonzero-shows-stdout      ...and whatever reached stdout is printed too
#   nonzero-exits-1           ...and the step still fails loudly
#   empty-is-a-distinct-case  artisan exits 0 printing nothing -> *different*
#                             message, naming exit 0
#   empty-shows-stderr        ...and still prints what the command said
#   empty-exits-1             ...and still fails loudly
#   the-two-differ            the two messages are not the same string
#   success-exports           a good mint writes CI_SESSION_COOKIE to GITHUB_ENV
#   success-masks             ...and masks the value, not the whole cookie
#   success-no-leak           ...and never echoes the captured streams
#   stderr-cannot-be-cookie   a deprecation on stderr does not become the cookie
#
# And the workflow assertion, which is what keeps the fix wired in:
#
#   budgets-calls-the-script  the budgets job mints via ci/mint-session-cookie.sh
#
# Usage: ./ci/mint-session-cookie-selftest.sh

set -uo pipefail

cd "$(dirname "$0")/.."

rc=0
n=0
fail() { printf '\033[31mFAIL: %s\033[0m\n' "$*" >&2; rc=1; }
pass() { printf '\033[32mPASS: %s\033[0m\n' "$*"; }
indent() { sed 's/^/        /'; }

STUB_DIR="$(mktemp -d)"
ENV_FILE="$(mktemp)"
trap 'rm -rf "$STUB_DIR" "$ENV_FILE"' EXIT

# The PATH stub reads its behaviour at call time for the diagnostic cases.
# Markers go to a separate file so they cannot become cookie output.
cat > "$STUB_DIR/php" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' 'path-php' >> "$STUB_TRACE_FILE"
printf '%s' "${STUB_STDOUT:-}"
printf '%s' "${STUB_STDERR:-}" >&2
exit "${STUB_RC:-0}"
STUB
cat > "$STUB_DIR/php-explicit" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' 'explicit-php' >> "$STUB_TRACE_FILE"
printf '%s\n' 'two_session=synthetic-explicit-cookie'
STUB
chmod +x "$STUB_DIR/php" "$STUB_DIR/php-explicit"

# A cookie-shaped value: long enough to exercise the mask gate, which is what
# stops a short non-cookie line redacting the whole log.
COOKIE_NAME="two_session"
COOKIE_VALUE="eyJpdiI6ImFiY2RlZmdoaWprbG1ub3AiLCJ2YWx1ZSI6Inp6enp6enp6enp6enp6eiJ9"

# Clear ambient executable selection in the child environment; only a test's
# explicit argument may set it. stdout+stderr merged because the *step log* is
# what a reader actually sees.
run_mint() {
  local php_env=()
  if [ -n "${1:-}" ]; then
    php_env=("CI_PHP_BIN=$1")
  fi
  : > "$STUB_DIR/php-trace"
  env -u CI_PHP_BIN "${php_env[@]}" \
    PATH="$STUB_DIR:$PATH" GITHUB_ENV="$ENV_FILE" STUB_TRACE_FILE="$STUB_DIR/php-trace" \
    ./ci/mint-session-cookie.sh 2>&1
}

printf '\n\033[1m==> PHP executable selection stays inside the fixtures\033[0m\n'

: > "$ENV_FILE"
out="$(CI_PHP_BIN="$STUB_DIR/php-explicit" \
       STUB_RC=0 STUB_STDOUT="two_session=synthetic-path-cookie" STUB_STDERR="" \
       run_mint)"
status=$?

n=$((n + 1))
if [ "$status" -eq 0 ] && [ "$(< "$STUB_DIR/php-trace")" = 'path-php' ]; then
  pass "unset-selects-path"
else
  fail "unset-selects-path: inherited CI_PHP_BIN bypassed the PATH stub (exit ${status})"
  indent <<< "$out"
fi

n=$((n + 1))
if [ "$(< "$ENV_FILE")" = 'CI_SESSION_COOKIE=two_session=synthetic-path-cookie' ]; then
  pass "path-selection-exports"
else
  fail "path-selection-exports: expected only the PATH stub's synthetic cookie"
  indent < "$ENV_FILE"
fi

: > "$ENV_FILE"
out="$(CI_PHP_BIN="$STUB_DIR/php" \
       STUB_RC=0 STUB_STDOUT="two_session=synthetic-path-cookie" STUB_STDERR="" \
       run_mint "$STUB_DIR/php-explicit")"
status=$?

n=$((n + 1))
if [ "$status" -eq 0 ] && [ "$(< "$STUB_DIR/php-trace")" = 'explicit-php' ]; then
  pass "explicit-selects-bin"
else
  fail "explicit-selects-bin: CI_PHP_BIN did not win over the PATH stub (exit ${status})"
  indent <<< "$out"
fi

n=$((n + 1))
if [ "$(< "$ENV_FILE")" = 'CI_SESSION_COOKIE=two_session=synthetic-explicit-cookie' ]; then
  pass "explicit-selection-exports"
else
  fail "explicit-selection-exports: expected only the explicit stub's synthetic cookie"
  indent < "$ENV_FILE"
fi

printf '\n\033[1m==> artisan exits non-zero: the code and the output survive\033[0m\n'

: > "$ENV_FILE"
out="$(STUB_RC=42 \
       STUB_STDOUT="" \
       STUB_STDERR="PDOException: SQLSTATE[08006] connection refused
  at /var/www/vendor/laravel/framework/src/Illuminate/Database/Connectors/Connector.php:70" \
       run_mint)"
status=$?

n=$((n + 1))
if grep -qF -- "exited 42" <<< "$out"; then
  pass "nonzero-names-the-code"
else
  fail "nonzero-names-the-code: the message does not name exit 42 — this is the \`if ! out=\$(...)\` trap, where \$? inside the then-branch is the negation's status (0) and every crash reports 'exited 0'"
  indent <<< "$out"
fi

n=$((n + 1))
if grep -qF -- "SQLSTATE[08006] connection refused" <<< "$out"; then
  pass "nonzero-shows-stderr"
else
  fail "nonzero-shows-stderr: Laravel's exception went to stderr and was discarded — that is the whole defect"
  indent <<< "$out"
fi

n=$((n + 1))
if [ "$status" -eq 1 ]; then
  pass "nonzero-exits-1"
else
  fail "nonzero-exits-1: expected exit 1, got ${status} — the failure must stay loud, not just become informative"
fi

NONZERO_OUT="$out"

# stdout is printed too. A command that failed *after* printing something is the
# case where stdout is the interesting half, and it costs one line to keep it.
n=$((n + 1))
: > "$ENV_FILE"
out="$(STUB_RC=7 STUB_STDOUT="halfway through a migration" STUB_STDERR="" run_mint)"
if grep -qF -- "halfway through a migration" <<< "$out" && grep -qF -- "exited 7" <<< "$out"; then
  pass "nonzero-shows-stdout"
else
  fail "nonzero-shows-stdout: stdout from a failing artisan was dropped"
  indent <<< "$out"
fi

printf '\n\033[1m==> artisan exits 0 printing nothing: a different message\033[0m\n'

: > "$ENV_FILE"
out="$(STUB_RC=0 \
       STUB_STDOUT="" \
       STUB_STDERR="ci:session-cookie refuses to run outside local, testing or CI." \
       run_mint)"
status=$?

n=$((n + 1))
if grep -qF -- "exited 0 but printed no cookie" <<< "$out"; then
  pass "empty-is-a-distinct-case"
else
  fail "empty-is-a-distinct-case: a silent success is not reported as its own cause"
  indent <<< "$out"
fi

n=$((n + 1))
if grep -qF -- "refuses to run outside local, testing or CI" <<< "$out"; then
  pass "empty-shows-stderr"
else
  fail "empty-shows-stderr: \$this->error() writes to stderr, and it was dropped — this is the environment-guard case, the single most likely real cause"
  indent <<< "$out"
fi

n=$((n + 1))
if [ "$status" -eq 1 ]; then
  pass "empty-exits-1"
else
  fail "empty-exits-1: expected exit 1, got ${status}"
fi

# The point of the card in one case. If these two ever converge, the step is back
# to naming a symptom that has two causes.
#
# Digits are normalised out before comparing, and that is the whole substance of
# the case rather than tidiness: the exit code is interpolated into one of the
# messages, so a *collapsed template* — both causes rendering the same sentence —
# still compares unequal as "exited 42" vs "exited 0" and would pass. Comparing
# the templates is what the case is named for. Found by mutation: collapsing the
# two messages left this case green and was caught only by a sibling.
n=$((n + 1))
template() { grep -F -- '::error::' <<< "$1" | head -1 | tr -d '0-9'; }
a="$(template "$NONZERO_OUT")"
b="$(template "$out")"
if [ -n "$a" ] && [ -n "$b" ] && [ "$a" != "$b" ]; then
  pass "the-two-differ"
else
  fail "the-two-differ: the two failure causes produce the same annotation template ('${a}' vs '${b}') — indistinguishable is exactly the defect"
fi

printf '\n\033[1m==> A good mint still exports and still masks\033[0m\n'

: > "$ENV_FILE"
out="$(STUB_RC=0 \
       STUB_STDOUT="${COOKIE_NAME}=${COOKIE_VALUE}
" \
       STUB_STDERR="" \
       run_mint)"
status=$?

n=$((n + 1))
if [ "$status" -eq 0 ] && grep -qF -- "CI_SESSION_COOKIE=${COOKIE_NAME}=${COOKIE_VALUE}" "$ENV_FILE"; then
  pass "success-exports"
else
  fail "success-exports: the cookie did not reach GITHUB_ENV (exit ${status}) — ci/pages.cjs throws on an empty one, so this would be a red build, but a *different* red"
  indent < "$ENV_FILE"
fi

n=$((n + 1))
if grep -qF -- "::add-mask::${COOKIE_VALUE}" <<< "$out" \
   && ! grep -qF -- "::add-mask::${COOKIE_NAME}=${COOKIE_VALUE}" <<< "$out"; then
  pass "success-masks"
else
  fail "success-masks: the mask must cover the value only. Masking the whole 'name=value' string leaves the value itself unmasked wherever it appears alone, which is everywhere that matters"
  indent <<< "$out"
fi

# The leak the fix had to not introduce: `echo "\$out"` runs before the mask is
# set, so the failure paths must never be reachable with a cookie in hand.
n=$((n + 1))
if ! grep -qF -- "--- ci:session-cookie stdout ---" <<< "$out" \
   && ! grep -qF -- "--- ci:session-cookie stderr ---" <<< "$out"; then
  pass "success-no-leak"
else
  fail "success-no-leak: the success path echoed the captured streams, which is how a live session reaches a public build log"
  indent <<< "$out"
fi

printf '\n\033[1m==> stderr cannot be mistaken for the cookie\033[0m\n'

# Why the script captures the two streams separately instead of `2>&1`. Merging
# makes the *last* line the cookie, and a deprecation notice arriving after the
# cookie would be exported as one — the app reads that as a guest and /admin is
# silently measured as the Discord handoff. A green build measuring the wrong
# page is worse than a red one.
n=$((n + 1))
: > "$ENV_FILE"
out="$(STUB_RC=0 \
       STUB_STDOUT="${COOKIE_NAME}=${COOKIE_VALUE}
" \
       STUB_STDERR="Deprecated: Return type of X should be compatible with Y
" \
       run_mint)"
if grep -qF -- "CI_SESSION_COOKIE=${COOKIE_NAME}=${COOKIE_VALUE}" "$ENV_FILE"; then
  pass "stderr-cannot-be-cookie"
else
  fail "stderr-cannot-be-cookie: a stderr line became the exported cookie — this is what \`2>&1\` into one capture buys you"
  indent < "$ENV_FILE"
fi

printf '\n\033[1m==> The budgets job still mints through this script\033[0m\n'

# Enumerated from the workflow, because the script being correct is worth
# nothing if the job stops calling it.
budgets="$(awk '/^  budgets:/{j=1;next} j && /^  [a-zA-Z0-9_-]+:[[:space:]]*$/{exit} j{print}' .github/workflows/ci.yml)"

n=$((n + 1))
if [ -z "$budgets" ]; then
  fail "budgets-calls-the-script: could not find the budgets job — this assertion is vacuous"
elif grep -qE '^[[:space:]]*\./ci/mint-session-cookie\.sh[[:space:]]*$' <<< "$budgets"; then
  pass "budgets-calls-the-script"
else
  fail "budgets-calls-the-script: the budgets job does not run ci/mint-session-cookie.sh, so a failed mint is back to naming its symptom and discarding its cause"
fi

# A guard that inlines the old pipeline again would pass every case above — they
# test the script, and an inlined step does not call it. This is the one that
# catches a revert-by-copy-paste.
n=$((n + 1))
if [ -z "$budgets" ]; then
  fail "budgets-does-not-inline: could not find the budgets job — this assertion is vacuous"
elif grep -qE 'php artisan ci:session-cookie' <<< "$budgets"; then
  fail "budgets-does-not-inline: the budgets job calls \`php artisan ci:session-cookie\` inline again. The Actions default shell is \`bash -e\` without pipefail, so a piped artisan's exit status is \`tail\`'s and its stderr is unlabelled — the defect returns whole"
else
  pass "budgets-does-not-inline"
fi

printf '\n'
if [ "$rc" -eq 0 ]; then
  printf '\033[32m%d/%d cases passed\033[0m\n' "$n" "$n"
else
  printf '\033[31mfailures above (%d cases run)\033[0m\n' "$n"
fi
exit "$rc"
