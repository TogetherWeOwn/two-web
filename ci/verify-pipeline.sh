#!/usr/bin/env bash
#
# Proves the merge gate actually gates.
#
# A pipeline nobody has watched fail is a pipeline nobody knows works. This script
# opens one deliberately broken pull request per failure mode and asserts that the
# *right named job* went red for the *right reason* — not just that something,
# somewhere, was unhappy. A budget job that fails because the server did not start
# looks identical to one that caught a real LCP breach, and only one of those means
# the gate works.
#
# Then it opens a clean PR and asserts the whole thing goes green, and reports how
# long that took, because a gate the team will not wait for is a gate they will
# route around.
#
# This is the acceptance evidence for TWO-22. Run it once when the repo lands, and
# again after any change to .github/workflows/ci.yml that alters what fails.
#
# Usage:
#   ./ci/verify-pipeline.sh                   # dry run: prints what it would do
#   ./ci/verify-pipeline.sh --lint            # static checks only: no network, no gh
#   ./ci/verify-pipeline.sh --assert-selftest # tests --run's assertions offline
#   ./ci/verify-pipeline.sh --run             # actually opens the PRs (lints first)
#   ./ci/verify-pipeline.sh --cleanup         # clear every ci-verify/* branch and PR
#
# Needs: gh, authenticated. Opens PRs against `main`. Never pushes to `main`,
# never force-pushes anything, closes every PR it opens.
#
# The token needs exactly four fine-grained permissions on this repository, and
# nothing else — no org admin, no access to any other repo:
#
#   Contents:      read and write   (push and delete the ci-verify/* branches)
#   Pull requests: read and write   (open them, close them)
#   Checks:        read             (read the job results it asserts on)
#   Workflows:     write            (push the `gate` case, which edits ci.yml)
#
# `Actions: read` is deliberately NOT on that list, and must not be added back.
# This script used to read `repos/{slug}/actions/runs` and `.../jobs`, which is the
# one permission that also grants **workflow log download** — and a log carries
# whatever CI printed, which on a private repo is a much larger blast radius than
# "did this job go red". TOG-247 refused it permanently on that basis: *"Do not add
# `actions:read`, and do not accept it later as a convenience."* Every result this
# script asserts on now comes from the Checks API instead, which answers the only
# question the assertions ask — did this named job conclude, and how — and grants
# nothing else. TOG-328 did the port. If you find yourself adding `Actions: read`
# to make something here work, you are re-opening a closed security decision; port
# the read to check-runs instead.
#
# `Checks: read` is the one that gets left out. Without it the script can open
# every pull request and then read nothing back, which looks exactly like a
# pipeline that never ran.
#
# `Workflows: write` is the one this list itself left out until TOG-20, when a run
# on a token minted from it lost exactly one case:
#
#   ! [remote rejected] ci-verify/gate -> ci-verify/gate (refusing to allow a
#     GitHub App to create or update workflow `.github/workflows/ci.yml` without
#     `workflows` permission)
#
# GitHub gates pushes that touch `.github/workflows/**` on that permission
# separately from `Contents: write`, and `break_gate` edits ci.yml by design — that
# is the case. The push fails about ninety seconds in, names the permission but not
# which case wanted it, and the other nine cases carry on, so the run ends with one
# case missing rather than an obvious refusal at the top.
#
# One `--run` at a time per repository. Every case has a fixed branch name, so a
# second concurrent run overwrites and then deletes the first one's pull requests.
# The preconditions refuse to start when another run is live rather than let that
# happen quietly — see TWO-103.
#
# And `--run` needs a checkout of its own. It works by checking out and committing
# on ten branches in whatever repository it is standing in, and the checkout in
# an agent workspace is shared by every run of that agent — so doing that there is
# a write to another run's working tree, with no reflog entry for whatever
# uncommitted work it lands on. `./ci/scratch-clone.sh` gives the run a private
# clone in a couple of hundred kilobytes and no network; the preconditions refuse
# to start outside one — see TWO-112.
#
# --lint needs nothing but bash, so it runs before the org exists, in a pre-commit
# hook, or in CI itself. It catches the class of bug where the gate still reports
# green while it has quietly stopped gating.

set -euo pipefail

BRANCH_PREFIX="ci-verify"
# Every verification PR is cut from `origin/$BASE_BRANCH`, not from whatever is
# checked out — so with the default the run always measures what is already on
# `main`. That makes it impossible to verify a change to ci.yml or the budgets
# *before* merging it: the only way to test the fix is to ship the fix first.
# Point this at the pull request's head branch to test the tree that merging
# would produce. Default unchanged.
BASE_BRANCH="${CI_VERIFY_BASE_BRANCH:-main}"
MODE="${1:---dry-run}"

WORKFLOW="./.github/workflows/ci.yml"
DOCS="./docs/ci.md"

# The aggregate. Every other job in ci.yml must be in its `needs:`, and it must go
# red when any of them does — including when one is *skipped*.
AGGREGATE="tests"

# The checks branch protection on `main` requires, by check-run name. Source of
# truth for this file, docs/ci.md, and two-bot/scripts/setup-github.sh (TWO-39):
# all three have to agree or the gate is decorative.
#
# This is the list actually applied to `two-web` on TWO-36: the five real job ids
# plus `gitleaks`, and never `ci` — `CI` is the workflow *name*, no job reports it,
# and an absent required context blocks every PR forever.
#
# Requiring the leaves rather than the aggregate alone is deliberate: protection
# then does not depend on the aggregate's guard *staying* correct through future
# edits. The cost is that a new job is not required until someone adds it here,
# which is why check 7 below warns about exactly that.
REQUIRED_CHECKS=(tests static pest dusk budgets gitleaks)

# Every check run a pull request should produce. A check that never reports is not
# a pass — GitHub blocks on a missing required context and this script used to read
# an empty result as green, which is the same bug one level up.
EXPECTED_CHECKS=(static pest dusk budgets tests gitleaks)

# Each case: <slug>|<expected failing job>|<expected aggregate state>|<what it proves>
#
# The third field is what `tests` must report. Normally FAILURE: the named job goes
# red, `if: always()` runs the aggregate anyway, and its guard turns that into a red
# required check. Two cases are not FAILURE, and neither is a weakening.
#
# `gate` is NOT_SUCCESS, and that is not a weakening (TWO-94). That case deletes
# `if: always()` — the very mechanism that makes the aggregate report at all when a
# need is red. So on that pull request `tests` is *skipped*, and no possible edit to
# ci.yml could make it FAILURE instead: the thing being deleted is the thing that
# would do it. Asserting FAILURE there asks for something unreachable by
# construction, and the assertion fails while the gate is working.
#
# What stops that pull request is `static`. It runs `--lint` before anything slow,
# `--lint` fails on a missing `if: always()`, and `static` is a required check in its
# own right — which is the entire reason the leaves are required alongside the
# aggregate. So the case asserts the two things that are actually load-bearing:
# `static` is red, and `tests` did not report SUCCESS over a red pipeline. A skipped
# aggregate is tolerated; an aggregate that ran and said yes is not.
#
# Check 9 in lint() is what keeps that true offline: it fails if the job running
# `--lint` stops being a required check.
#
# `secret` is SUCCESS, and that is the other one. `gitleaks` is not a job in
# ci.yml at all — it comes from secret-scan.yml — so it is not in the aggregate's
# `needs:` and nothing it does can make `tests` red. On that pull request every
# ci.yml job is green, `tests` is green, and the only thing between a committed
# credential and `main` is that `gitleaks` is a required check in its own right.
# So the case asserts exactly that, and the assertion insists the aggregate is
# green rather than merely not-red: a breakage that also tripped a ci.yml job
# would pass while proving nothing about the secret scan, the same way break_dusk
# has to stay invisible to `pest`.
CASES=(
  "pint|static|FAILURE|badly formatted PHP is rejected"
  "phpstan|static|FAILURE|a type error is rejected"
  "pest|pest|FAILURE|a failing feature test is rejected"
  "tokens|pest|FAILURE|an edit to the vendored design system is rejected"
  "dusk|dusk|FAILURE|a broken page is caught in a real browser"
  "a11y|budgets|FAILURE|a WCAG 2.2 AA violation is rejected"
  "slowserver|budgets|FAILURE|a three-second server response is rejected"
  "lcp|budgets|FAILURE|a client-side LCP breach is rejected"
  "gate|static|NOT_SUCCESS|a pull request that disarms the merge gate is rejected"
  "secret|gitleaks|SUCCESS|a committed credential is rejected"
)

log()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
fail() { printf '\033[31mFAIL: %s\033[0m\n' "$*" >&2; }
pass() { printf '\033[32mPASS: %s\033[0m\n' "$*"; }

# --- static checks ----------------------------------------------------------
# Everything here is about one failure mode: the gate reports a result that is not
# the pipeline's result. Opening ten pull requests proves it too, but only after
# the org, the repo and the protection rules exist, and only in about forty
# minutes. These take no network and half a second, so there is no excuse.
#
# GitHub's two asymmetric rules, both of which have bitten this repo already:
#
#   a *skipped* required check counts as PASSED
#   an *absent* required check blocks the PR forever
#
# A job with a plain `needs:` is skipped — not failed — when a need goes red. So a
# plain aggregate is a green light on a red pipeline. `if: always()` plus a guard
# that treats `skipped` as red is what makes it a gate.

job_ids() { awk '/^jobs:/{j=1;next} j && /^  [a-zA-Z0-9_-]+:[[:space:]]*$/{gsub(/[ :]/,"");print}' "$1"; }

# The lines of one job's block: from `  <id>:` to the next job at the same indent.
job_block() { awk -v id="$2" '$0 ~ "^  " id ":[[:space:]]*$" {j=1;next} j && /^  [a-zA-Z0-9_-]+:[[:space:]]*$/{exit} j{print}' "$1"; }

# Does this workflow run on pull requests? Only those produce check runs a PR can
# be blocked on. `deploy.yml` is `workflow_run`, `main-guard` is push-only: their
# job ids look like perfectly good required contexts and would each hang every PR
# forever. Matching a job id is not enough — it has to be a job that *reports*.
#
# The awk output goes through a here-string rather than a pipe. See the note on
# `has_line` below: `awk | grep -q` under `pipefail` is a coin flip.
triggers_on_pr() {
  local on
  on=$(awk '/^on:/{o=1;next} o && /^[a-zA-Z]/{exit} o' "$1")
  grep -qE '^\s+pull_request:?' <<< "$on"
}

# grep for a pattern in some text, and say so honestly.
#
# Never write `producer | grep -q pattern` in this file. `grep -q` exits the
# instant it matches; if the producer still has output to write it takes SIGPIPE
# and dies with 141, and `set -o pipefail` makes the whole pipeline non-zero —
# so a *successful* match reports as a failure. `if` suppresses errexit but not
# pipefail, so the branch silently inverts. `grep -m1` exits early for the same
# reason and is banned in the same place.
#
# It is a race, so it depends on how far into the producer's output the match
# lands and on how fast the machine is: `job_block budgets | grep -q 'npm run
# build'` matched 65 lines from the end, passed on a laptop every time, and went
# red on the runner (TWO-87). A check that fails only sometimes, only in CI, and
# only when it should have passed is worse than no check.
#
# `verify-lint-selftest.sh` greps this file for the shape and fails on a hit,
# which is the only way a ban on something invisible in review stays a ban.
has_line() { grep -q -- "$2" <<< "$1"; }

# What one job will actually report as: its `name:` if it sets one, its id if not.
# Branch protection matches the check-run name, so a job that renames itself stops
# answering to its id and takes `main` down with it — the same "absent required
# check" failure as requiring `ci`, but arriving later and looking like flakiness.
# `^    name:` is job level; step names are deeper and carry a `- `.
job_reported_name() {
  local blk n
  blk=$(job_block "$1" "$2")
  n=$(grep -m1 -E '^    name:' <<< "$blk" | sed 's/^    name:[[:space:]]*//' | sed 's/^["'"'"']//; s/["'"'"']$//')
  [ -n "$n" ] && echo "$n" || echo "$2"
}

