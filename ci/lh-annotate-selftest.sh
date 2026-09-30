#!/usr/bin/env bash
#
# Tests for the script that decides whether a budget breach stops the merge.
#
# ci/lh-annotate.mjs is the `budgets` job's verdict. lhci runs under
# `continue-on-error: true`, so its own exit code is discarded and this script's
# is what turns red — which makes it the whole gate, and it had no test at all.
#
# That gap shipped a real defect. The script failed on `!r.passed` alone, with no
# reference to `level`, so every `warn` budget was silently promoted to a gate.
# ci/lighthouserc.cjs writes two kinds of threshold and means the difference: LCP,
# CLS and TTFB are `['error', ...]` and are the CEO's numbers; TBT and FCP are
# `['warn', ...]` and are documented there as "not a CEO budget, so not a
# failure". /admin went red on FCP 1957ms against an 1800ms tripwire while every
# error-level budget passed with headroom — LCP 2779ms against its 3200ms ceiling
# (TOG-54).
#
# A warning that fails the build is not a warning. The pressure it creates is to
# delete the tripwire or inflate its number, which costs exactly the early signal
# the tripwire exists to give.
#
# Both directions are pinned here, because a script that stops failing looks
# identical from the outside to one that has nothing to fail on:
#
#   warn-only        two warn breaches, no error   -> exit 0  (the TOG-54 case)
#   error-only       one error breach              -> exit 1  (the CEO's budget)
#   error-and-warn   both                          -> exit 1, error named
#   all-passed       empty results                 -> exit 0
#   no-results-file  lhci wrote no assertions      -> exit 0
#   no-directory     lhci never ran at all         -> exit 1  (never measured)
#
# The original fixtures are the real numbers CI reported at cb90bcc. The
# two-report aggregation case uses synthetic values with exact even-count medians.
#
# No network, no Chrome, no lhci. Well under a second. Run it anywhere.
#
# Usage: ./ci/lh-annotate-selftest.sh

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ANNOTATE="${REPO_ROOT}/ci/lh-annotate.mjs"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/lh-annotate-selftest.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

pass() { printf '\033[32mPASS\033[0m  %s\n' "$*"; }
fail() { printf '\033[31mFAIL\033[0m  %s\n' "$*" >&2; }

rc=0
n=0

# case <slug> <expected exit> <expected substring> <assertion-results.json, or the
# literal NO_FILE / NO_DIR>
case_is() {
  local slug="$1" want="$2" expected="$3" body="$4"
  local dir out status
  n=$((n + 1))
  dir="$WORK/$slug"
  mkdir -p "$dir"

  if [ "$body" != "NO_DIR" ]; then
    mkdir -p "$dir/.lighthouseci"
    [ "$body" != "NO_FILE" ] && printf '%s\n' "$body" > "$dir/.lighthouseci/assertion-results.json"
  fi

  out="$( cd "$dir" && node "$ANNOTATE" 2>&1 )"
  status=$?

  if [ "$status" -ne "$want" ]; then
    fail "$slug: expected exit ${want}, got ${status}"
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
    return
  fi
  if ! grep -qF -- "$expected" <<< "$out"; then
    fail "$slug: exit ${status} was right, but the output never says: ${expected}"
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
    return
  fi
  pass "$slug"
}

printf '\n\033[1m==> A warning is a tripwire, not a gate\033[0m\n'

# The exact case that failed the budgets job at cb90bcc: /admin and its resource
# page over the 1800ms FCP tripwire, every error-level budget passing.
case_is warn-only 0 '0 budgets breached' \
'[
 {"auditId":"first-contentful-paint","level":"warn","url":"http://127.0.0.1:8000/admin","actual":1956.9988,"expected":1800,"passed":false},
 {"auditId":"first-contentful-paint","level":"warn","url":"http://127.0.0.1:8000/admin/featured-contents","actual":1947.8505,"expected":1800,"passed":false}
]'

# ...and it still has to be visible. It is annotated as ::error:: on purpose, so a
# principal holding only `checks: read` can see it without the job log, worded so
# nobody mistakes it for the thing that stopped the merge.
case_is warn-still-annotated 0 'TRIPWIRE, NOT A FAILURE' \
'[{"auditId":"total-blocking-time","level":"warn","url":"http://127.0.0.1:8000/","actual":420,"expected":300,"passed":false}]'

