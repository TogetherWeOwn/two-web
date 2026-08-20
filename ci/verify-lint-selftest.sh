#!/usr/bin/env bash
#
# Tests for the tester.
#
# `verify-pipeline.sh --lint` is the thing that notices when the merge gate has
# quietly stopped gating. It runs first in the `static` job, so if it silently
# stops noticing, nothing downstream is watching it — a checker that always passes
# and a checker that works look identical from the outside.
#
# So: mutate a throwaway copy of the workflow one defect at a time, and assert the
# lint goes red *for the right reason*. Every case here is a bug that has actually
# been committed to this repo or proposed for it, not a hypothetical.
#
# The last section covers `--run`'s assertions rather than `--lint`'s, for the same
# reason one level along: those only ever execute during a live run, so a wrong one
# survives until somebody spends forty minutes finding it. TWO-94 is that, once.
#
# No network, no gh, no PHP. Half a second. Run it anywhere.
#
# Usage: ./ci/verify-lint-selftest.sh

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/lint-selftest.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

pass() { printf '\033[32mPASS\033[0m  %s\n' "$*"; }
fail() { printf '\033[31mFAIL\033[0m  %s\n' "$*" >&2; }

rc=0
n=0

# A pristine copy of everything the lint reads, per case.
fixture() {
  local dir="$WORK/$1"
  rm -rf "$dir"
  mkdir -p "$dir/.github/workflows" "$dir/docs" "$dir/ci"
  cp "$REPO_ROOT"/.github/workflows/*.yml "$dir/.github/workflows/"
  cp "$REPO_ROOT/docs/ci.md" "$dir/docs/"
  cp "$REPO_ROOT/ci/verify-pipeline.sh" "$dir/ci/"
  cp "$REPO_ROOT/ci/lighthouserc.cjs" "$dir/ci/"
  echo "$dir"
}

# expect_fail <slug> <expected substring of the failure> <mutation...>
# The mutation runs with the fixture as cwd.
expect_fail() {
  local slug="$1" expected="$2"; shift 2
  local dir out status
  n=$((n + 1))
  dir="$(fixture "$slug")"
  ( cd "$dir" && "$@" ) || { fail "$slug: the mutation itself failed to apply"; rc=1; return; }
  out="$( cd "$dir" && ./ci/verify-pipeline.sh --lint 2>&1 )"
  status=$?

  if [ "$status" -eq 0 ]; then
    fail "$slug: lint passed. It should have caught this."
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
    return
  fi
  if ! grep -qF -- "$expected" <<< "$out"; then
    fail "$slug: lint failed, but not for the stated reason. Wanted: ${expected}"
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
    return
  fi
  pass "$slug"
}

# expect_warn <slug> <expected substring> <mutation...>
# Still exit 0 — a warning is not a gate, it is a tripwire.
expect_warn() {
  local slug="$1" expected="$2"; shift 2
  local dir out status
  n=$((n + 1))
  dir="$(fixture "$slug")"
  ( cd "$dir" && "$@" ) || { fail "$slug: the mutation itself failed to apply"; rc=1; return; }
  out="$( cd "$dir" && ./ci/verify-pipeline.sh --lint 2>&1 )"
  status=$?

  if [ "$status" -ne 0 ]; then
    fail "$slug: expected a warning and a green exit, got a failure"
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
    return
  fi
  if ! grep -qF -- "$expected" <<< "$out"; then
    fail "$slug: expected a warning containing: ${expected}"
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
    return
  fi
  pass "$slug"
}

printf '\n\033[1m==> The gate stops gating\033[0m\n'

# The aggregate loses `if: always()`. Every job still passes; a red `static` now
# *skips* `tests`, and GitHub counts a skipped required check as a passed one.
expect_fail no-always 'has no `if: always()`' \
  sed -i '/^    if: always()$/d' .github/workflows/ci.yml

# The guard drops `skipped`. A job disabled by its own `if:` — a path filter, a job
# turned off "temporarily" — then sails straight through the gate.
expect_fail guard-misses-skipped "never mentions \`skipped\`" \
  sed -i "s/'skipped'//g" .github/workflows/ci.yml

# A job is added and nobody wires it into the aggregate. It goes red, the required
# check stays green.
expect_fail job-not-behind-aggregate "are not in \`tests\`'s \`needs:\`" \
  bash -c "printf '  orphan:\n    name: orphan\n    runs-on: ubuntu-24.04\n    steps:\n      - run: true\n' >> .github/workflows/ci.yml"

# The `static` job stops running `--lint`. Every job still passes and every other
# check above still passes, because they all read `ci.yml` rather than asking who
# reads it. Nothing then catches a disarmed aggregate on the pull request that
# disarms it (TWO-94).
expect_fail no-lint-step 'runs `verify-pipeline.sh --lint`' \
  sed -i '/verify-pipeline.sh --lint/d' .github/workflows/ci.yml

# `static` is dropped from the required checks. This is the specific hazard TWO-94
# turned on: deleting `if: always()` *skips* `tests`, GitHub counts a skipped
# required check as passed, and `static` is then the only required check that goes
# red. Require the aggregate alone — the shape a smaller protection rule naturally
# takes — and a pull request that disarms the gate merges clean.
expect_fail lint-job-not-required 'are not required checks' \
  bash -c 'sed -i "s/^REQUIRED_CHECKS=(.*)$/REQUIRED_CHECKS=(tests pest dusk budgets gitleaks)/" ci/verify-pipeline.sh'

printf '\n\033[1m==> The required check never arrives (main unmergeable, forever)\033[0m\n'

# Requiring the workflow *name*. `CI` is the workflow; protection matches the
# check-run name. This one was actually applied to `main` on TWO-36.
expect_fail phantom-ci 'required check `ci` is not reported' \
  sed -i 's/^REQUIRED_CHECKS=(.*)$/REQUIRED_CHECKS=(ci tests static pest dusk budgets gitleaks)/' ci/verify-pipeline.sh

# Requiring a job from a workflow that never runs on a pull request. `deploy.yml`
# is `workflow_run`-triggered, so `staging` looks like a real job id and reports
# nothing on a PR.
expect_fail phantom-deploy-job 'required check `staging` is not reported' \
  bash -c 'sed -i "s/^REQUIRED_CHECKS=(.*)$/REQUIRED_CHECKS=(tests staging)/" ci/verify-pipeline.sh'

# A job renames itself. The id still exists, so an id-matching lint says fine — but
# the check now reports under the new name and the required context never arrives.
expect_fail job-renamed 'required check `budgets` is not reported' \
  sed -i 's/^    name: budgets$/    name: Performance budgets/' .github/workflows/ci.yml

printf '\n\033[1m==> Docs and protection drift apart\033[0m\n'

# docs/ci.md is where the next person reads the list off before applying it. A
# stale doc is how the wrong context gets required in the first place.
expect_fail docs-stale 'never names the required check `dusk`' \
  bash -c "sed -i 's/\`dusk\`//g' docs/ci.md"

printf '\n\033[1m==> The pipeline stops covering something without going red\033[0m\n'

# The PHP suite stubs Vite (tests/TestCase.php) so Pest needs no build. That rests
# entirely on `dusk` and `budgets` still building for real. Drop the build from one
# of them and nothing anywhere exercises a real manifest — and every job stays
# green while it happens, which is precisely why the lint has to say it.
# The mutation deletes the build from `dusk` and leaves `budgets` alone, so this
# proves the check reads the job it names rather than grepping the whole file.
#
# It addresses the `dusk:` block by name rather than deleting the file's first
# `npm run build`. Bounding by position was a false green waiting to happen: it
# quietly assumes no job above `dusk` ever builds, and the moment one does — a
# `pest` job that builds assets was proposed and nearly merged — the mutation
# lands on that job instead, `dusk` keeps its build, check 8 correctly passes,
# and this case stops testing anything while still printing PASS. A self-test
# that can silently stop self-testing is the exact failure mode check 8 exists
# to prevent, so it should not be how the self-test is written.
expect_fail dusk-stops-building 'job `dusk` no longer runs `npm run build`' \
  bash -c "awk '
    /^  dusk:[[:space:]]*\$/            { inside = 1; print; next }
    inside && /^  [a-zA-Z0-9_-]+:[[:space:]]*\$/ { inside = 0 }
    inside && /npm run build/           { next }
                                        { print }
  ' .github/workflows/ci.yml > ci.yml.mutated && mv ci.yml.mutated .github/workflows/ci.yml"

# A threshold nudged upwards until the build goes green. ci/lighthouserc.cjs asks
# people in prose not to do this and, until TWO-93, nothing checked. The number is
# the CEO's; moving it is a decision made in writing, not a line in a feature PR.
expect_fail budget-relaxed 'budget `largest-contentful-paint`' \
  sed -i 's/maxNumericValue: 2000/maxNumericValue: 4000/' ci/lighthouserc.cjs

# The TTFB gate deleted. Nothing else in the pipeline notices a slow server: under
# `throttlingMethod: 'simulate'` a three-second document response is medianed away
# before LCP is ever computed (TWO-93). Remove this line and the LCP budget goes on
# reporting green over a homepage that takes three seconds to answer.
expect_fail ttfb-gate-removed 'budget `server-response-time`' \
  sed -i "/'server-response-time':/d" ci/lighthouserc.cjs

# The same gate downgraded to a warning, which reads like keeping it and is not.
expect_fail ttfb-downgraded 'budget `server-response-time`' \
  sed -i "s/'server-response-time': \['error'/'server-response-time': ['warn'/" ci/lighthouserc.cjs

printf '\n\033[1m==> Tripwires (warn, do not block)\033[0m\n'

# The price of requiring leaves instead of the aggregate alone: drop one and it can
# go red while the PR merges. `tests` still catches it, so this warns rather than
# failing — but it must not be silent.
expect_warn unrequired-job 'job `budgets` reports on pull requests but is not a required check' \
  bash -c 'sed -i "s/^REQUIRED_CHECKS=(.*)$/REQUIRED_CHECKS=(tests static pest dusk gitleaks)/" ci/verify-pipeline.sh'

printf '\n\033[1m==> The lint cannot fail for reasons that are not about the workflow\033[0m\n'

# `producer | grep -q pattern` is banned in verify-pipeline.sh, and this is the
# only way to keep it banned — the defect is invisible in review and reproduces
# on maybe one run in three.
#
# `grep -q` exits the instant it matches. If the producer still has output to
# write it takes SIGPIPE and dies with 141, `set -o pipefail` hands that up as
# the pipeline's status, and `if` suppresses errexit but not pipefail — so a
# check that *found what it was looking for* takes the else branch and reports
# red. Whether it happens depends on how far into the producer's output the
# match lands and how fast the machine is.
#
# It has already cost this repo a day: `job_block budgets | grep -q 'npm run
# build'` matched 65 lines from the end of the block, passed every time on a
# laptop, and went red on the runner (TWO-87). Every one of these greps is
# looking for a reason to fail the merge gate, so every one of them can invent
# one. Use a here-string, or `has_line`.
n=$((n + 1))
# Comment lines are skipped — the ban is documented in the file it applies to,
# in prose that necessarily quotes the thing being banned. Indented as well as
# column-zero `#`, because the explanation sits inside the function it warns
# about.
offenders=$(grep -nE '\|[[:space:]]*grep[[:space:]]+-[a-zA-Z]*[qm]' "$REPO_ROOT/ci/verify-pipeline.sh" \
  | grep -vE '^[0-9]+:[[:space:]]*#' || true)
if [ -z "$offenders" ]; then
  pass "no-pipe-into-early-exit-grep"
else
  fail "no-pipe-into-early-exit-grep: verify-pipeline.sh pipes into a grep that exits early. Under \`set -o pipefail\` the producer takes SIGPIPE and a successful match reports as a failure. Use a here-string or \`has_line\`."
  printf '%s\n' "$offenders" | sed 's/^/        /'
  rc=1
fi

printf '\n\033[1m==> The unmutated repo still passes\033[0m\n'
n=$((n + 1))
if ( cd "$(fixture clean)" && ./ci/verify-pipeline.sh --lint >/dev/null 2>&1 ); then
  pass "clean: the real workflow lints green"
else
  fail "clean: the real workflow does not lint green"
  ( cd "$WORK/clean" && ./ci/verify-pipeline.sh --lint 2>&1 | sed 's/^/        /' )
  rc=1
fi

printf '\n\033[1m==> The live-run assertions themselves\033[0m\n'

# `--lint` reads the workflow. `--run` reads the check conclusions a pull request
# actually produced, and its assertions are only ever exercised by the slowest,
# rarest thing we own — so a wrong one survives. One did: TWO-94 cost eight pull
# requests and forty minutes of runner time to discover that a line of bash
# expected something the design makes unreachable. Those assertions are now
# testable offline against synthetic conclusions, and this is where that runs.
#
# The same run also covers cleanup, which had the identical defect in a blunter
# form: it reported nine pull requests closed while closing none, and the
# acceptance run left three open behind a green summary.
n=$((n + 1))
if ( cd "$(fixture assertions)" && ./ci/verify-pipeline.sh --assert-selftest ) > "$WORK/assert.out" 2>&1; then
  pass "assertions: --run accepts and rejects the right conclusions, and cleanup reports what it did"
else
  fail "assertions: --run's assertions or its cleanup do not say what they claim"
  sed 's/^/        /' "$WORK/assert.out"
  rc=1
fi

printf '\n'
if [ "$rc" -ne 0 ]; then
  fail "the lint does not catch everything it claims to. Fix it before trusting the gate."
else
  printf '\033[1m%d/%d — the lint catches every defect it claims to.\033[0m\n' "$n" "$n"
fi
exit "$rc"