# Every check-run name a pull request will actually produce, across all workflows.
pr_reported_names() {
  local f job
  for f in ./.github/workflows/*.yml; do
    [ -f "$f" ] || continue
    triggers_on_pr "$f" || continue
    while read -r job; do
      [ -n "$job" ] && job_reported_name "$f" "$job"
    done <<< "$(job_ids "$f")"
  done
}

# Which jobs run the gate's own lint, by the name they report as. `--lint` is the
# only thing that catches an aggregate whose guard has been removed, so both the
# step's existence and the requiredness of the job carrying it are load-bearing.
gate_lint_jobs() {
  local job blk
  while read -r job; do
    [ -n "$job" ] || continue
    blk=$(job_block "$1" "$job")
    if has_line "$blk" 'verify-pipeline.sh --lint'; then
      job_reported_name "$1" "$job"
    fi
  done <<< "$(job_ids "$1")"
}

lint() {
  local rc=0 block needs jobs missing

  [ -f "$WORKFLOW" ] || { fail "$WORKFLOW not found — run this from the repository root"; return 1; }

  jobs=$(job_ids "$WORKFLOW")
  block=$(job_block "$WORKFLOW" "$AGGREGATE")

  if [ -z "$block" ]; then
    fail "no job \`${AGGREGATE}\` in ${WORKFLOW}. Branch protection requires that name; without the job the check never arrives and every PR waits forever."
    return 1
  fi

  # 1. The aggregate must run even when a need went red. Without this it is skipped,
  #    and a skipped required check is a passed one.
  if grep -qE '^\s*if:\s*always\(\)\s*$' <<< "$block"; then
    pass "\`${AGGREGATE}\` has \`if: always()\` — it runs even when a need fails"
  else
    fail "\`${AGGREGATE}\` has no \`if: always()\`. A red \`static\` would *skip* it, GitHub counts a skipped required check as passed, and the PR merges."
    rc=1
  fi

  # 2. Running is not enough — it has to fail. The guard must treat all three
  #    non-success results as red. `skipped` is the one people leave out: a job
  #    disabled by its own `if:` would otherwise sail through.
  for result in failure cancelled skipped; do
    if grep -q "'${result}'" <<< "$block"; then
      pass "\`${AGGREGATE}\` treats \`${result}\` as red"
    else
      fail "\`${AGGREGATE}\`'s guard never mentions \`${result}\` — a need in that state would pass the gate"
      rc=1
    fi
  done

  # 3. Every other job must be wired into the aggregate. Adding a job and forgetting
  #    this is silent: the new job goes red, the required check stays green.
  needs=$(grep -oE '^\s*needs:.*' <<< "$block" | tr -d '[]' | sed 's/.*needs://' | tr ',' ' ')
  missing=""
  while read -r job; do
    [ -n "$job" ] || continue
    [ "$job" = "$AGGREGATE" ] && continue
    grep -qw -- "$job" <<< "$needs" || missing="${missing} ${job}"
  done <<< "$jobs"
  if [ -n "$missing" ]; then
    fail "job(s)${missing} are not in \`${AGGREGATE}\`'s \`needs:\` — they can go red while the required check stays green"
    rc=1
  else
    pass "every job in ${WORKFLOW} is behind \`${AGGREGATE}\`"
  fi

  # 4. A `needs:` naming a job that does not exist is a workflow that never starts.
  for job in $needs; do
    grep -qx -- "$job" <<< "$jobs" || { fail "\`${AGGREGATE}\` needs \`${job}\`, which is not a job in ${WORKFLOW}"; rc=1; }
  done

  # 5. Every required check must be something a pull request actually reports.
  #    Protection matches the check-run name, so this compares against the names
  #    jobs *report as* on a PR — not their ids, and not jobs from workflows that
  #    never run on a PR at all. Both of those look like a match and neither one
  #    ever arrives, and an absent required check blocks every PR forever.
  local reported
  reported=$(pr_reported_names)
  for check in "${REQUIRED_CHECKS[@]}"; do
    if grep -qxF "$check" <<< "$reported"; then
      pass "required check \`${check}\` is reported by a job on every PR"
    else
      fail "required check \`${check}\` is not reported by any pull-request job — every PR would wait on it forever. Reported on a PR: $(echo "$reported" | tr '\n' ' ')"
      rc=1
    fi
  done

  # 6. The docs have to name the same checks. A protection rule set from a stale
  #    doc is how the wrong context gets required in the first place.
  if [ -f "$DOCS" ]; then
    for check in "${REQUIRED_CHECKS[@]}"; do
      grep -q "\`${check}\`" "$DOCS" || { fail "${DOCS} never names the required check \`${check}\`"; rc=1; }
    done
  fi

  # 7. The reverse drift, and the price of requiring leaves rather than the
  #    aggregate alone: a job that reports on pull requests but is not required can
  #    go red while the PR merges. A warning, not a failure — `tests` still covers
  #    it while check 3 passes — but this is the line that goes quiet the day
  #    someone adds a job and forgets protection, so it speaks up on every run.
  local required_list
  required_list=$(printf '%s\n' "${REQUIRED_CHECKS[@]}")
  while read -r name; do
    [ -n "$name" ] || continue
    grep -qxF "$name" <<< "$required_list" \
      || printf '\033[33mwarn: job `%s` reports on pull requests but is not a required check — it can go red while the PR merges\033[0m\n' "$name"
  done <<< "$reported"

  # 8. The PHP suite does not build assets — tests/TestCase.php stubs Vite so Pest
  #    stays PHP-only and gives the same answer on a clean runner as on a laptop
  #    with a stale `public/build`. That is only safe while something else still
  #    builds for real and renders the layout. `dusk` and `budgets` are that
  #    something. If either quietly drops its build step, nothing in the pipeline
  #    exercises a real manifest any more and the gate stops covering a whole
  #    class of breakage without a single job going red. Hence a failure here.
  #
  #    The block is read into a variable rather than piped straight into `grep -q`.
  #    Under `set -o pipefail` that pipeline reports the *producer's* status too,
  #    and `grep -q` exits the moment it matches, so a large enough job block can
  #    leave awk writing into a closed pipe and turn a passing check into a red
  #    one that depends on scheduling. This check is the thing standing between a
  #    stubbed Vite and no manifest coverage at all; it must not be able to fail
  #    for a reason that has nothing to do with the workflow.
  #
  #    "Job is missing" and "job is present but stopped building" are also reported
  #    separately. They need different fixes, and a check that says the wrong one
  #    sends whoever reads it looking in the wrong place.
  local block
  for job in dusk budgets; do
    block="$(job_block "$WORKFLOW" "$job")" || block=''

    if [ -z "$block" ]; then
      fail "job \`${job}\` was not found in ${WORKFLOW}. Check 8 expects it to exist and to build assets, because tests/TestCase.php stubs Vite for the PHP suite on the grounds that this job builds for real. If the job was renamed, rename it here too."
      rc=1
    elif grep -q 'npm run build' <<< "$block"; then
      pass "\`${job}\` builds assets — the real manifest is still exercised somewhere"
    else
      fail "job \`${job}\` no longer runs \`npm run build\`. tests/TestCase.php stubs Vite for the PHP suite on the grounds that this job builds for real; drop it and nothing tests the manifest, silently."
      rc=1
    fi
  done

  # 9. The gate's own lint must run on pull requests, from a job that is required.
  #
  #    Check 1 catches an aggregate that has lost `if: always()` — but only if
  #    something runs check 1 on the pull request making that edit, and only if a
  #    failure there actually blocks. `tests` cannot be what blocks it: deleting
  #    `if: always()` *skips* the aggregate, GitHub reads a skipped required check
  #    as passed, and the mechanism that would have made it red is the thing being
  #    deleted. The job carrying `--lint` is the only required check that goes red
  #    on that pull request (TWO-94), which is why this is a failure and not a
  #    warning: check 7 only warns about an unrequired job on the grounds that
  #    `tests` still covers it, and here `tests` is precisely what does not.
  #
  #    Two ways to lose this whole class of breakage with every job still green:
  #    drop the `--lint` step, or drop its job from REQUIRED_CHECKS. Neither shows
  #    up anywhere else in the pipeline.
  local lint_jobs guarded=""
  lint_jobs=$(gate_lint_jobs "$WORKFLOW")
  if [ -z "$lint_jobs" ]; then
    fail "no job in ${WORKFLOW} runs \`verify-pipeline.sh --lint\`. Nothing then catches an aggregate that has lost \`if: always()\`: that edit skips \`${AGGREGATE}\`, and a skipped required check counts as passed."
    rc=1
  else
    while read -r name; do
      [ -n "$name" ] || continue
      if grep -qxF "$name" <<< "$required_list"; then guarded="$name"; fi
    done <<< "$lint_jobs"
    if [ -n "$guarded" ]; then
      pass "\`${guarded}\` runs \`--lint\` and is a required check — it is what stops a PR that disarms \`${AGGREGATE}\`"
    else
      fail "the job(s) running \`--lint\` ($(echo "$lint_jobs" | tr '\n' ' ')) are not required checks. A pull request deleting \`${AGGREGATE}\`'s \`if: always()\` would skip \`${AGGREGATE}\` — which GitHub counts as passed — and nothing required would be red. Requiring \`${AGGREGATE}\` alone does not cover this."
      rc=1
    fi
  fi

  # 10. The performance budget is still a budget. ci/lighthouserc.cjs asks in prose
  #    that nobody edit a threshold to make a build go green, and until TWO-93
  #    nothing checked — a relaxed budget and an enforced one look the same from
  #    every job in the pipeline. Each entry below must be present, at `error`, at
  #    exactly this number.
  #
  #    LCP and CLS are the CEO's, in writing. Lowering one is their decision, and
  #    then it is changed here too, in the same commit that says so — that second
  #    edit is the point, not an obstacle.
  #
  #    `server-response-time` is not a CEO budget and is not optional either: it is
  #    the only thing in the pipeline that sees a slow server. Lighthouse runs with
  #    `throttlingMethod: 'simulate'`, and Lantern models one server response time
  #    per origin — the median over every request to it — so on a page that also
  #    loads a few static files a three-second document response is medianed away
  #    and simulated LCP never sees it. That is TWO-93, and it passed a homepage
  #    that took three seconds to answer. Delete this line and it passes one again.
  #
  #    Read through node rather than grepped. This check used to match the text of
  #    the file, and matching text is guessing: `'largest-contentful-paint'` is one
  #    of four spellings of that key, and the other three load as the effective
  #    budget while leaving the pinned line word for word intact —
  #
  #        "largest-contentful-paint": ['warn', { maxNumericValue: 99999 }],
  #        ['largest-contentful-paint']: ['warn', { maxNumericValue: 99999 }],
  #        ...{ 'largest-contentful-paint': ['warn', { maxNumericValue: 99999 }] },
  #
  #    — because an object literal keeps the last entry for a key. All three were
  #    demonstrated green past the grep (QA, TWO-101). Widening the pattern closes
  #    two of them and cannot close the spread, which has no key to match at all.
  #    So: load the config the way lhci loads it, with the same `require()`, and
  #    compare the value it actually gets. There is no fourth spelling to miss
  #    because there is no longer any spelling involved.
  local budget_file="./ci/lighthouserc.cjs"
  if [ ! -f "$budget_file" ]; then
    fail "${budget_file} not found — the \`budgets\` job has no thresholds to enforce"
    rc=1
  elif ! command -v node >/dev/null 2>&1; then
    # Red, not skipped. A budget check that cannot run is a budget that is not
    # enforced, and it should look like one. The runners have node preinstalled,
    # before `setup-node` runs, which is where `--lint` already sits in `static`.
    fail "node is not on PATH, so the budgets in ${budget_file} cannot be read as lhci reads them"
    rc=1
  else
    # One line per audit: name|level|maxNumericValue|aggregationMethod|options.
    #
    # The last field is every option key, sorted — the equivalent of anchoring the
    # old pattern on the closing brace. It means a fourth option cannot be added
    # unnoticed, and adding one on purpose means updating the line below, which is
    # the point. `aggregationMethod` is in there because it decides *which of the
    # three runs* the number is compared against. From the pinned @lhci/cli@0.14.0
    # (@lhci/utils/src/assertions.js):
    #
    #     const useMin =
    #       (aggregationMethod === 'optimistic' && assertionType.startsWith('max')) ||
    #       (aggregationMethod === 'pessimistic' && assertionType.startsWith('min'));
    #     return useMin ? Math.min(...values) : Math.max(...values);
    #
    # All three budgets are `maxNumericValue`, so `optimistic` takes the minimum
    # over `numberOfRuns: 3` — one word turns every budget from median-of-3 into
    # best-of-3 with the threshold still reading 2000 in the diff. `'median'` is
    # load-bearing rather than decoration: that same file defaults the option to
    # `'optimistic'`, so deleting it is best-of-3 by another route.
    # Read through `assertMatrix` as well as a plain `assertions` block, because
    # the budgets are now split by surface: the public pages keep the CEO's 2.0s
    # LCP and /admin has its own, looser ceiling (see ci/lighthouserc.cjs for why).
    #
    # What is checked here is the budget that applies to the PUBLIC pages, which is
    # the promise this guard exists to protect. The matrix entry is selected by
    # running lhci's own matching rule against a public URL, so moving the public
    # pages under a relaxed pattern fails this check rather than slipping past it —
    # the escape hatch this opens is exactly one URL pattern wide, and the
    # /admin-specific ceiling is asserted separately below.
    local effective entry audit value expected
    local loaded=1
    effective=$(node -e '
      const path = require("path");
      const config = require(path.resolve(process.argv[1]));
      const assert = (config.ci || {}).assert || {};

      // lhci applies every matrix entry whose pattern matches the URL, so the
      // effective budget for a public page is the last matching entry — the same
      // way a duplicate key in a plain object literal wins.
      const PUBLIC_URL = "http://127.0.0.1:8000/";
      let assertions = assert.assertions || {};
      if (Array.isArray(assert.assertMatrix)) {
        assertions = {};
        for (const group of assert.assertMatrix) {
          if (!new RegExp(group.matchingUrlPattern).test(PUBLIC_URL)) continue;
          Object.assign(assertions, group.assertions || {});
        }
      }

      for (const audit of process.argv.slice(2)) {
        const entry = assertions[audit];
        if (!Array.isArray(entry)) { console.log(audit + "|absent|||"); continue; }
        const options = entry[1] || {};
        console.log([
          audit,
          entry[0],
          String(options.maxNumericValue),
          String(options.aggregationMethod),
          Object.keys(options).sort().join("+"),
        ].join("|"));
      }
    ' "$budget_file" largest-contentful-paint cumulative-layout-shift server-response-time 2>&1) || loaded=0

    if [ "$loaded" -eq 0 ]; then
      fail "${budget_file} could not be loaded by node, so lhci cannot load it either and the \`budgets\` job has no thresholds to enforce: ${effective}"
      rc=1
    else
      for entry in \
        "largest-contentful-paint|2000" \
        "cumulative-layout-shift|0.1" \
        "server-response-time|600"; do
        IFS='|' read -r audit value <<< "$entry"
        expected="${audit}|error|${value}|median|aggregationMethod+maxNumericValue"

        if grep -qxF -- "$expected" <<< "$effective"; then
          pass "budget \`${audit}\` fails the build above ${value}, on the median of the runs"
        else
          fail "budget \`${audit}\` is not asserted at ${value} as an \`error\` on the median of the runs. lhci loads \`$(grep -F -- "${audit}|" <<< "$effective" | head -1)\` (audit|level|maxNumericValue|aggregationMethod|options), and the job goes on reporting green whether the budget was relaxed, downgraded to a warning, removed, or had its aggregation changed. If the line in ${budget_file} still reads correctly, look further down the file for a second entry for the same audit: an object literal keeps the last one. If the budget genuinely changed, change it in both places in the commit that explains why."
          rc=1
        fi
      done

      # The /admin exception, bounded. A per-surface budget is a door, and the way
      # it gets abused is not by deleting the public budget above — it is by
      # nudging the relaxed one up a few hundred milliseconds at a time until it
      # means nothing. This pins the ceiling: raising it is a deliberate edit here
      # with a reason, which is the same standard the public budget is held to.
      local admin_lcp
      admin_lcp=$(node -e '
        const path = require("path");
        const config = require(path.resolve(process.argv[1]));
        const assert = (config.ci || {}).assert || {};
        const ADMIN_URL = "http://127.0.0.1:8000/admin";
        let assertions = assert.assertions || {};
        if (Array.isArray(assert.assertMatrix)) {
          assertions = {};
          for (const group of assert.assertMatrix) {
            if (!new RegExp(group.matchingUrlPattern).test(ADMIN_URL)) continue;
            Object.assign(assertions, group.assertions || {});
          }
        }
        const entry = assertions["largest-contentful-paint"];
        if (!Array.isArray(entry)) { console.log("absent"); process.exit(0); }
        const options = entry[1] || {};
        console.log([entry[0], String(options.maxNumericValue), String(options.aggregationMethod)].join("|"));
      ' "$budget_file" 2>&1)

      if [ "$admin_lcp" = "error|3000|median" ]; then
        pass "the \`/admin\` LCP exception is still capped at 3000 as an \`error\` on the median"
      else
        fail "the relaxed \`/admin\` LCP budget reads \`${admin_lcp}\`, not \`error|3000|median\`. That budget exists because Filament's stylesheet is render-blocking; it is not a general allowance to be widened. TOG-1008 tree-shook the theme to 342KB and brought the ceiling down from 3200 with it, and with the stylesheet stubbed out entirely the panel still medians 2047ms, so no further CSS work reaches the public 2000ms budget. If the panel genuinely got slower, find what regressed; do not raise this number."
        rc=1
      fi

      # How LHCI launches Chrome, read the same way — through node, not grep.
      # TOG-7021: the budgets job died on `Invalid URL: undefined`, the
      # DevTools endpoint of a browser that never started, because LHCI shelled
      # out to an unpinned Chrome with none of the sandbox workarounds every
      # other launcher here passes. Both flags match ci/browser/launch.mjs and
      # ci/a11y.mjs; dropping either is how the next startup crash arrives.
      local chrome_flags
      chrome_flags=$(node -e '
        const path = require("path");
        const config = require(path.resolve(process.argv[1]));
        console.log((config.ci || {}).collect?.settings?.chromeFlags ?? "absent");
      ' "$budget_file" 2>&1) || chrome_flags="unreadable: ${chrome_flags}"
      for chrome_flag in --no-sandbox --disable-dev-shm-usage; do
        if has_line "$chrome_flags" "$chrome_flag"; then
          pass "LHCI launches Chrome with \`${chrome_flag}\`"
        else
          fail "ci/lighthouserc.cjs launches Chrome without \`${chrome_flag}\` (chromeFlags reads \`${chrome_flags}\`). On the persistent self-hosted hosts that is a browser that dies at startup and a job that fails as \`Invalid URL: undefined\` with zero assertion results (TOG-7021). The flags match ci/browser/launch.mjs and ci/a11y.mjs; if one genuinely has to go, say which and why in the commit, and update this check with it."
          rc=1
        fi
      done
    fi
  fi

  # 11. The secret scan is still scanning for more than three things.
  #
  #    `.gitleaks.toml` opens with `[extend] useDefault = true`. That one line is
  #    what keeps every provider pattern gitleaks maintains — AWS, GCP, Stripe,
  #    GitHub PATs, private keys — switched on for this repo. Delete it and the
  #    scan falls back to our three hand-written Discord rules and nothing else,
  #    and every job in the pipeline stays green while it happens.
  #
  #    Nothing live covers this and nothing live can. The `secret` case in `--run`
  #    commits a value shaped to match our own `discord-bot-token` rule precisely
  #    so that a config which stopped being read is caught (docs/ci.md, "Which
  #    required check stops a committed credential"). A check-run conclusion is
  #    one bit, so a case that tripped both rule sources could not say which one
  #    fired, and the repo-specific rules are the half with no other coverage
  #    anywhere. That trade is written down and it is the right one; it just
  #    leaves this half uncovered, which is what this check is for (TOG-297).
  #
  #    Read through a TOML parser rather than grepped, for the same reason check
  #    10 loads lighthouserc.cjs through node: matching text is guessing. All
  #    four of these leave `useDefault = true` in the file word for word and were
  #    run against the pinned gitleaks 8.30.1 —
  #
  #        [allowlist]              # the line intact, the table above it changed:
  #        useDefault = true        # it now sets allowlist.useDefault. DEFAULTS OFF.
  #
  #        useDefault = "false"     # a string. Loads clean, defaults OFF, exit 0.
  #        useDefault = "yes"       # a string. Config fails to load entirely.
  #        disabledRules = ['aws-access-token', 'discord-bot-token']
  #
  #    — the first because a stanza inserted above it re-homes the key without
  #    touching it, the middle two because TOML types are not shell truthiness,
  #    and the last because `[extend]` has a second field that turns rules off by
  #    name, ours included, with `useDefault` still reading `true`. Measured: a
  #    repository with an AWS key pair and a real-shaped Discord bot token reports
  #    4 findings on the real config, 2 with the stanza inserted, 0 with the
  #    string, and 1 with the two rules disabled. Only the first of those four is
  #    visible to a grep for the line.
  #
  #    The allowlist bullet is not a nicety either. `regexes` is matched against
  #    every candidate finding, so one bare `.*` there is an off switch for the
  #    whole scan — the same 4-finding repository reports 0, `discord-bot-token`
  #    included. `paths` does it too. The entries in the file are narrow on
  #    purpose and say so in prose; this is what makes that prose a rule.
  local gitleaks_file="./.gitleaks.toml"
  if [ ! -f "$gitleaks_file" ]; then
    fail "${gitleaks_file} not found. Without it the \`gitleaks\` job scans with the stock rule set alone: no \`discord-bot-token\`, and the bot token is the one credential this repository must never hold (.gitleaks.toml's own header)."
    rc=1
  elif ! command -v python3 >/dev/null 2>&1; then
    # Red, not skipped — same rule as check 10. A config check that cannot run is
    # a config that is not checked, and it should look like one. The runners are
    # Ubuntu 24.04 and carry python3, and `tomllib` has been in the standard
    # library since 3.11, so `static` needs no install step for this.
    fail "python3 is not on PATH, so ${gitleaks_file} cannot be read as gitleaks reads it"
    rc=1
  else
    # One `key=value` line per fact. Parsed, not matched: `extend.useDefault` is
    # the value the parser resolves, so a key re-homed under another table reads
    # as absent here exactly as it does to gitleaks.
    #
    # `useDefault` is reported with its TOML type attached rather than coerced.
    # gitleaks unmarshals it into a Go `bool`; a string is either a hard config
    # load failure or silently not-true, and both of those are the defaults off.
    # Anything but a real TOML `true` is wrong, so the type is part of the fact.
    local gitleaks_facts gitleaks_loaded=1
    gitleaks_facts=$(python3 -c '
import re, sys, tomllib

with open(sys.argv[1], "rb") as fh:
    config = tomllib.load(fh)

extend = config.get("extend") or {}
use_default = extend.get("useDefault")
print("useDefault=%s:%r" % (type(use_default).__name__, use_default))
print("disabledRules=%s" % ",".join(sorted(str(r) for r in extend.get("disabledRules") or [])))
print("rules=%s" % ",".join(sorted(str(r.get("id")) for r in config.get("rules") or [])))

# A pattern that matches somewhere inside the empty string matches inside every
# string, so it allowlists everything. That is `.*`, `.+` on nothing at all,
# `(a|)`, `^`, and every other spelling of the same thing — tested rather than
# enumerated, because a list of shapes to reject is a list to be walked around.
# Compiled with Python `re`; gitleaks uses Go RE2, and the two disagree on
# exotica but not on whether a pattern can match nothing.
allowlists = config.get("allowlists") or []
if config.get("allowlist"):
    allowlists = [config["allowlist"]] + list(allowlists)
trivial = []
for index, allowlist in enumerate(allowlists):
    for key in ("regexes", "paths"):
        for pattern in allowlist.get(key) or []:
            try:
                compiled = re.compile(pattern)
            except re.error:
                trivial.append("%s[%d]:uncompilable" % (key, index))
                continue
            if compiled.search(""):
                trivial.append("%s[%d]:%s" % (key, index, pattern))
print("trivialAllowlist=%s" % ",".join(trivial))
' "$gitleaks_file" 2>&1) || gitleaks_loaded=0

    if [ "$gitleaks_loaded" -eq 0 ]; then
      fail "${gitleaks_file} could not be parsed as TOML, so gitleaks cannot load it either — and a config it cannot load is a scan running on the stock rule set or not running at all: ${gitleaks_facts}"
      rc=1
    else
      # `bool:True` and nothing else. `"true"` is a string, `1` is an int, and a
      # key that landed under a different table is `NoneType:None`.
      if grep -qxF -- 'useDefault=bool:True' <<< "$gitleaks_facts"; then
        pass "\`.gitleaks.toml\` extends the default rule set — every provider pattern gitleaks maintains is on"
      else
        fail "\`[extend] useDefault\` in ${gitleaks_file} does not resolve to the boolean \`true\` — a TOML parser reads \`$(grep -F 'useDefault=' <<< "$gitleaks_facts")\` (type:value). The scan then runs on our three Discord rules alone: no AWS, GCP, Stripe, GitHub PAT or private-key pattern, and every job stays green. If the line still reads \`useDefault = true\` in the file, check what table it is under — a stanza inserted above it re-homes the key without touching the line — and check it is not quoted, because a string is not a bool."
        rc=1
      fi

      # The three rules .gitleaks.toml's own header calls the boundary the
      # integration rests on. The stock rule set has no Discord token pattern of
      # its own, so deleting one of these blocks is not a relaxation — it is the
      # only thing anywhere looking for that value, gone.
      local defined rule
      defined=$(sed -n 's/^rules=//p' <<< "$gitleaks_facts")
      for rule in discord-bot-token discord-mfa-token discord-webhook; do
        if grep -qE "(^|,)${rule}(,|$)" <<< "$defined"; then
          pass "\`${rule}\` is defined"
        else
          fail "${gitleaks_file} no longer defines the rule \`${rule}\`. The stock rule set has no Discord token pattern, so nothing anywhere is looking for one — and \`discord-bot-token\` firing on this repository means the boundary the whole integration rests on has been crossed (${gitleaks_file}, header). Defined: ${defined:-none}"
          rc=1
        fi
      done

      # `[extend]` has a second field, and it is the quiet way to undo the line
      # above. `disabledRules` names rules to switch off *in the set being
      # extended*, so every entry is one provider pattern subtracted from the
      # thing `useDefault = true` is there to supply — with `useDefault = true`
      # still sitting above it reading correctly.
      #
      # Measured against the pinned 8.30.1, and worth stating precisely because
      # the obvious guess is wrong: it reaches the default rules only. Naming our
      # own `discord-bot-token` there does nothing at all — the rule goes on
      # firing — so this is a check about the default half, not about ours, and a
      # `disabledRules` entry cannot be used to blunt the Discord rules. Naming
      # `aws-access-token` there does exactly what it looks like: that rule stops
      # reporting and nothing else changes.
      #
      # Empty is the only correct value. Disabling a stock rule is a real thing
      # to want one day — a pattern that false-positives on this repository the
      # way `discord-client-id` did (see the allowlist notes below) — and the
      # answer then is to allowlist the specific value, or to change this line in
      # the commit that explains which pattern and why. That second edit is the
      # point, exactly as it is for the Lighthouse budgets in check 10.
      local disabled
      disabled=$(sed -n 's/^disabledRules=//p' <<< "$gitleaks_facts")
      if [ -z "$disabled" ]; then
        pass "\`[extend] disabledRules\` is empty — no provider pattern is switched off"
      else
        fail "\`[extend] disabledRules\` in ${gitleaks_file} switches off ${disabled}. Each entry subtracts a provider pattern from the default rule set that \`useDefault = true\` on the line above exists to turn on, and the config goes on reading as though it were fully armed — verified against the pinned gitleaks 8.30.1. If a stock rule genuinely false-positives here, allowlist the specific value it is catching rather than the whole pattern; if the rule really has to go, say which and why in the commit, and update this check with it."
        rc=1
      fi

      # Anything in the allowlist that matches the empty string matches every
      # finding. Not a warning: this is the one edit in the file that silences
      # `discord-bot-token` as well as everything else, and it fits on one line.
      local trivial
      trivial=$(sed -n 's/^trivialAllowlist=//p' <<< "$gitleaks_facts")
      if [ -z "$trivial" ]; then
        pass "no allowlist entry in \`.gitleaks.toml\` matches trivially"
      else
        fail "allowlist entr(ies) ${trivial} in ${gitleaks_file} match the empty string, so they match every finding — that is an off switch for the whole scan, \`discord-bot-token\` included, with the job still reporting green. Measured on gitleaks 8.30.1: a repository with four findings reports none. The entries in that file are narrow on purpose and say so; if a real fixture needs allowlisting, name it — see the bar written above \`[allowlist]\`."
        rc=1
      fi
    fi
  fi

  # 12. The Vite bundle budget is still a budget (TOG-5629).
  #
  #    ci/bundle-budget.json caps every built entrypoint in raw and gzip bytes,
  #    and the `budgets` job fails when ci/check-bundle-budget.mjs finds one
  #    over its ceiling. Both halves have a quiet way out: relax a number in
  #    the JSON until the breach goes green, or drop the enforcement step from
  #    the job while leaving the file in place. Either edit leaves every job
  #    green while the bundle grows without bound — the TOG-53 axios accident
  #    (48 KB in the 1-byte app.js) and the TOG-3233 unconditional hallmark
  #    include (10.9 KB on every public page) would both have merged clean.
  #
  #    Parsed, not matched: the ceilings are read out of the JSON with node's
  #    own JSON.parse — the same values the checker compares against — so a
  #    second `budgets` key appended lower in the file reads as the effective
  #    one, exactly as the checker sees it. Same lesson as check 10's four
  #    spellings of a duplicate key.
  local bundle_budget_file="./ci/bundle-budget.json"
  if [ ! -f "$bundle_budget_file" ]; then
    fail "${bundle_budget_file} not found — the \`budgets\` job has no bundle thresholds to enforce"
    rc=1
  elif ! command -v node >/dev/null 2>&1; then
    # Red, not skipped — same rule as check 10. A budget check that cannot run
    # is a budget that is not enforced, and it should look like one.
    fail "node is not on PATH, so ${bundle_budget_file} cannot be read as the checker reads it"
    rc=1
  else
    # One `entry|raw|gzip` line per ceiling. Compared exactly: raising a number
    # to make a breach go green is a deliberate edit here, in the commit that
    # says what grew and why — the same standard as the Lighthouse budgets.
    local bundle_effective bundle_loaded=1
    bundle_effective=$(node -e '
      const fs = require("fs");
      const budget = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
      for (const [entry, ceiling] of Object.entries(budget.budgets || {})) {
        console.log([entry, String(ceiling.maxRawBytes), String(ceiling.maxGzipBytes)].join("|"));
      }
    ' "$bundle_budget_file" 2>&1) || bundle_loaded=0

    if [ "$bundle_loaded" -eq 0 ]; then
      fail "${bundle_budget_file} could not be parsed as JSON, so the checker cannot load it either and the \`budgets\` job has no bundle thresholds to enforce: ${bundle_effective}"
      rc=1
    else
      local bundle_entry bundle_expected
      for bundle_entry in \
        "resources/css/app.css|76800|15360" \
        "resources/css/filament/admin/theme.css|375000|38000" \
        "resources/css/hallmark.css|15360|4096" \
        "resources/js/app.js|5120|2048" \
        "resources/js/event-copy-link.js|4096|2048"; do
        if grep -qxF -- "$bundle_entry" <<< "$bundle_effective"; then
          pass "bundle budget \`$(cut -d'|' -f1 <<< "$bundle_entry")\` caps raw and gzip at $(cut -d'|' -f2 <<< "$bundle_entry")/$(cut -d'|' -f3 <<< "$bundle_entry") bytes"
        else
          # `|| true`: an absent entry makes grep exit 1, and under
          # `set -o pipefail` that status would kill the whole lint instead of
          # reporting the absence. Empty reads as `absent` below, which is the
          # point — a deleted ceiling must fail loudly, not silently.
          bundle_expected="$(grep -F -- "$(cut -d'|' -f1 <<< "$bundle_entry")|" <<< "$bundle_effective" | head -1 || true)"
          fail "bundle budget for \`$(cut -d'|' -f1 <<< "$bundle_entry")\` reads \`${bundle_expected:-absent}\`, not \`$(cut -d'|' -f2 <<< "$bundle_entry")|$(cut -d'|' -f3 <<< "$bundle_entry")\` (entry|raw|gzip). A ceiling quietly raised — or an entry deleted — lets the bundle grow while every job stays green. If the bundle genuinely grew, raise the ceiling in ${bundle_budget_file} in the commit that says what grew and why, and update this pin with it."
          rc=1
        fi
      done
    fi

    # The enforcement step itself, addressed to the `budgets` job by name rather
    # than grepped across the file — the same reason check 8's self-test case
    # addresses `dusk` by name. A `Bundle budget` step added to `static` would
    # satisfy a file-wide grep while measuring nothing (no manifest there), and
    # a new job above `budgets` that builds would quietly move the assumption
    # the Pest half rests on.
    local budgets_block
    budgets_block="$(job_block "$WORKFLOW" "budgets")" || budgets_block=''

    if [ -z "$budgets_block" ]; then
      fail "job \`budgets\` was not found in ${WORKFLOW}. Check 12 expects it to exist and to enforce the bundle budget, because the checker needs the real manifest only a job that builds for real produces. If the job was renamed, rename it here too."
      rc=1
    elif has_line "$budgets_block" 'check-bundle-budget.mjs'; then
      pass "\`budgets\` enforces the bundle budget — the checker still runs where the manifest is real"
    else
      fail "job \`budgets\` no longer runs \`check-bundle-budget.mjs\`. ${bundle_budget_file} still caps every entrypoint, but nothing compares the built bundle against it — a budget with no enforcement, silently."
      rc=1
    fi

    # The checker's own self-test must still run in `static`: the byte
    # comparison needs a manifest so it lives in `budgets`, and a checker that
    # quietly stopped failing is indistinguishable from a fitting bundle unless
    # something offline proves it still fails. Same argument as check 9's.
    local budget_selftest_jobs budget_selftest_job budget_selftest_blk
    budget_selftest_jobs=$(while read -r budget_selftest_job; do
      [ -n "$budget_selftest_job" ] || continue
      budget_selftest_blk=$(job_block "$WORKFLOW" "$budget_selftest_job")
      if has_line "$budget_selftest_blk" 'check-bundle-budget.mjs --selftest'; then
        job_reported_name "$WORKFLOW" "$budget_selftest_job"
      fi
    done <<< "$(job_ids "$WORKFLOW")")
    if [ -z "$budget_selftest_jobs" ]; then
      fail "no job in ${WORKFLOW} runs \`check-bundle-budget.mjs --selftest\`. Nothing then catches a checker that has quietly stopped failing: the byte comparison only ever executes in \`budgets\`, against a real manifest, so a neutered checker and a fitting bundle look identical from every job in the pipeline."
      rc=1
    else
      pass "\`$(echo "$budget_selftest_jobs" | tr '\n' ' ' | sed 's/ $//')\` runs the bundle checker's self-test — a checker that stops failing cannot go quiet"
    fi
  fi

  return "$rc"
}

# The assertions, kept separate from the fetching so they can be exercised without
# a network, a repository, or forty minutes of runner time — see --assert-selftest.
# `checks` is one `name=STATE` per line, exactly as `branch_checks` yields it.
assert_checks() {
  local checks expected_job="$2" aggregate="$3" expect_green="$4" why="$5" where="${6:-}"
  checks=$(grep -v '^[[:space:]]*$' <<< "$1" || true)
  if [ -n "$where" ]; then where=" on ${where}"; fi

  # A check that never reported is not a pass. If the workflow file has a syntax
  # error, or Actions is disabled on the repo, no check run is ever created and the
  # Checks API returns an empty list — and reading nothing as green is exactly the
  # bug this script exists to catch, one level up. The other two ways to get nothing
  # back — an origin that is not a GitHub repository, and a token without
  # `Checks: read` — are ruled out in the preconditions before anything is pushed
  # (TWO-87, and TOG-328 for the move off `Actions: read`). The fourth, a push that
  # never landed, is not ruled out anywhere and is meant to arrive here: it looks
  # like a case that was never gated, because it was not.
  local absent=""
  for expected in "${EXPECTED_CHECKS[@]}"; do
    grep -q "^${expected}=" <<< "$checks" || absent="${absent} ${expected}"
  done
  if [ -n "$absent" ]; then
    fail "${why}: check(s)${absent} never reported${where}. A required check that never arrives blocks the PR forever; one that is not required is not gating at all. Got: $(echo "$checks" | tr '\n' ' ')"
    return 1
  fi

  if [ "$expect_green" = "yes" ]; then
    local not_green
    not_green=$(grep -v '=SUCCESS$' <<< "$checks" || true)
    if [ -n "$not_green" ]; then
      fail "clean PR was not green: $(echo "$not_green" | tr '\n' ' ')"
      return 1
    fi
    pass "clean PR went green (all ${#EXPECTED_CHECKS[@]} checks reported SUCCESS)"
    return 0
  fi

  # The named job must be the one that failed. Anything else — including a green
  # run — means the gate does not catch this, whatever else it caught.
  if ! grep -q "^${expected_job}=FAILURE$" <<< "$checks"; then
    fail "${why}: expected job '${expected_job}' to fail${where}, got: $(echo "$checks" | tr '\n' ' ')"
    return 1
  fi

  local aggregate_state
  aggregate_state=$(grep -m1 "^${AGGREGATE}=" <<< "$checks" | cut -d= -f2)

  if [ "$aggregate" = "FAILURE" ]; then
    # The aggregate is a required check, and it has `if: always()` on this pull
    # request, so it must have run and gone red. A named job going red while
    # `tests` stays green is the exact failure this whole script exists to catch:
    # it looks like a working pipeline and merges anyway.
    if [ "$aggregate_state" != "FAILURE" ]; then
      fail "${why}: '${expected_job}' failed but the required '${AGGREGATE}' check reported ${aggregate_state} — the gate would let this merge"
      return 1
    fi
    pass "${why} (rejected by '${expected_job}', and '${AGGREGATE}' went red with it)"
    return 0
  fi

  if [ "$aggregate" = "SUCCESS" ]; then
    # SUCCESS — the `secret` case, and only that case. `gitleaks` comes from
    # secret-scan.yml, not from ci.yml, so it is not in the aggregate's `needs:`
    # and cannot make it red however loudly it fails. That is not a defect, but it
    # does mean the argument that stops this pull request is a different one, and
    # it is thinner: the failing check being required is the *whole* of it.
    #
    # So assert it rather than assume it. Drop `gitleaks` from protection and
    # every required check on this pull request is green — a credential merges,
    # with a green tick, and nothing in ci.yml is wrong.
    if ! grep -qxF "$expected_job" <<< "$(printf '%s\n' "${REQUIRED_CHECKS[@]}")"; then
      fail "${why}: '${expected_job}' failed, but it is not a required check and it is not behind '${AGGREGATE}' either — it is in another workflow. Nothing required is red on this pull request, so it merges."
      return 1
    fi
    # And the aggregate must be green, not merely not-red. This case is only
    # evidence about the secret scan while the secret scan is the only thing it
    # trips: a breakage that also took a ci.yml job down would satisfy every line
    # above while proving nothing about `gitleaks`, which is the same reason
    # break_dusk hides the heading with CSS instead of deleting it.
    if [ "$aggregate_state" != "SUCCESS" ]; then
      fail "${why}: '${expected_job}' failed as expected, but '${AGGREGATE}' reported ${aggregate_state}. The breakage was supposed to be invisible to ci.yml; something else on this branch is red, so this run is not evidence that '${expected_job}' caught anything."
      return 1
    fi
    pass "${why} (rejected by required check '${expected_job}' alone; '${AGGREGATE}' stayed green, because '${expected_job}' is not one of its needs)"
    return 0
  fi

  # NOT_SUCCESS — the `gate` case, and only that case. See the CASES comment: the
  # breakage deletes the aggregate's `if: always()`, so the aggregate is skipped
  # and cannot be red. Two things still have to hold, and both are real.
  #
  # First, the aggregate must not have reported SUCCESS. Skipped is tolerated;
  # skipped is what a deleted `if: always()` produces. SUCCESS would mean the
  # aggregate ran and blessed a red pipeline, which is a genuine guard defect.
  if [ "$aggregate_state" = "SUCCESS" ]; then
    fail "${why}: '${expected_job}' failed and '${AGGREGATE}' still reported SUCCESS. The aggregate ran and passed over a red need — its guard is not treating a red need as red."
    return 1
  fi
  # Second, the job that did go red must itself be a required check. Otherwise
  # nothing required is red, the aggregate counts as passed, and this merges. This
  # is the whole load-bearing argument for requiring the leaves as well as the
  # aggregate, so it is asserted rather than assumed. Check 9 in lint() is the
  # offline half of the same guarantee.
  if ! grep -qxF "$expected_job" <<< "$(printf '%s\n' "${REQUIRED_CHECKS[@]}")"; then
    fail "${why}: '${expected_job}' failed and '${AGGREGATE}' was ${aggregate_state}, but '${expected_job}' is not a required check. Nothing required is red and a skipped required check counts as passed — this merges."
    return 1
  fi
  pass "${why} (rejected by required check '${expected_job}'; '${AGGREGATE}' was ${aggregate_state}, which is not a pass and not what stops this one — TWO-94)"
  return 0
}

# --- reading the results ------------------------------------------------------

# The fetch layer, kept next to the assertions it feeds and above the mode
# dispatch, for the same reason assert_checks is up here: so `--assert-selftest`
# can exercise it with no network. `wait_for_checks` is the one function in this
# file whose failure mode is *silence* — it returns, the assertions read a
# half-finished branch, and the run reports a check that never ran. TOG-328 made it
# carry a condition it used to share with the Actions API, so it is now tested
# rather than reviewed.
#
# $REPO_SLUG is resolved and proven readable in the preconditions, before anything
# is pushed. Nothing here is called until then.

POLL_INTERVAL=20
CHECK_TIMEOUT=2400

# The commit the checks are asked about. Check runs hang off a commit, not a
# branch, so every read below needs a SHA.
#
# Read from the *local* ref rather than from the remote or the API, on purpose.
# This clone is where the branch was just built and pushed from, and it is
# run-private (the scratch-clone precondition, TWO-112), so the local ref is the
# commit this run put on the remote — which is the commit whose checks it is
# entitled to assert on. Resolving through the remote instead would silently follow
# anything that landed on top, and report someone else's result as this run's.
#
# It also spends no API call per poll, and a branch name with a `/` in it — every
# branch here has one — needs escaping in a `/commits/{ref}/` path and does not
# here.
#
# Empty when the branch does not exist locally, which is what a failed push leaves
# behind. That is deliberately not an error at this level: it flows through as "no
# check reported", and assert_checks names the missing checks and fails the case.
# That is the right report — a case whose branch never reached the remote did not
# get gated, and the run must not pass.
branch_head() {
  git rev-parse --verify --quiet "refs/heads/$1" 2>/dev/null || true
}

# "<job>=<STATE>" per line. STATE is SUCCESS / FAILURE / CANCELLED / SKIPPED, or
# PENDING for a check that has not concluded.
#
# Shorter than the Actions version it replaces because check runs are already
# per-job: there is no run to enumerate first and no second call to expand a run
# into its jobs.
#
#   filter=latest    the API default, stated anyway because the whole assertion
#                    rests on it. It keeps one check run per name — the most
#                    recent. That is what `group_by(.workflow_id) | max_by(
#                    .run_number)` was doing in the Actions version: ci.yml cancels
#                    an in-progress run when the branch is pushed again, and a
#                    cancelled predecessor is not the result being asserted on.
#   app.slug         only GitHub Actions. The Actions API could not return anything
#                    else; check-runs can, because any GitHub App may post one. An
#                    unrelated app's check run would be a line in this output that
#                    no job produced — and the clean case asserts *every* line is
#                    SUCCESS, so a third-party check would fail a green pipeline.
branch_checks() {
  local sha
  sha=$(branch_head "$1")
  [ -n "$sha" ] || return 0
  gh api "repos/${REPO_SLUG}/commits/${sha}/check-runs?per_page=100&filter=latest" \
    --jq '.check_runs[] | select(.app.slug == "github-actions")
          | "\(.name)=\(.conclusion // "pending" | ascii_upcase)"' 2>/dev/null || true
}

# Blocks until every expected check has finished. Returns non-zero on timeout so
# the caller reports "never reported" rather than reading a half-finished run.
#
# The Actions version waited on two conditions — every workflow run it could see
# has completed, *and* every name in EXPECTED_CHECKS has reported — because
# neither is sufficient alone. Two workflows produce these checks (ci.yml and
# secret-scan.yml) and the API only lists a run once GitHub has created it, so
# "every run I can see is done" returns immediately in the window where one
# workflow has finished and the other has not been created yet. The assertion then
# reports a check that never ran, on a branch where it was about to.
#
# Check runs have no run-level status to read, so that half is gone and the
# EXPECTED_CHECKS half has to carry the whole load. It can, and the reason is that
# it was always the load-bearing half: the window above is exactly "a name in
# EXPECTED_CHECKS is not there yet", and waiting for the name closes it whether the
# gap came from an uncreated run or an unfinished one.
#
# What changes is that presence is no longer enough. In the old form a name could
# be counted as reported while still PENDING, because the run-completed half was
# there to rule that out; drop that half and PENDING has to be rejected here or the
# script reads a half-finished pipeline as a finished one. So the condition is:
# every expected name is present AND none of them is PENDING. A check run that
# exists but has not concluded reports `status: queued|in_progress` with a null
# conclusion, which branch_checks renders as PENDING — that is the replacement
# signal for the run-level status this used to read.
#
# Names not in EXPECTED_CHECKS are not waited on, same as before. A new job in
# ci.yml is not a reason to block; it is a reason for check 7 in lint() to tell
# someone to add it to the list.
# Written as an explicit state read rather than two `grep -q`s and a `&&`: an
# AND-list whose left side legitimately fails is a `set -e` exit waiting for the
# day someone calls this outside a `|| true`.
wait_for_checks() {
  local branch="$1" waited=0 checks settled state
  while [ "$waited" -lt "$CHECK_TIMEOUT" ]; do
    checks=$(branch_checks "$branch")
    settled=1
    for expected in "${EXPECTED_CHECKS[@]}"; do
      state=$(grep -m1 "^${expected}=" <<< "$checks" | cut -d= -f2 || true)
      if [ -z "$state" ] || [ "$state" = PENDING ]; then
        settled=0
        break
      fi
    done
    if [ "$settled" -eq 1 ]; then return 0; fi
    sleep "$POLL_INTERVAL"
    waited=$((waited + POLL_INTERVAL))
  done
  return 1
}

# Tests for the assertions above, with synthetic check results.
#
# TWO-94 was a wrong assertion, not a wrong pipeline, and it cost a full live run
# to find out: eight pull requests and forty minutes of runner time to learn that
# one line of bash expected something the design makes impossible. An assertion
# that is only ever exercised by the slowest thing we own gets exactly one review
# and then nobody looks again. These scenarios take no network and no runner, so
# the next wrong assertion is caught by `static` in half a second.
#
# Every scenario is a real conclusion set: the ordinary case, the case that
# motivated this file, and the ways each could be read wrong.
assert_selftest() {
  local rc=0 n=0 name expect job aggregate green raw out status

  # <name>|<ok|reject>|<expected job>|<aggregate expectation>|<expect_green>|<checks>
  local scenarios=(
    "ordinary-failure|ok|static|FAILURE|no|static=FAILURE;pest=SUCCESS;dusk=SUCCESS;budgets=SUCCESS;tests=FAILURE;gitleaks=SUCCESS"
    "aggregate-stayed-green|reject|static|FAILURE|no|static=FAILURE;pest=SUCCESS;dusk=SUCCESS;budgets=SUCCESS;tests=SUCCESS;gitleaks=SUCCESS"
    "aggregate-skipped-when-it-should-be-red|reject|static|FAILURE|no|static=FAILURE;pest=SUCCESS;dusk=SUCCESS;budgets=SUCCESS;tests=SKIPPED;gitleaks=SUCCESS"
    "wrong-job-went-red|reject|dusk|FAILURE|no|static=FAILURE;pest=SUCCESS;dusk=SUCCESS;budgets=SUCCESS;tests=FAILURE;gitleaks=SUCCESS"
    "nothing-red-at-all|reject|static|FAILURE|no|static=SUCCESS;pest=SUCCESS;dusk=SUCCESS;budgets=SUCCESS;tests=SUCCESS;gitleaks=SUCCESS"
    "nothing-reported|reject|static|FAILURE|no|"
    "one-check-never-arrived|reject|static|FAILURE|no|static=FAILURE;pest=SUCCESS;dusk=SUCCESS;budgets=SUCCESS;tests=FAILURE"
    # The observed conclusions from run 32324926996 — the gate holding, correctly.
    "gate-disarmed-aggregate-skipped|ok|static|NOT_SUCCESS|no|static=FAILURE;pest=SUCCESS;dusk=SUCCESS;budgets=SUCCESS;tests=SKIPPED;gitleaks=SUCCESS"
    # The aggregate ran and passed over a red need: a real guard defect, still caught.
    "gate-disarmed-aggregate-passed|reject|static|NOT_SUCCESS|no|static=FAILURE;pest=SUCCESS;dusk=SUCCESS;budgets=SUCCESS;tests=SUCCESS;gitleaks=SUCCESS"
    # NOT_SUCCESS must not become "anything goes": the lint job still has to go red.
    "gate-disarmed-lint-missed-it|reject|static|NOT_SUCCESS|no|static=SUCCESS;pest=SUCCESS;dusk=SUCCESS;budgets=SUCCESS;tests=SKIPPED;gitleaks=SUCCESS"
    # The secret scan holding: `gitleaks` red on a pull request where ci.yml is
    # entirely green, because `gitleaks` is in another workflow. This is the only
    # shape in which a green `${AGGREGATE}` is an acceptable answer.
    "secret-committed-gitleaks-red|ok|gitleaks|SUCCESS|no|static=SUCCESS;pest=SUCCESS;dusk=SUCCESS;budgets=SUCCESS;tests=SUCCESS;gitleaks=FAILURE"
    # And the failure this case exists for: the scan stopped catching anything.
    # Nothing else in the pipeline reddens on a committed credential, so a green
    # `gitleaks` here has to be rejected on its own.
    "secret-committed-nothing-caught-it|reject|gitleaks|SUCCESS|no|static=SUCCESS;pest=SUCCESS;dusk=SUCCESS;budgets=SUCCESS;tests=SUCCESS;gitleaks=SUCCESS"
    # SUCCESS must not become "the aggregate is not my problem". A breakage that
    # also took ci.yml down is not evidence about the secret scan, whatever else
    # it proves — the same reason break_dusk must stay invisible to `pest`.
    "secret-case-also-broke-ci|reject|gitleaks|SUCCESS|no|static=FAILURE;pest=SUCCESS;dusk=SUCCESS;budgets=SUCCESS;tests=FAILURE;gitleaks=FAILURE"
    "clean-all-green|ok|${AGGREGATE}|SUCCESS|yes|static=SUCCESS;pest=SUCCESS;dusk=SUCCESS;budgets=SUCCESS;tests=SUCCESS;gitleaks=SUCCESS"
    "clean-one-skipped|reject|${AGGREGATE}|SUCCESS|yes|static=SUCCESS;pest=SUCCESS;dusk=SKIPPED;budgets=SUCCESS;tests=SUCCESS;gitleaks=SUCCESS"
  )

  for entry in "${scenarios[@]}"; do
    IFS='|' read -r name expect job aggregate green raw <<< "$entry"
    n=$((n + 1))
    if out=$(assert_checks "$(tr ';' '\n' <<< "$raw")" "$job" "$aggregate" "$green" "$name" 2>&1); then
      status=ok
    else
      status=reject
    fi
    if [ "$status" = "$expect" ]; then
      pass "assert: ${name} -> ${expect}"
    else
      fail "assert: ${name} -> expected the assertion to ${expect}, it returned ${status}"
      printf '%s\n' "$out" | sed 's/^/        /'
      rc=1
    fi
  done

  # And the caveat this whole case turns on: NOT_SUCCESS is only safe while the job
  # that went red is itself required. Strip `static` from REQUIRED_CHECKS in a
  # subshell and the same conclusions must be rejected — because then nothing
  # required is red, `tests` is skipped, and GitHub reads skipped as passed.
  n=$((n + 1))
  if out=$(
    REQUIRED_CHECKS=(tests gitleaks)
    assert_checks "static=FAILURE
pest=SUCCESS
dusk=SUCCESS
budgets=SUCCESS
tests=SKIPPED
gitleaks=SUCCESS" static NOT_SUCCESS no "gate-disarmed-lint-job-not-required" 2>&1
  ); then
    fail "assert: gate-disarmed-lint-job-not-required -> the assertion passed. If the only red job is not a required check, nothing stops that PR."
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
  else
    pass "assert: gate-disarmed-lint-job-not-required -> reject"
  fi

  # The same caveat for the `secret` case, where it is the entire argument rather
  # than half of one. `gitleaks` is in no aggregate's `needs:`, so with it off the
  # required list there is nothing left: every required check on that pull request
  # is green and the credential merges. Strip it in a subshell and the identical
  # conclusions must be rejected.
  n=$((n + 1))
  if out=$(
    REQUIRED_CHECKS=(tests static pest dusk budgets)
    assert_checks "static=SUCCESS
pest=SUCCESS
dusk=SUCCESS
budgets=SUCCESS
tests=SUCCESS
gitleaks=FAILURE" gitleaks SUCCESS no "secret-committed-gitleaks-not-required" 2>&1
  ); then
    fail "assert: secret-committed-gitleaks-not-required -> the assertion passed. If \`gitleaks\` is not a required check, nothing on that pull request is red that anyone is blocking on, and the credential merges."
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
  else
    pass "assert: secret-committed-gitleaks-not-required -> reject"
  fi

  printf '\n'
  if [ "$rc" -ne 0 ]; then
    fail "the live-run assertions do not say what they claim. Fix them before spending forty minutes on --run."
  else
    printf '\033[1m%d/%d — the live-run assertions accept and reject the right conclusions.\033[0m\n' "$n" "$n"
  fi
  return "$rc"
}

# Tests for the wait, with synthetic check-run output.
#
# `wait_for_checks` had no test until TOG-328, and until TOG-328 it did not need
# one as badly: it read two conditions and the Actions half — "every workflow run
# on this branch has completed" — was doing most of the work. Check runs have no
# run-level status, so that half is gone and the EXPECTED_CHECKS half carries all
# of it. A wait that returns early is silent by construction: the assertions then
# read a branch mid-flight and report a check that never ran, on a branch where it
# was about to. That is the exact bug the Actions-era comment claimed to have
# closed, and nothing offline would have noticed it come back.
#
# `branch_checks` is shadowed by a shell function here, so no network, no
# repository and no SHA. POLL_INTERVAL and CHECK_TIMEOUT are shadowed too: each
# scenario is a fixed script of polls, and the wait must land on the right one.
wait_selftest() {
  local rc=0 n=0 name expect polls out status waited_polls counter
  counter=$(mktemp "${TMPDIR:-/tmp}/wait-selftest.XXXXXX")
  trap 'rm -f "$counter"' RETURN

  # Each scenario is a `;`-separated script of poll responses, one per call to
  # branch_checks, each a `,`-separated set of `name=STATE`. The wait must return
  # 0 on the poll named by <expect>, or return 1 if <expect> is `timeout`.
  #
  # <name>|<expect: poll index, 1-based | timeout>|<polls>
  local all='static=SUCCESS,pest=SUCCESS,dusk=SUCCESS,budgets=SUCCESS,tests=SUCCESS,gitleaks=SUCCESS'
  local scenarios=(
    # The ordinary case: everything settled on the first read.
    "all-settled-immediately|1|${all}"

    # The load-bearing one. secret-scan.yml has not created its check run yet, so
    # `gitleaks` is absent entirely while every ci.yml check is green and final.
    # The weak condition — "everything I can see is done" — returns here, and the
    # assertions then fail a `gitleaks` that was about to run and pass. It must
    # wait for the name.
    "absent-check-is-not-a-finished-branch|2|static=SUCCESS,pest=SUCCESS,dusk=SUCCESS,budgets=SUCCESS,tests=SUCCESS;${all}"

    # The condition the Actions run-status used to supply. Every expected name is
    # present, so presence alone would return — but one is still running. Reading
    # a PENDING check as a conclusion is how a half-finished pipeline gets reported
    # as a finished one.
    "pending-check-is-not-a-conclusion|2|static=SUCCESS,pest=SUCCESS,dusk=SUCCESS,budgets=SUCCESS,tests=PENDING,gitleaks=SUCCESS;${all}"

    # Both gaps at once, closing one poll at a time — the real shape of a branch
    # coming up: checks appear pending, conclude, and the second workflow arrives
    # last.
    "checks-arrive-and-conclude-over-several-polls|4|static=PENDING;static=SUCCESS,pest=PENDING,dusk=PENDING,budgets=PENDING,tests=PENDING;static=SUCCESS,pest=SUCCESS,dusk=SUCCESS,budgets=SUCCESS,tests=PENDING;${all}"

    # A red branch is a finished branch. Nine of the ten cases end here, so a wait
    # that only accepts SUCCESS would time out on every one of them and report the
    # gate as dead while it is working.
    "failure-is-a-conclusion|1|static=FAILURE,pest=SUCCESS,dusk=SUCCESS,budgets=SUCCESS,tests=FAILURE,gitleaks=SUCCESS"

    # And SKIPPED, which is what the `gate` case produces for the aggregate: the
    # breakage deletes `if: always()`, so `tests` never runs. Skipped is a
    # conclusion; waiting for it to become something else waits forever.
    "skipped-is-a-conclusion|1|static=FAILURE,pest=SUCCESS,dusk=SUCCESS,budgets=SUCCESS,tests=SKIPPED,gitleaks=SUCCESS"

    # A check nobody asked about must not hold the wait up. A new job in ci.yml is
    # for check 7 in lint() to complain about, not for this to block on.
    "unexpected-pending-check-is-not-waited-on|1|${all},typos=PENDING"

    # A branch whose push never landed reports nothing, forever. It has to time out
    # rather than return, because returning would hand assert_checks an empty set
    # that reads identically to a branch that was gated and passed. This is the
    # TOG-20 `workflows` shape: one case silently missing from a run of ten.
    "nothing-ever-reports|timeout|;;;"

    # And the stall: checks exist, they simply never finish. Same requirement.
    "never-concludes|timeout|static=PENDING,pest=PENDING,dusk=PENDING,budgets=PENDING,tests=PENDING,gitleaks=PENDING;;;"
  )

  for entry in "${scenarios[@]}"; do
    IFS='|' read -r name expect polls <<< "$entry"
    n=$((n + 1))

    # The poll counter lives in a file, not a variable. `wait_for_checks` reads
    # branch_checks through a command substitution, so the stub runs in a subshell
    # and an incremented variable dies with it — which reads as a stub that was
    # never called.
    : > "$counter"
    out=$(
      # Four polls per scenario at most: CHECK_TIMEOUT/POLL_INTERVAL. `sleep` is
      # shadowed to nothing, so a timeout costs no wall clock.
      POLL_INTERVAL=1
      CHECK_TIMEOUT=4
      SCRIPT="$polls"
      COUNTER="$counter"
      sleep() { :; }
      branch_checks() {
        local n
        printf 'x' >> "$COUNTER"
        n=$(wc -c < "$COUNTER" | tr -d ' ')
        awk -v n="$n" -v s="$SCRIPT" 'BEGIN{
          m = split(s, poll, ";")
          if (n > m) { exit }
          split(poll[n], one, ",")
          for (i = 1; i in one; i++) if (one[i] != "") print one[i]
        }'
      }
      wait_for_checks "ci-verify/selftest"
      printf 'status=%s\n' "$?"
    )
    status=${out#status=}
    waited_polls=$(wc -c < "$counter" | tr -d ' ')

    if [ "$expect" = timeout ]; then
      if [ "$status" = 0 ]; then
        fail "wait: ${name} -> returned on poll ${waited_polls}. A wait that gives up quietly hands the assertions an empty result, which reads exactly like a branch that was gated and passed."
        rc=1
      else
        pass "wait: ${name} -> timed out, as it must"
      fi
      continue
    fi

    if [ "$status" != 0 ]; then
      fail "wait: ${name} -> timed out. It should have returned on poll ${expect}; the branch was settled by then and the run now reports a dead pipeline that is not dead."
      rc=1
    elif [ "$waited_polls" != "$expect" ]; then
      fail "wait: ${name} -> returned on poll ${waited_polls}, expected poll ${expect}. Too early means the assertions read a branch mid-flight and fail a check that was about to pass; too late is forty minutes of nothing."
      rc=1
    else
      pass "wait: ${name} -> returned on poll ${expect}"
    fi
  done

  printf '\n'
  if [ "$rc" -ne 0 ]; then
    fail "the wait does not wait for what it claims. --run would assert on half-finished branches."
  else
    printf '\033[1m%d/%d — the wait returns exactly when the branch is settled.\033[0m\n' "$n" "$n"
  fi
  return "$rc"
}

# Cleanup has the same failure mode as the assertions and had it in the worst
# form: it reported nine pull requests closed while closing none. `gh` and `git`
# are shadowed by shell functions here, so the real cleanup() runs against
# synthetic responses — no network, no repository, no pull requests.
cleanup_selftest() {
  local rc=0 n=0 out

  # The stubs below answer two different `gh pr list` calls: the one cleanup()
  # closes from, and the re-read it checks itself against afterwards. They are told
  # apart by the re-read's `startswith` filter, so a cleanup() that stops doing one
  # of them stops being fed by these stubs and the case it belongs to goes red —
  # which is the point. Keep the filters and these patterns in step (TWO-109).

  # Every close succeeds and the follow-up read finds nothing open. The only
  # case that may pass.
  n=$((n + 1))
  if out=$(
    gh() {
      if [ "$2" = "list" ]; then
        case "$*" in
          *startswith*) ;;                              # final sweep finds none
          *) printf '101\tci-verify/pint\n' ;;          # one open to close
        esac
        return 0
      fi
      return 0
    }
    git() { return 1; }
    cleanup 2>&1
  ); then
    pass "cleanup: every pull request closed -> accept"
  else
    fail "cleanup: every pull request closed -> reported a leftover that does not exist"
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
  fi

  # Every close fails. This is the TWO-22 acceptance run: it must not report
  # them closed, and it must not exit 0.
  n=$((n + 1))
  out=$(
    gh() {
      if [ "$2" = "list" ]; then
        case "$*" in
          *startswith*) echo "#102 ci-verify/lcp" ;;
          *)            printf '102\tci-verify/lcp\n' ;;
        esac
        return 0
      fi
      [ "$2" = "close" ] && return 1
      return 0
    }
    git() { return 1; }
    cleanup 2>&1
  ) && cleanup_rc=0 || cleanup_rc=$?
  if [ "$cleanup_rc" -eq 0 ]; then
    fail "cleanup: every close failed -> it exited 0. That is the TWO-94 bug: nine reported closed, three still open."
    rc=1
  elif has_line "$out" 'closed pull request #'; then
    fail "cleanup: every close failed -> it still printed 'closed pull request #'. The line has to track the close, not the loop."
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
  else
    pass "cleanup: every close failed -> reject"
  fi

  # The liar: `gh pr close` exits 0 and the pull request is still open anyway.
  # Only the re-read catches this one, which is why it is not optional.
  n=$((n + 1))
  if out=$(
    gh() {
      if [ "$2" = "list" ]; then
        case "$*" in
          *startswith*) echo "#103 ci-verify/gate" ;;
          *)            printf '103\tci-verify/gate\n' ;;
        esac
        return 0
      fi
      return 0   # close claims success
    }
    git() { return 1; }
    cleanup 2>&1
  ); then
    fail "cleanup: close exits 0 but the pull request survives -> it accepted. Trusting the exit code over the live list is the whole bug."
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
  else
    pass "cleanup: close reports success but the pull request survives -> reject"
  fi

  printf '\n'
  if [ "$rc" -ne 0 ]; then
    fail "cleanup does not do what it reports. It is the last thing that runs and nothing downstream checks it."
  else
    printf '\033[1m%d/%d — cleanup reports what it actually did.\033[0m\n' "$n" "$n"
  fi
  return "$rc"
}

# --- the breakages ----------------------------------------------------------
# Each writes exactly one deliberate defect into the working tree. Deterministic on
# purpose: a verification that itself flakes teaches nothing.
#
# Each also names the paths it touches in TOUCHED, and open_pr stages those and
# nothing else. `git add -A` would stage whatever happened to be in the tree —
# including a file some other process wrote while this script was mid-run — and a
# "deliberately broken" pull request carrying an unrelated change proves nothing
# about which change reddened the job. Naming the paths also gives open_pr something
# to check the result against: a sed whose anchor has moved leaves those paths
# unchanged, and the case that used to prove something quietly stops.

break_pint() {
  TOUCHED=(app/CiVerifyBadFormatting.php)
  # Laravel preset wants braces, spacing and a trailing newline. This has none.
  cat > app/CiVerifyBadFormatting.php <<'PHP'
<?php
namespace App;
class CiVerifyBadFormatting {
    public function thing( $a,$b ) { if($a){return $b;}
return null; }
}
PHP
}

break_phpstan() {
  TOUCHED=(app/CiVerifyTypeError.php)
  # Declared to return string, returns int. Level 8 catches this immediately.
  cat > app/CiVerifyTypeError.php <<'PHP'
<?php

namespace App;

class CiVerifyTypeError
{
    public function name(): string
    {
        return 42;
    }
}
PHP
}

break_pest() {
  TOUCHED=(tests/Feature/CiVerifyFailingTest.php)
  cat > tests/Feature/CiVerifyFailingTest.php <<'PHP'
<?php

it('is deliberately broken to prove CI catches a failing test', function () {
    expect(1)->toBe(2);
});
PHP
}

break_tokens() {
  TOUCHED=(resources/css/two.css)
  # One hex nudged in the vendored design system. Nothing in this repo checks
  # contrast — two-design does, and that guarantee only holds while our copy is
  # byte-identical. Without the digest test this is completely silent: the page
  # renders, the suite passes, and the site is quietly less accessible than the
  # design system says it is.
  sed -i '0,/#9e96b5/s//#6a6480/' resources/css/two.css
}

break_dusk() {
  TOUCHED=(resources/views/home.blade.php)
  # Hide the heading with CSS. The HTML still contains the text, so the *feature*
  # test's assertSee passes and only the real browser notices it is invisible —
  # which is the whole reason we pay for Dusk. A breakage that also trips `tests`
  # would prove nothing about the browser job.
  sed -i 's#</x-layouts.app>#    <style>h1 { display: none; }</style>\n</x-layouts.app>#' resources/views/home.blade.php
}

break_a11y() {
  TOUCHED=(resources/views/home.blade.php)
  # An image with no alt text. wcag2a `image-alt` — a real barrier, and a rule axe
  # detects with total reliability. Invisible to every other job.
  sed -i 's#</x-layouts.app>#    <img src="/favicon.ico" width="16" height="16">\n</x-layouts.app>#' resources/views/home.blade.php
}

break_slowserver() {
  TOUCHED=(resources/views/home.blade.php)
  # Three seconds of server think-time before anything can paint. A real member on
  # a real phone waits three seconds; the budget has to say so.
  #
  # What catches it is `server-response-time`, not `largest-contentful-paint`, and
  # that is worth knowing before you go looking. This case ran green for a while
  # (TWO-93): `simulate` throttling does not report observed timings, it rebuilds
  # them, and Lantern models one server response time per origin — the median over
  # every request to it. The document's three seconds sits in a set with four
  # static files served off disk in a millisecond, the median is a millisecond, and
  # Lantern then simulates the document at a millisecond too. Simulated LCP comes
  # in well under 2.0s over a server that took three seconds to answer.
  #
  # In the view rather than as a closure route on purpose: the budgets job runs
  # `route:cache`, and a closure route is not serialisable, so that version would
  # fail the job at the wrong step and look like a pass.
  sed -i '1i @php usleep(3000000); @endphp' resources/views/home.blade.php
}

break_secret() {
  TOUCHED=(ci-verify-credential.txt)
  # A credential-shaped string in a tracked file. `gitleaks` is a required check on
  # `main` and until now no case here made it go red — the one required check with
  # no live proof it fails, guarding the one thing that cannot be undone by a
  # revert (TOG-20). An install step that fails open, an allowlist that grew too
  # wide, a rule that changed on a version bump: all three leave the job green and
  # nothing else in the pipeline is looking.
  #
  # The value is fake and matches OUR rule, `discord-bot-token` in .gitleaks.toml,
  # not one of gitleaks' stock patterns. That is deliberate and it is the stronger
  # of the two: verified against the pinned 8.30.1, this string is NOT a finding
  # under the default rule set alone, so a run in which .gitleaks.toml stopped
  # being read — renamed, unparsed, or allowlisted into silence — goes green here
  # and the case catches it. A stock AWS key would have gone red in that run and
  # told us nothing.
  #
  # What that trade costs, said plainly: this case does not prove `useDefault =
  # true` is still on. One check-run conclusion is one bit, so a PR that trips both
  # rule sources cannot tell you which one fired, and the repo-specific rules are
  # the half with no other coverage anywhere.
  #
  # A root-level text file on purpose: no other job reads it, so `tests` stays
  # green and this case remains evidence about the secret scan and nothing else.
  # Do not move it under app/, config/ or resources/ — pint, phpstan and the build
  # all glob those, and a `secret` case that also reddens `static` proves nothing.
  #
  # The value is assembled from three pieces instead of written out as one string,
  # and that is load-bearing rather than clever. `gitleaks git .` reads this
  # repository's entire history, and this file is in it: a credential-shaped
  # literal sitting here is a finding in `ci/verify-pipeline.sh` itself. The pull
  # request adding this case would fail the very check the case exists to prove,
  # and after a merge every pull request against `main` would fail it forever —
  # the string would be in the history, and history is what the scan reads.
  # Deleting the line later would not help, for the same reason.
  #
  # The other way out is an allowlist entry, and widening the allowlist to make
  # room for a secret-scan test is precisely the trade .gitleaks.toml tells you not
  # to make. Split the string and there is nothing to allowlist. The separators are
  # what the `discord-bot-token` regex matches on, so with the dots supplied by
  # printf at runtime no arrangement of these three fragments is a finding here.
  #
  # If someone later inlines it for readability, the `gitleaks` job on that pull
  # request goes red and says which file. That is the check working, not a
  # nuisance — so re-split it, do not allowlist it.
  local seg1='NotARealTokenCiVerifyFake'
  local seg2='TOG020'
  local seg3='ThisIsNotACredentialCiVerify'
  {
    printf '# Written by ci/verify-pipeline.sh to prove the secret scan rejects a credential.\n'
    printf '# This value is fake. It is shaped to match the `discord-bot-token` rule in\n'
    printf '# .gitleaks.toml and has never been a live token for anything.\n'
    printf 'DISCORD_BOT_TOKEN=%s.%s.%s\n' "$seg1" "$seg2" "$seg3"
  } > ci-verify-credential.txt
}

break_lcp() {
  TOUCHED=(public/ci-verify-hero.bmp resources/views/home.blade.php)
  # An oversized hero image above the fold. This is the case that actually exercises
  # the CEO's LCP < 2.0s budget, and it exists because `slowserver` above does not:
  # what reddens `budgets` there is `server-response-time`. Without this case the
  # headline budget has no live proof that it fires at all, and a broken
  # `largest-contentful-paint` assertion would be invisible to every job in the
  # pipeline (TWO-101, finding 2).
  #
  # Measured, not assumed — same Lighthouse settings as ci/lighthouserc.cjs
  # (mobile, simulate, 1474.56 kbps down), varying only the image:
  #
  #     no image      LCP  752ms
  #     148 KB image  LCP 1653ms   under budget
  #     1.6 MB image  LCP 9152ms   4.6x over the 2.0s budget
  #
  # with CLS 0 and server-response-time 2ms in every case, so LCP is the only
  # assertion that goes red and the case proves the thing it is named for. The
  # simulator charges ~9s to pull 1.6 MB over Slow 4G and the largest contentful
  # element cannot render until it lands (95% of LCP is Render Delay). Deterministic
  # because it is simulated from the byte count, not measured off the runner's clock.
  #
  # An uncompressed BMP of random bytes, built by hand: it needs no image tooling on
  # whoever's machine runs this, and it cannot be squeezed by transport compression
  # on the way, so the size in the header is the size on the wire. Realistic, too —
  # a hero image nobody compressed is how LCP actually breaches on a real site, and
  # TWO-28 is about to put real screenshots on this page.
  #
  # 1.6 MB of /dev/urandom is also exactly what an entropy-based secret scanner is
  # built to notice, and this case going red on `gitleaks` instead of `budgets`
  # would be a false result rather than a caught one (QA, TWO-111). So it was
  # measured, not argued: gitleaks 8.30.1 — the version secret-scan.yml pins — run
  # the way that workflow runs it, over a commit of this exact file with this
  # repo's .gitleaks.toml, reports `no leaks found`, exit 0.
  #
  # Not luck, either. `.gitattributes` sets `text=auto`, and bytes 6..9 of the
  # header are a `le32 0` — four NULs — so git calls the blob binary whatever the
  # random payload happens to spell, and writes `Binary files ... differ` into the
  # patch instead of content. `gitleaks git` reads that patch, so it never sees a
  # byte of the payload and no rule of any kind can fire on it. The only thing that
  # would change that is forcing this path to diff as text in .gitattributes.
  local w=900 h=620 stride data size
  stride=$(( (w * 3 + 3) / 4 * 4 ))
  data=$(( stride * h ))
  size=$(( 54 + data ))
  b()    { printf "$(printf '\\x%02x' "$1")"; }
  le16() { b $(( $1 & 255 )); b $(( ($1 >> 8) & 255 )); }
  le32() { b $(( $1 & 255 )); b $(( ($1 >> 8) & 255 )); b $(( ($1 >> 16) & 255 )); b $(( ($1 >> 24) & 255 )); }
  {
    printf 'BM'; le32 "$size"; le32 0; le32 54          # BITMAPFILEHEADER
    le32 40; le32 "$w"; le32 "$h"; le16 1; le16 24      # BITMAPINFOHEADER, 24bpp
    le32 0; le32 "$data"; le32 2835; le32 2835; le32 0; le32 0
  } > public/ci-verify-hero.bmp
  head -c "$data" /dev/urandom >> public/ci-verify-hero.bmp

  # Above the first contentful element, not appended at the end of the page like the
  # other breakages. An image below the fold is lazy-loadable and would not touch
  # LCP at all, so anchoring on `</x-layouts.app>` would produce a case that quietly
  # stops breaching the moment this page gets longer. Fail loudly if the anchor is
  # gone rather than opening a PR that is not broken.
  grep -q '<h1>' resources/views/home.blade.php || {
    fail "break_lcp: no <h1> in resources/views/home.blade.php to place the hero above. The anchor moved; fix this case rather than deleting it."
    return 1
  }
  sed -i '0,/<h1>/s##<img src="/ci-verify-hero.bmp" width="900" height="620" alt="A deliberately oversized hero image">\n    <h1>#' resources/views/home.blade.php
}

break_gate() {
  TOUCHED=("$WORKFLOW")
  # Delete the aggregate's `if: always()`. Every job still passes, every test still
  # passes, and the pipeline stops being a gate: a red `static` now *skips* `tests`,
  # and GitHub counts a skipped required check as a passed one. Nothing else in the
  # suite notices — this is the failure mode that has no symptom until the day a
  # broken PR merges clean. Caught by `--lint`, which the `static` job runs first.
  sed -i '/^    if: always()$/d' .github/workflows/ci.yml
}

# --- driver -----------------------------------------------------------------

# Delete this run's branches and close its pull requests — and, because the `--run`
# guard below names this command as the way out, clear everything that guard
# refuses on rather than everything this checkout happens to know about.
#
# Those were not the same set. The guard blocks on any `ci-verify/*` ref, by glob.
# This used to iterate CASES plus `clean` and delete those nine fixed names, so a
# `ci-verify/*` branch whose slug is not in CASES — a case renamed or dropped since
# the run that died, an older checkout of this script, one made by hand — was
# blocked on forever and cleaned never: `--cleanup` printed a header, exited 0 and
# changed nothing, and the only way out was knowing to `git push origin --delete`
# by hand (TWO-109). Both ends now read the same two probes, so the guard and its
# recovery agree by construction instead of by both being remembered.
#
# The other half of the promise is TWO-94's: the header says this closes every pull
# request it opens, and it used to say so in the output too whatever happened —
# every `gh` call was `|| true` and the "closed" line printed unconditionally, so a
# run in which every close failed was indistinguishable from a clean one. The TWO-22
# acceptance run left three pull requests open and reported nine closed. So the
# result is read from the close, and then the whole thing is checked again against
# the live list: `gh pr close` exiting 0 and the pull request being closed are two
# different facts, and this file's entire premise is that those drift.
#
# This gets used. The script has no `trap`, so a Ctrl-C, a dropped connection or a
# CI timeout leaves branches behind with no pull request attached — the killed-run
# path is the normal way a run ends badly.
cleanup() {
  log "Cleaning up"
  local entry slug prs branches number head sha ref name survivors removed=0
  local rc=0

  # Local branches first. They are on no remote, nothing enumerates them, and the
  # fixed names are the only handle there is. Not counted as removals: nothing
  # outside this checkout can see them, and the guard does not look here.
  for entry in "${CASES[@]}" "clean|tests|the happy path"; do
    slug="${entry%%|*}"
    git branch -D "${BRANCH_PREFIX}/${slug}" >/dev/null 2>&1 || true
  done

  # Then the two things `--run` refuses on, read the same way it reads them. Pull
  # requests first: closing one with --delete-branch takes its branch with it, so
  # the sweep below is left with exactly the branches that have no pull request.
  prs=$(gh pr list --state open --limit 100 \
          --json number,headRefName --jq '.[] | "\(.number)\t\(.headRefName)"' 2>/dev/null || true)
  while IFS=$'\t' read -r number head; do
    case "${head:-}" in "${BRANCH_PREFIX}/"*) ;; *) continue ;; esac
    if gh pr close "$number" --delete-branch --comment "CI verification run finished." >/dev/null 2>&1; then
      printf '  closed pull request #%s (%s)\n' "$number" "$head"
      removed=$((removed + 1))
    else
      fail "could not close pull request #$number ($head)"
      rc=1
    fi
  done <<< "$prs"

  branches=$(git ls-remote --heads origin "refs/heads/${BRANCH_PREFIX}/*" 2>/dev/null || true)
  while read -r sha ref; do
    [ -n "${ref:-}" ] || continue
    name="${ref#refs/heads/}"
    if git push origin --delete "$name" >/dev/null 2>&1; then
      printf '  deleted branch %s on origin\n' "$name"
      removed=$((removed + 1))
    else
      printf '  could not delete branch %s on origin\n' "$name"
      rc=1
    fi
  done <<< "$branches"

  # Re-read rather than trust the loop. This also catches pull requests an earlier
  # interrupted run left behind, which is what "closes every PR it opens" has to
  # mean if the promise is worth anything.
  survivors=$(gh pr list --state open --limit 100 --json number,headRefName \
    --jq ".[] | select(.headRefName | startswith(\"${BRANCH_PREFIX}/\")) | \"#\(.number) \(.headRefName)\"" \
    2>/dev/null || true)

  if [ -n "$survivors" ]; then
    fail "these ${BRANCH_PREFIX}/* pull requests are still open against ${BASE_BRANCH}:"
    printf '%s\n' "$survivors" | sed 's/^/        /'
    fail "close them, or re-run: ./ci/verify-pipeline.sh --cleanup"
    rc=1
  fi

  # Say which of the two happened. One header and exit 0 read the same whether nine
  # branches went or none did, which is how the no-op above stayed invisible.
  if [ "$rc" -eq 0 ] && [ "$removed" -eq 0 ]; then
    echo "  nothing to clean"
  fi
  return "$rc"
}

if [ "$MODE" = "--cleanup" ]; then
  cleanup || {
    fail "some ${BRANCH_PREFIX}/* refs are still there — see above. \`--run\` will refuse to start while they are."
    exit 1
  }
  exit 0
fi

if [ "$MODE" = "--assert-selftest" ]; then
  selftest_rc=0
  log "The live-run assertions, against synthetic check results (no network)"
  assert_selftest || selftest_rc=1
  log "The wait for those results, against synthetic polls (no network)"
  wait_selftest || selftest_rc=1
  log "Cleanup, against synthetic gh responses (no network)"
  cleanup_selftest || selftest_rc=1
  exit "$selftest_rc"
fi

if [ "$MODE" = "--lint" ]; then
  log "Static checks on the gate (no network)"
  lint || { fail "the gate would report green while not gating. Fix ${WORKFLOW} before anything is merged behind it."; exit 1; }
  log "Gate wiring is sound."
  exit 0
fi

if [ "$MODE" != "--run" ]; then
  log "Static checks on the gate (no network)"
  lint || { fail "the gate would report green while not gating. Fix ${WORKFLOW} before anything is merged behind it."; exit 1; }
  log "Dry run. Nothing will be pushed. Re-run with --run to execute."
  for entry in "${CASES[@]}"; do
    IFS='|' read -r slug job aggregate why <<< "$entry"
    printf '  %-10s -> expects %-8s red, and %s %-11s : %s\n' \
      "$slug" "$job" "${AGGREGATE}" "$aggregate" "$why"
  done
  printf '  %-10s -> expects %-8s red, and %s %-11s : %s\n' \
    "clean" "nothing" "${AGGREGATE}" "SUCCESS" "a clean PR goes green"
  exit 0
fi

# Before ten pull requests and forty minutes of runner time, half a second of
# reading the file.
log "Static checks on the gate"
lint || { fail "the gate is misconfigured. Fix ${WORKFLOW} first — the live run would only tell you the same thing, slower."; exit 1; }

# This has to be first, before the dirty-tree check, because `--run` does its work
# by checking out branches in whatever repository it is standing in — and in an
# agent workspace that repository belongs to every run of that agent, not to this
# one. Two runs overlap: one heartbeat can still be finishing while the next has
# started. A `git checkout -B` here is a write to someone else's working tree, and
# unlike a `reset --hard` on committed work it leaves no reflog entry to recover
# from (TWO-112; observed live on 2026-08-20, both times inside one agent's own
# workspace).
#
# It also has to come before the dirty-tree check specifically, or an operator in a
# shared checkout is told "commit or stash first" — advice that would have them
# commit another run's half-finished work onto a ci-verify branch and push it.
#
# `ci/scratch-clone.sh` makes a run-private clone and stamps its run id into
# `paperclip.runScratch`. Outside Paperclip there is no run to own anything, so
# PAPERCLIP_RUN_ID is unset and this guard does not apply — a laptop or a CI runner
# has its own checkout by definition.
if [ -n "${PAPERCLIP_RUN_ID:-}" ]; then
  SCRATCH_OWNER=$(git config --get paperclip.runScratch 2>/dev/null || true)
  if [ -z "$SCRATCH_OWNER" ]; then
    fail "this is a shared workspace checkout, not a scratch clone owned by run ${PAPERCLIP_RUN_ID}.
\`--run\` checks out and commits on ten branches in the repository it is standing
in. Another run of this agent shares this directory and can be mid-edit in it right
now; uncommitted work it destroys is not in any reflog. Work in a clone of your own:

  cd \"\$(./ci/scratch-clone.sh)\" && ./ci/verify-pipeline.sh --run

Nothing has been pushed."
    exit 1
  fi
  if [ "$SCRATCH_OWNER" != "$PAPERCLIP_RUN_ID" ]; then
    fail "this scratch clone belongs to run ${SCRATCH_OWNER}, not to run ${PAPERCLIP_RUN_ID}.
Either that run is still going — in which case this is its working tree and the
same collision applies — or it ended and Paperclip has not yet removed the
directory, in which case its object store may be pruned out from under you.
Make your own with \`./ci/scratch-clone.sh\`. Nothing has been pushed."
    exit 1
  fi
  pass "working in this run's own scratch clone"
fi

command -v gh >/dev/null || { fail "gh is not installed"; exit 1; }
gh auth status >/dev/null 2>&1 || { fail "gh is not authenticated"; exit 1; }
[ -z "$(git status --porcelain)" ] || { fail "working tree is dirty — commit or stash first"; exit 1; }

# open_pr() commits ten times, and `git commit` needs an identity it has not needed
# up to this point. Checked here rather than trusted to `set -e`: open_pr() is
# always called as `branch=$(open_pr ...)`, and a command substitution subshell
# starts with errexit OFF regardless of the outer shell's `-e` — that is bash's
# behaviour, not a bug in this file, and it is why a failed `git commit` used to
# fall through to `git push` instead of stopping the case (TOG-466). `git var`
# is what `git commit` itself calls to resolve the author line, so this fails for
# exactly the same reason `git commit` would, before anything is pushed.
git var GIT_AUTHOR_IDENT >/dev/null 2>&1 || {
  fail "git identity is not set — run: git config user.email you@example.com && git config user.name \"Your Name\" (add --global for every repo). Every case below commits on its own branch, and a commit with no identity fails while the push and PR-open around it do not, which is how this leaves ${BRANCH_PREFIX}/* branches with nothing in them. Nothing has been pushed."
  exit 1
}

# The owner/repo the Checks API is asked about. Resolved and checked here, in the
# main shell, rather than inside the assertions: every use of it below sits in a
# command substitution, and an `exit` in there kills only the subshell and lets the
# run carry on with an empty value. That is the swallow this section exists to have
# stopped doing, so it must not be reintroduced one level down.
repo_slug() {
  git config --get remote.origin.url \
    | sed -E 's#^(git@github\.com:|https://([^@/]+@)?github\.com/)##; s#/+$##; s#\.git$##'
}
REPO_SLUG=$(repo_slug || true)
[[ "$REPO_SLUG" =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$ ]] || {
  fail "origin is not a GitHub repository: '$(git config --get remote.origin.url 2>/dev/null || echo unset)'. Results come from the GitHub Checks API, so this has to run in a clone whose origin is GitHub — not a workspace clone and not a local mirror. Nothing has been pushed."
  exit 1
}

# Prove the credential can read results *before* opening ten pull requests. A
# token with push access but without `Checks: read` gets all the way through the
# run and then reads nothing back, which is indistinguishable from a pipeline that
# never ran — and the two diagnoses point in opposite directions. Half a second
# here, or forty minutes and the wrong answer there.
#
# Asked about `$BASE_BRANCH` because nothing of this run's has been pushed yet, and
# because it is the one ref name in this script guaranteed to contain no `/` — the
# ci-verify/* branches do, and a ref with a slash in this path has to be spelled
# `heads/<name>` for GitHub's router to find the trailing `/check-runs`. An empty
# `check_runs` array is a fine answer here: this probes the *permission*, and 200
# with nothing in it is a 200. Only the HTTP status is read.
gh api "repos/${REPO_SLUG}/commits/${BASE_BRANCH}/check-runs?per_page=1" >/dev/null 2>&1 || {
  fail "cannot read the Checks API on ${REPO_SLUG}. The token needs 'Checks: read' — see the permissions listed at the top of this file. Nothing has been pushed."
  exit 1
}

# Nothing else may already be mid-run. Every case gets a fixed branch name —
# `ci-verify/pint`, `ci-verify/gate` — so two `--run` executions against one repo
# share branches. The second push lands on the first run's branch and silently
# changes its pull request's head commit mid-flight, and whichever `cleanup()`
# reaches the end first closes and deletes *both* runs' work. The survivor is then
# told its checks never reported, on a branch that no longer exists, which reads
# as a dead pipeline and is not one — the same wrong diagnosis the Checks API
# check directly above just stopped this script handing out. Observed live on
# 2026-08-20: nine ci-verify pull requests opened and closed under another run
# while a flake sample was being collected (TWO-103).
#
# Per-run branch names would let both runs proceed. That is deliberately not what
# this does: two people running the acceptance suite against one repo at once is a
# thing to notice, not a thing to support. So refuse in the first second, like the
# other preconditions, and name what is already there.
#
# A bare remote branch counts as much as an open pull request. A run killed
# part-way through leaves branches behind with no PR, and the next run's
# `--force-with-lease` push collides with those just as hard.
#
# The two probes are read here rather than inside the function, because whether
# each one *answered* matters as much as what it said. An unreadable probe and a
# probe that found nothing produce the same empty string, and the difference
# between them is the difference between a checked claim and a guess.
PR_PROBE=ok
PRS=$(gh pr list --repo "$REPO_SLUG" --state open --limit 100 \
        --json number,headRefName --jq '.[] | "\(.number)\t\(.headRefName)"' 2>/dev/null) || PR_PROBE=unreadable

BRANCH_PROBE=ok
BRANCHES=$(git ls-remote --heads origin "refs/heads/${BRANCH_PREFIX}/*" 2>/dev/null) || BRANCH_PROBE=unreadable

live_run() {
  local number head sha ref
  while IFS=$'\t' read -r number head; do
    case "${head:-}" in
      "${BRANCH_PREFIX}/"*) printf '  pull request #%s on %s\n' "$number" "$head" ;;
    esac
  done <<< "$PRS"

  while read -r sha ref; do
    [ -n "${ref:-}" ] || continue
    printf '  branch %s on origin\n' "${ref#refs/heads/}"
  done <<< "$BRANCHES"
}

LIVE_RUN=$(live_run || true)
if [ -n "$LIVE_RUN" ]; then
  fail "another verification run is already live on ${REPO_SLUG}:
${LIVE_RUN}
Both runs use these same branch names, so they would overwrite and then delete
each other's pull requests, and the one left standing would report checks that
never arrived. Wait for it to finish. If you know it is dead, clear it with
\`./ci/verify-pipeline.sh --cleanup\` and start again. Nothing has been pushed."
  exit 1
fi

if [ "$PR_PROBE" = ok ] && [ "$BRANCH_PROBE" = ok ]; then
  pass "no other verification run is live on ${REPO_SLUG}"
elif [ "$BRANCH_PROBE" = ok ]; then
  # `gh pr list --json` prints nothing and exits non-zero when the API will not
  # answer, which is byte-for-byte what an empty list looks like. Starting anyway
  # is the right call: open_pr() pushes the branch *before* it opens the pull
  # request, so every live run has a ci-verify/* branch too, and the ls-remote
  # half — different transport, different credential — just looked for exactly
  # that and found none. What is not right is printing PASS, which is a positive
  # claim about a probe that was never read.
  printf '\033[33mNOTE: could not read the open pull requests on %s. No %s/* branch is on origin either way, and a live run would have one, so this run is starting on that evidence alone.\033[0m\n' \
    "$REPO_SLUG" "$BRANCH_PREFIX"
else
  # The other half is gone too, so there is nothing left to fail open onto — and
  # `git ls-remote` is the same transport every push in this script uses, so the
  # fetch on the next line would die regardless. Refuse here, where the message is
  # about the thing that is actually wrong.
  fail "cannot read the branches on origin: \`git ls-remote\` failed, so whether another verification run is live is unknown — and so is whether this run could push at all. Nothing has been pushed."
  exit 1
fi

# `--prune` is not decoration. Every push below is `--force-with-lease`, which holds
# the lease against the local `refs/remotes/origin/ci-verify/*`. A previous run's
# cleanup deletes those branches on the remote but leaves the remote-tracking refs
# here, so the lease is held against a branch that no longer exists and every push
# is rejected with "stale info" — a whole run wasted, and the error names neither
# the cause nor the fix.
#
# It has to be a bare `git fetch origin --prune`. Adding `--prune` to a fetch with
# an explicit refspec prunes only within that refspec, so `git fetch origin main
# --prune` leaves every stale `ci-verify/*` ref exactly where it was.
git fetch origin --prune --quiet
results=()

open_pr() {
  local slug="$1" title="$2"
  local branch="${BRANCH_PREFIX}/${slug}"
  git checkout -q -B "$branch" "origin/${BASE_BRANCH}"

  # A breakage that fails is not a breakage that got skipped. break_lcp checks the
  # anchor it seds against and says so when it has moved — under the old
  # `2>/dev/null || true` that message went to /dev/null and the script opened a
  # pull request that was not broken, which reads as the pipeline failing to catch
  # something. Let it speak and let it stop the run.
  TOUCHED=()
  "break_${slug}" || { fail "break_${slug} could not apply its breakage. Fix the case; a pull request opened from here would prove nothing."; exit 1; }

  # Stage what the case declared, then check the tree agrees. Two failure modes,
  # both of which produce a green-looking run that verified nothing: a sed whose
  # anchor moved changes no file, and a concurrent write puts someone else's edit
  # in a commit whose message says it is one deliberate defect.
  git add -- "${TOUCHED[@]}"
  [ -n "$(git diff --cached --name-only)" ] || { fail "break_${slug} changed nothing under ${TOUCHED[*]}. The case is a no-op — an anchor it edits has probably moved."; exit 1; }
  local stray
  stray=$(git status --porcelain --untracked-files=all | grep -E '^(\?\?| M| D)' || true)
  [ -z "$stray" ] || { fail "break_${slug} left changes outside ${TOUCHED[*]}: $(tr '\n' ';' <<< "$stray"). Something else is writing to this working tree. Stop rather than push a commit that says it is one deliberate defect and is not."; exit 1; }

  # Checked explicitly rather than left to `set -e`: open_pr() is always called as
  # `branch=$(open_pr ...)`, and bash starts a command-substitution subshell with
  # errexit OFF regardless of the outer shell's `-e` — only the subshell's *last*
  # command decides whether the assignment fails. `git commit` here is not the last
  # command, `git push` and `gh pr create` are, so a failed commit used to fall
  # through and push the branch open_pr() had already checked out, unchanged — the
  # TOG-466 empty branches. The identity preflight above catches the ordinary cause
  # before any of this runs; this is what stops the case if commit fails for some
  # other reason.
  git commit -q -m "ci-verify: ${title}" -m "Deliberately broken. Opened by ci/verify-pipeline.sh to prove the merge gate works. Close it, do not merge it." || {
    fail "git commit failed for ${slug} — see above. Nothing for this case has been pushed."
    exit 1
  }
  git push -q -u origin "$branch" --force-with-lease
  gh pr create --base "$BASE_BRANCH" --head "$branch" \
    --title "[ci-verify] ${title} — do not merge" \
    --body "Automated verification of the merge gate (TWO-22). Expected to be **rejected**. Closed automatically by \`ci/verify-pipeline.sh --cleanup\`." \
    --draft >/dev/null
  echo "$branch"
}

# Every PR is opened before any waiting starts, so the runs happen in parallel
# rather than end to end. Ten sequential CI runs is most of a morning.
log "Opening the broken pull requests"
for entry in "${CASES[@]}"; do
  IFS='|' read -r slug job aggregate why <<< "$entry"
  branch=$(open_pr "$slug" "$why")
  echo "  $branch"
done

log "Opening the clean pull request"
git checkout -q -B "${BRANCH_PREFIX}/clean" "origin/${BASE_BRANCH}"
printf '\n<!-- ci-verify: a no-op change so a clean PR has something to build. -->\n' >> README.md
git add -- README.md
git commit -q -m "ci-verify: a clean PR goes green"
git push -q -u origin "${BRANCH_PREFIX}/clean" --force-with-lease
gh pr create --base "$BASE_BRANCH" --head "${BRANCH_PREFIX}/clean" \
  --title "[ci-verify] a clean PR goes green — do not merge" \
  --body "Automated verification of the merge gate (TWO-22). Expected to be **green**." --draft >/dev/null

git checkout -q "$BASE_BRANCH" 2>/dev/null || git checkout -q "origin/${BASE_BRANCH}"

# --- assert -----------------------------------------------------------------

# Results come from the Checks API — `gh api .../check-runs` — and deliberately
# not from `gh pr checks`, and no longer from the Actions API at all.
#
# Not `gh pr checks`, still, for the reason that made it a *silent* wrong answer
# rather than an error (TWO-87): `gh pr checks --json` landed in gh 2.47, Debian
# ships 2.46, and there the command prints usage to stderr and exits — `|| echo ""`
# swallows it and an empty result reads as "no check ever reported". Eight false
# failures in forty minutes. `gh api` is the stable surface; the JSON is ours to
# filter and its absence is an error we can see.
#
# Not the Actions API, since TOG-328. `repos/{slug}/actions/runs` and `.../jobs`
# need `Actions: read`, which also grants workflow *log* download — logs carry
# whatever CI printed — and TOG-247 refused that permanently. See the note in the
# header. Check-runs need `Checks: read`, which grants exactly the conclusions.
#
# The old TWO-87 note listed `Checks: read` as a reason to avoid this API: "a
# permission nobody thinks to ask for". That is no longer true and was never a
# safety argument — it was a provisioning one, and provisioning has caught up.
# `Checks: read` is in the default profile TOG-247 settled on, and it is now first
# in this file's permission list and probed in the preconditions before anything is
# pushed, so a credential without it is refused in the first second rather than
# forty minutes in.
#
# The names are the same either way: a check run's `name` for an Actions job is the
# job name, which is what branch protection matches on and what EXPECTED_CHECKS
# holds. That is the property the port rests on, and check 7 in lint() is what
# keeps the three lists agreeing.

check_branch() {
  local branch="$1" expected_job="$2" aggregate="$3" expect_green="$4" why="$5"

  # Blocks until every check on the branch has reported.
  wait_for_checks "$branch" || true

  local checks
  checks=$(branch_checks "$branch")

  assert_checks "$checks" "$expected_job" "$aggregate" "$expect_green" "$why" "$branch"
}

log "Waiting for CI on all $(( ${#CASES[@]} + 1 )) pull requests"
ok=0
for entry in "${CASES[@]}"; do
  IFS='|' read -r slug job aggregate why <<< "$entry"
  check_branch "${BRANCH_PREFIX}/${slug}" "$job" "$aggregate" "no" "$why" || ok=1
done
check_branch "${BRANCH_PREFIX}/clean" "$AGGREGATE" "SUCCESS" "yes" "the happy path" || ok=1

# The fourth Actions read in this file, and the quietest: `gh run list` is
# `repos/{slug}/actions/runs` under the covers, so on a token without `Actions:
# read` it printed nothing and `|| true` swallowed the reason. A run that looked
# complete simply had no runtime line — and the runtime is not decoration, it is
# the number the header calls load-bearing: a gate the team will not wait for is a
# gate they will route around. Ported with the rest (TOG-328).
#
# Derived from the same check runs the assertions read, which makes it wall-clock
# across *both* workflows rather than ci.yml alone — the honest number, since a PR
# is not clear until gitleaks reports too. `min(started_at)` to `max(completed_at)`
# over the clean branch's check runs.
log "Runtime of the clean run (the number the team has to tolerate)"
clean_sha=$(branch_head "${BRANCH_PREFIX}/clean")
if [ -n "$clean_sha" ]; then
  gh api "repos/${REPO_SLUG}/commits/${clean_sha}/check-runs?per_page=100&filter=latest" \
    --jq '[.check_runs[] | select(.app.slug == "github-actions")]
          | select(length > 0)
          | "  checks: \(length)  conclusions: \([.[].conclusion] | unique | join(","))  started: \([.[].started_at] | min)  finished: \([.[] | select(.completed_at != null) | .completed_at] | max)"' || true
else
  printf '  the clean branch is not in this clone — nothing to measure\n'
fi

cleanup_rc=0
cleanup || cleanup_rc=1

# The gate's verdict first: it is the reason anyone ran this. Leftover pull
# requests are reported separately and never mistaken for a gate defect — but they
# still fail the run, because ten open pull requests titled "do not merge" is not
# a state to walk away from.
if [ "$ok" -ne 0 ]; then
  fail "the merge gate does not catch everything it claims to. Do not sign off TWO-22."
  exit 1
fi

if [ "$cleanup_rc" -ne 0 ]; then
  fail "the gate itself was verified — nothing above is wrong with ci.yml. This run could not clear its own pull requests. Finish that before walking away."
  exit 1
fi

log "Merge gate verified: every deliberate breakage was rejected by the right job, and a clean PR went green."