printf '\n\033[1m==> An error-level budget still stops the merge\033[0m\n'

# The direction that matters most. If this ever goes green, the CEO's LCP budget
# is decorative and the funnel is unprotected.
case_is error-only 1 'largest-contentful-paint' \
'[{"auditId":"largest-contentful-paint","level":"error","url":"http://127.0.0.1:8000/","actual":2450,"expected":2000,"passed":false}]'

# A warning alongside a breach must not dilute the verdict or hide the breach.
case_is error-and-warn 1 'largest-contentful-paint' \
'[{"auditId":"largest-contentful-paint","level":"error","url":"http://127.0.0.1:8000/","actual":2450,"expected":2000,"passed":false},
  {"auditId":"first-contentful-paint","level":"warn","url":"http://127.0.0.1:8000/admin","actual":1957,"expected":1800,"passed":false}]'

# CLS and TTFB are error-level too, and the split must not be LCP-specific.
case_is error-cls 1 'cumulative-layout-shift' \
'[{"auditId":"cumulative-layout-shift","level":"error","url":"http://127.0.0.1:8000/","actual":0.24,"expected":0.1,"passed":false}]'

case_is error-ttfb 1 'server-response-time' \
'[{"auditId":"server-response-time","level":"error","url":"http://127.0.0.1:8000/","actual":3100,"expected":600,"passed":false}]'

printf '\n\033[1m==> Nothing breached, and nothing measured\033[0m\n'

case_is all-passed 0 'Every performance assertion passed' '[]'

# lhci writes no assertion-results.json when nothing asserted. Not a failure.
case_is no-results-file 0 'nothing to annotate' NO_FILE

# But no .lighthouseci at all means Lighthouse never produced a result — a
# crashed Chrome, a server that went away. That must be red: a budget that was
# never measured is not a budget that passed.
case_is no-directory 1 'never produced results' NO_DIR

printf '\n\033[1m==> A breach reports the samples behind it (TOG-3224)\033[0m\n'

# A bare median cannot be acted on: "LCP 2114 exceeds 2000" is equally consistent
# with a byte regression and with a contended runner, and those want opposite
# responses. lhci already records every sample as `values`; this prints them.
#
# 1980/2114/2200 -> mean 2098, half-range 110, so ±5.2%.
case_is error-shows-samples 1 '1980, 2114, 2200 — spread ±5.2%' \
'[{"auditId":"largest-contentful-paint","level":"error","url":"http://127.0.0.1:8000/events","actual":2114,"expected":2000,"passed":false,"values":[1980,2114,2200]}]'

# The tripwire wording gets the same treatment, and must still not fail the build.
case_is warn-shows-samples 0 'spread ±' \
'[{"auditId":"first-contentful-paint","level":"warn","url":"http://127.0.0.1:8000/admin","actual":1957,"expected":1800,"passed":false,"values":[1890,1957,2010]}]'

# Backwards compatibility, and it is not cosmetic: an lhci that stopped emitting
# `values` must degrade to the old wording, not crash the gate into a green.
case_is samples-absent 1 'median of 3 runs' \
'[{"auditId":"largest-contentful-paint","level":"error","url":"http://127.0.0.1:8000/","actual":2450,"expected":2000,"passed":false}]'

case_is samples-single 1 'median of 3 runs' \
'[{"auditId":"largest-contentful-paint","level":"error","url":"http://127.0.0.1:8000/","actual":2450,"expected":2000,"passed":false,"values":[2450]}]'

# CLS is a fraction, not milliseconds. Rounding the samples to integers would
# print "0, 0, 0" and lose the entire number the budget is written in.
case_is samples-cls-precision 1 '0.21, 0.24, 0.29' \
'[{"auditId":"cumulative-layout-shift","level":"error","url":"http://127.0.0.1:8000/","actual":0.24,"expected":0.1,"passed":false,"values":[0.21,0.24,0.29]}]'

# A non-numeric sample must not produce "NaN" in an annotation someone has to act on.
case_is samples-non-numeric 1 'median of 3 runs' \
'[{"auditId":"largest-contentful-paint","level":"error","url":"http://127.0.0.1:8000/","actual":2450,"expected":2000,"passed":false,"values":[null,"x"]}]'

printf '\n\033[1m==> The host speed that produced those samples (TOG-3224)\033[0m\n'

