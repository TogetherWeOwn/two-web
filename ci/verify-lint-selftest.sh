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

printf '\n\033[1m==> The verdict, against job output recorded from a real run\033[0m\n'

# `check_branch` turns job results into the pass/fail verdict of the whole
# acceptance run. Everything above tests the lint, which is the cheap half; this
# tests the half that costs forty minutes and nine pull requests to exercise for
# real, and whose wrong answers are the expensive ones.
#
# The fixtures are the actual job output of the first live run on 2026-08-20
# (TWO-87), copied from the Actions API, not invented.
#
# `check_branch` is extracted rather than sourced because verify-pipeline.sh runs
# its driver at the top level; sourcing it would try to open pull requests.
CHECK_BRANCH_SRC="$(sed -n '/^check_branch() {/,/^}$/p' "$REPO_ROOT/ci/verify-pipeline.sh")"

# Run check_branch against canned results. Echoes nothing; returns its verdict.
verdict() {
  local expected_job="$1" aggregate="$2" checks="$3"
  local required="${4:-tests static pest dusk budgets gitleaks}"
  (
    set -euo pipefail
    # shellcheck disable=SC2206
    REQUIRED_CHECKS=($required)
    EXPECTED_CHECKS=(static pest dusk budgets tests gitleaks)
    FIXTURE="$checks"
    fail() { printf 'FAIL: %s\n' "$*" >&2; }
    pass() { printf 'PASS: %s\n' "$*"; }
    has_line() { grep -q "$2" <<< "$1"; }
    wait_for_checks() { return 0; }
    branch_checks() { printf '%s\n' "$FIXTURE"; }
    eval "$CHECK_BRANCH_SRC"
    check_branch "a-branch" "$expected_job" "no" "why" "$aggregate"
  ) >/dev/null 2>&1
}

# expect_verdict <slug> <pass|reject> <expected job> <aggregate> <checks> [required]
expect_verdict() {
  local slug="$1" want="$2"; shift 2
  local got
  n=$((n + 1))
  if verdict "$@"; then got=pass; else got=reject; fi
  if [ "$got" = "$want" ]; then pass "$slug"; else
    fail "$slug: check_branch returned '${got}', wanted '${want}'"
    rc=1
  fi
}

GATE_CHECKS='static=FAILURE
pest=SUCCESS
dusk=SUCCESS
budgets=SUCCESS
tests=SKIPPED
gitleaks=SUCCESS'

NORMAL_CHECKS='static=FAILURE
pest=SUCCESS
dusk=SUCCESS
budgets=SUCCESS
tests=FAILURE
gitleaks=SUCCESS'

# The ordinary shape: a leaf goes red and drags the aggregate red with it.
expect_verdict ordinary-breakage pass static FAILURE "$NORMAL_CHECKS"

# The `gate` case. Its mutation deletes `if: always()`, which is *what makes the
# aggregate skip* — so the old blanket `tests=FAILURE` assertion could never be
# satisfied here. On the first live run this printed "the gate would let this
# merge" under a `static` job that had failed exactly as intended: the script
# reporting the merge gate broken while it was working.
expect_verdict gate-aggregate-skipped pass static SKIPPED "$GATE_CHECKS"

# The same fixture under the old expectation, pinned so nobody restores it.
expect_verdict gate-under-old-assertion reject static FAILURE "$GATE_CHECKS"

# A skipped aggregate counts as *passed* on GitHub, so the only thing blocking
# that PR is the named job being required in its own right. Trim it out of
# REQUIRED_CHECKS and the gate genuinely has stopped holding — this must reject.
expect_verdict gate-named-job-not-required reject static SKIPPED "$GATE_CHECKS" \
  "tests pest dusk budgets gitleaks"

# The failure this script exists to catch: the named job is green, so whatever
# else went wrong, the gate does not catch this breakage.
expect_verdict named-job-green reject static FAILURE "$(sed 's/^static=FAILURE$/static=SUCCESS/' <<< "$NORMAL_CHECKS")"

# A check that never reported is not a pass — an absent required context blocks
# a PR forever, and reading silence as green is this script's original sin.
expect_verdict check-never-reported reject static FAILURE "$(grep -v '^dusk=' <<< "$NORMAL_CHECKS")"

printf '\n\033[1m==> The unmutated repo still passes\033[0m\n'
n=$((n + 1))
if ( cd "$(fixture clean)" && ./ci/verify-pipeline.sh --lint >/dev/null 2>&1 ); then
  pass "clean: the real workflow lints green"
else
  fail "clean: the real workflow does not lint green"
  ( cd "$WORK/clean" && ./ci/verify-pipeline.sh --lint 2>&1 | sed 's/^/        /' )
  rc=1
fi

printf '\n'
if [ "$rc" -ne 0 ]; then
  fail "the lint does not catch everything it claims to. Fix it before trusting the gate."
else
  printf '\033[1m%d/%d — the lint catches every defect it claims to.\033[0m\n' "$n" "$n"
fi
exit "$rc"
