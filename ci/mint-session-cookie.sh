#!/usr/bin/env bash
#
# Mint the moderator session the budgets job measures /admin with, and report
# *why* it failed when it fails.
#
# This is a script and not four lines of `run:` for the reason deploy.yml gives
# for ci/deploy-target.sh: logic that decides whether a required check goes red
# should be executable and testable, not merely readable. See the companion
# ci/mint-session-cookie-selftest.sh, which drives every branch below against a
# stubbed `php`.
#
# The defect this replaces (TOG-3220) was:
#
#   COOKIE="$(php artisan ci:session-cookie --moderator | tail -1)"
#   if [ -z "$COOKIE" ]; then echo "::error::could not mint a session cookie"; fi
#
# which threw away all three things a reader needs:
#
#   1. stderr — Laravel renders exceptions and `$this->error()` there, so the
#      stack trace, the refused-environment message and the DB connection error
#      all went to the raw job log unlabelled, where an annotation reader (which
#      is all a `checks=read` token can see) never finds them.
#   2. the exit status — `$?` after a pipeline is `tail`'s, and the Actions
#      default shell is `bash -e` *without* pipefail, so a crashing artisan that
#      still printed a line would have sailed through the guard.
#   3. which of the two it was — "empty cookie" has at least two distinct causes
#      and the single message could not tell them apart.
#
# That message is a known-misleading pointer: it is the same one the budgets job
# printed on the first post-merge run after the self-hosted migration, where the
# actual cause was a leaked `artisan serve` holding the port and a stale APP_KEY
# (TOG-2847, see ci/reclaim-ports-selftest.sh). It cost a full misdiagnosis then
# because it names the symptom. This script names the cause.
#
# Usage: ./ci/mint-session-cookie.sh
#
# Writes CI_SESSION_COOKIE to $GITHUB_ENV on success. Exits 1 with the captured
# output on either failure.

set -uo pipefail

# Deliberately not `set -e`. Every command whose failure matters is checked by
# hand below, and `-e` would abort at the capture itself — before a single line
# of diagnostic could be printed, which is precisely the defect being fixed.

cd "$(dirname "$0")/.." || exit 1

stderr_file="$(mktemp)"
trap 'rm -f "$stderr_file"' EXIT

# stdout and stderr are captured *separately*, not merged with `2>&1`. Merging
# looks tidier and quietly reintroduces a silent green: the cookie is the last
# line of stdout, so any stray stderr line — a PHP deprecation, a driver notice —
# would become `tail -1` and be exported as a cookie the app reads as a guest.
# /admin would then be measured as the Discord handoff, which is the exact
# outcome this step exists to prevent.
#
# `if/then/else` and never `if ! out=...; then rc=$?`: inside the `then` branch
# of a *negated* condition, `$?` is the negation's status, which is 0. That form
# reports "exited 0" for a crash and collides with the empty-output message
# below — it reproduces the bug this script fixes. Measured, both forms, in
# ci/mint-session-cookie-selftest.sh.
if stdout="$(php artisan ci:session-cookie --moderator 2>"$stderr_file")"; then
  rc=0
else
  rc=$?
fi

cookie="$(printf '%s\n' "$stdout" | tail -1)"

# Mask before any branch below can echo what was captured. A command that
# printed the cookie and *then* exited non-zero would otherwise leak a live
# session into a public build log. Length-gated so that a short non-cookie last
# line (`0`, say) cannot redact every occurrence of that string in the whole
# log; the success path masks unconditionally further down, exactly as the step
# it replaces did.
case "$cookie" in
  *=*)
    candidate="${cookie#*=}"
    if [ "${#candidate}" -ge 40 ]; then
      echo "::add-mask::${candidate}"
    fi
    ;;
esac

# Failure one: artisan itself failed. The status is real — it is the status of
# `php`, not of a downstream `tail`.
if [ "$rc" -ne 0 ]; then
  echo "::error::ci:session-cookie exited ${rc} — /admin cannot be measured"
  echo "--- ci:session-cookie stdout ---"
  printf '%s\n' "$stdout"
  echo "--- ci:session-cookie stderr ---"
  cat "$stderr_file"
  exit 1
fi

# Failure two: artisan succeeded and produced nothing. A distinct cause needing
# a distinct message — this is the one that used to be indistinguishable from
# the case above.
if [ -z "$cookie" ]; then
  echo "::error::ci:session-cookie exited 0 but printed no cookie — /admin cannot be measured"
  echo "--- ci:session-cookie stderr ---"
  cat "$stderr_file"
  exit 1
fi

# Masked so the session value never appears in a public build log. Unconditional
# here, unlike the defensive gate above: on this path the value *is* the cookie
# whatever its length.
echo "::add-mask::${cookie#*=}"

if [ -n "${GITHUB_ENV:-}" ]; then
  echo "CI_SESSION_COOKIE=$cookie" >> "$GITHUB_ENV"
fi

echo "minted a moderator session cookie for the authenticated pages"