# case_with_lhr <slug> <expected exit> <expected substrings, one per line> <assertion json> <lhr json>...
# Same contract as case_is, plus saved lhr-*.json reports so the environment block
# is readable. benchmarkIndex is per-host, so it is reported once per job.
case_with_lhr() {
  local slug="$1" want="$2" expected="$3" body="$4"
  local dir out status lhr expected_line report=0
  shift 4
  n=$((n + 1))
  dir="$WORK/$slug"
  mkdir -p "$dir/.lighthouseci"
  printf '%s\n' "$body" > "$dir/.lighthouseci/assertion-results.json"
  for lhr in "$@"; do
    printf '%s\n' "$lhr" > "$dir/.lighthouseci/lhr-$((1700000000000 + report)).json"
    report=$((report + 1))
  done

  out="$( cd "$dir" && node "$ANNOTATE" 2>&1 )"
  status=$?

  if [ "$status" -ne "$want" ]; then
    fail "$slug: expected exit ${want}, got ${status}"
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
    return
  fi
  while IFS= read -r expected_line; do
    if ! grep -qF -- "$expected_line" <<< "$out"; then
      fail "$slug: exit ${status} was right, but the output never says: ${expected_line}"
      printf '%s\n' "$out" | sed 's/^/        /'
      rc=1
      return
    fi
  done <<< "$expected"
  if [ "$(grep -cF -- '::error::CONTEXT, NOT A FAILURE — host speed while measuring:' <<< "$out")" -gt 1 ]; then
    fail "$slug: host speed must be annotated only once per job"
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
    return
  fi
  pass "$slug"
}

LHR_SLOW='{"finalDisplayedUrl":"http://127.0.0.1:8000/events","environment":{"benchmarkIndex":903.5},"audits":{"largest-contentful-paint":{"numericValue":2114}}}'
LHR_NO_ENV='{"finalDisplayedUrl":"http://127.0.0.1:8000/events","audits":{"largest-contentful-paint":{"numericValue":2114}}}'

case_with_lhr benchmark-reported 1 'benchmarkIndex 904' \
'[{"auditId":"largest-contentful-paint","level":"error","url":"http://127.0.0.1:8000/events","actual":2114,"expected":2000,"passed":false,"values":[1980,2114,2200]}]' \
"$LHR_SLOW"

# It is context, never the verdict. A green job still reports the host speed, and
# reporting it must not turn a pass into a failure.
case_with_lhr benchmark-on-a-green-job 0 'benchmarkIndex 904' '[]' "$LHR_SLOW"

# An lhr with no environment block must simply omit the line rather than print
# "benchmarkIndex NaN" or throw and take the gate down with it.
case_with_lhr benchmark-absent 1 'largest-contentful-paint' \
'[{"auditId":"largest-contentful-paint","level":"error","url":"http://127.0.0.1:8000/events","actual":2114,"expected":2000,"passed":false}]' \
"$LHR_NO_ENV"

# Two reports for one URL exercise even-count medians, not an assertion's cached
# `actual`. Both reports share the host, so its median and range belong on one line.
LHR_LOW='{"finalDisplayedUrl":"http://127.0.0.1:8000/events","environment":{"benchmarkIndex":1000},"audits":{"largest-contentful-paint":{"numericValue":100}}}'
LHR_HIGH='{"finalDisplayedUrl":"http://127.0.0.1:8000/events","environment":{"benchmarkIndex":1400},"audits":{"largest-contentful-paint":{"numericValue":300}}}'

case_with_lhr two-reports-one-url 1 \
'::error::CONTEXT, NOT A FAILURE — measured on http://127.0.0.1:8000/events (median of 2 runs): largest-contentful-paint 200
::error::CONTEXT, NOT A FAILURE — host speed while measuring: benchmarkIndex 1200 (median of 2 runs, range 1000–1400).' \
'[{"auditId":"largest-contentful-paint","level":"error","url":"http://127.0.0.1:8000/events","actual":200,"expected":150,"passed":false,"values":[100,300]}]' \
"$LHR_LOW" "$LHR_HIGH"

printf '\n'
if [ "$rc" -ne 0 ]; then
  fail "the budgets verdict is not what it claims to be. Fix it before trusting the gate."
else
  printf '\033[1m%d/%d — a warning warns and a breach blocks.\033[0m\n' "$n" "$n"
fi
exit "$rc"
