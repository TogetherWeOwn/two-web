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
#   ./ci/verify-pipeline.sh              # dry run: prints what it would do
#   ./ci/verify-pipeline.sh --lint       # static checks only: no network, no gh
#   ./ci/verify-pipeline.sh --run        # actually opens the PRs (lints first)
#   ./ci/verify-pipeline.sh --cleanup    # delete leftover branches and close PRs
#
# Needs: gh, authenticated. Opens PRs against `main`. Never pushes to `main`,
# never force-pushes anything, closes every PR it opens.
#
# The token needs exactly three fine-grained permissions on this repository, and
# nothing else — no org admin, no access to any other repo:
#
#   Contents:      read and write   (push and delete the ci-verify/* branches)
#   Pull requests: read and write   (open them, close them)
#   Actions:       read             (read the job results it asserts on)
#
# `Actions: read` is the one that gets left out. Without it the script can open
# every pull request and then read nothing back, which looks exactly like a
# pipeline that never ran.
#
# One `--run` at a time per repository. Every case has a fixed branch name, so a
# second concurrent run overwrites and then deletes the first one's pull requests.
# The preconditions refuse to start when another run is live rather than let that
# happen quietly — see TWO-103.
#
# --lint needs nothing but bash, so it runs before the org exists, in a pre-commit
# hook, or in CI itself. It catches the class of bug where the gate still reports
# green while it has quietly stopped gating.

set -euo pipefail

BRANCH_PREFIX="ci-verify"
BASE_BRANCH="main"
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

# Each case: <slug>|<expected failing job>|<what it proves>
CASES=(
  "pint|static|badly formatted PHP is rejected"
  "phpstan|static|a type error is rejected"
  "pest|pest|a failing feature test is rejected"
  "tokens|pest|an edit to the vendored design system is rejected"
  "dusk|dusk|a broken page is caught in a real browser"
  "a11y|budgets|a WCAG 2.2 AA violation is rejected"
  "lcp|budgets|an LCP breach is rejected"
  "gate|static|a pull request that disarms the merge gate is rejected"
)

log()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
fail() { printf '\033[31mFAIL: %s\033[0m\n' "$*" >&2; }
pass() { printf '\033[32mPASS: %s\033[0m\n' "$*"; }

# --- static checks ----------------------------------------------------------
# Everything here is about one failure mode: the gate reports a result that is not
# the pipeline's result. Opening seven pull requests proves it too, but only after
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
triggers_on_pr() {
  awk '/^on:/{o=1;next} o && /^[a-zA-Z]/{exit} o' "$1" | grep -qE '^\s+pull_request:?'
}

# What one job will actually report as: its `name:` if it sets one, its id if not.
# Branch protection matches the check-run name, so a job that renames itself stops
# answering to its id and takes `main` down with it — the same "absent required
# check" failure as requiring `ci`, but arriving later and looking like flakiness.
# `^    name:` is job level; step names are deeper and carry a `- `.
job_reported_name() {
  local n
  n=$(job_block "$1" "$2" | grep -m1 -E '^    name:' | sed 's/^    name:[[:space:]]*//' | sed 's/^["'"'"']//; s/["'"'"']$//')
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

  return "$rc"
}

# --- the breakages ----------------------------------------------------------
# Each writes exactly one deliberate defect into the working tree. Deterministic on
# purpose: a verification that itself flakes teaches nothing.

break_pint() {
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
  cat > tests/Feature/CiVerifyFailingTest.php <<'PHP'
<?php

it('is deliberately broken to prove CI catches a failing test', function () {
    expect(1)->toBe(2);
});
PHP
}

break_tokens() {
  # One hex nudged in the vendored design system. Nothing in this repo checks
  # contrast — two-design does, and that guarantee only holds while our copy is
  # byte-identical. Without the digest test this is completely silent: the page
  # renders, the suite passes, and the site is quietly less accessible than the
  # design system says it is.
  sed -i '0,/#9e96b5/s//#6a6480/' resources/css/two.css
}

break_dusk() {
  # Hide the heading with CSS. The HTML still contains the text, so the *feature*
  # test's assertSee passes and only the real browser notices it is invisible —
  # which is the whole reason we pay for Dusk. A breakage that also trips `tests`
  # would prove nothing about the browser job.
  sed -i 's#</x-layouts.app>#    <style>h1 { display: none; }</style>\n</x-layouts.app>#' resources/views/home.blade.php
}

break_a11y() {
  # An image with no alt text. wcag2a `image-alt` — a real barrier, and a rule axe
  # detects with total reliability. Invisible to every other job.
  sed -i 's#</x-layouts.app>#    <img src="/favicon.ico" width="16" height="16">\n</x-layouts.app>#' resources/views/home.blade.php
}

break_lcp() {
  # Three seconds of server think-time before anything can paint. LCP cannot come
  # in under the 2.0s budget no matter how fast the runner is, so this proves the
  # assertion is wired up without depending on runner luck.
  #
  # In the view rather than as a closure route on purpose: the budgets job runs
  # `route:cache`, and a closure route is not serialisable, so that version would
  # fail the job at the wrong step and look like a pass.
  sed -i '1i @php usleep(3000000); @endphp' resources/views/home.blade.php
}

break_gate() {
  # Delete the aggregate's `if: always()`. Every job still passes, every test still
  # passes, and the pipeline stops being a gate: a red `static` now *skips* `tests`,
  # and GitHub counts a skipped required check as a passed one. Nothing else in the
  # suite notices — this is the failure mode that has no symptom until the day a
  # broken PR merges clean. Caught by `--lint`, which the `static` job runs first.
  sed -i '/^    if: always()$/d' .github/workflows/ci.yml
}

# --- driver -----------------------------------------------------------------

cleanup() {
  log "Cleaning up"
  for entry in "${CASES[@]}" "clean|tests|the happy path"; do
    slug="${entry%%|*}"
    branch="${BRANCH_PREFIX}/${slug}"
    number=$(gh pr list --head "$branch" --state open --json number --jq '.[0].number' 2>/dev/null || true)
    if [ -n "${number:-}" ] && [ "$number" != "null" ]; then
      gh pr close "$number" --delete-branch --comment "CI verification run finished." >/dev/null 2>&1 || true
      echo "  closed PR #$number ($branch)"
    fi
    git push origin --delete "$branch" >/dev/null 2>&1 || true
    git branch -D "$branch" >/dev/null 2>&1 || true
  done
}

if [ "$MODE" = "--cleanup" ]; then
  cleanup
  exit 0
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
    IFS='|' read -r slug job why <<< "$entry"
    printf '  %-10s -> expects job %-8s : %s\n' "$slug" "$job" "$why"
  done
  printf '  %-10s -> expects job %-8s : %s\n' "clean" "tests" "a clean PR goes green"
  exit 0
fi

# Before eight pull requests and forty minutes of runner time, half a second of
# reading the file.
log "Static checks on the gate"
lint || { fail "the gate is misconfigured. Fix ${WORKFLOW} first — the live run would only tell you the same thing, slower."; exit 1; }

command -v gh >/dev/null || { fail "gh is not installed"; exit 1; }
gh auth status >/dev/null 2>&1 || { fail "gh is not authenticated"; exit 1; }
[ -z "$(git status --porcelain)" ] || { fail "working tree is dirty — commit or stash first"; exit 1; }

# The owner/repo the Actions API is asked about. Resolved and checked here, in the
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
  fail "origin is not a GitHub repository: '$(git config --get remote.origin.url 2>/dev/null || echo unset)'. Results come from the GitHub Actions API, so this has to run in a clone whose origin is GitHub — not a workspace clone and not a local mirror. Nothing has been pushed."
  exit 1
}

# Prove the credential can read results *before* opening eight pull requests. A
# token with push access but without `Actions: read` gets all the way through the
# run and then reads nothing back, which is indistinguishable from a pipeline that
# never ran — and the two diagnoses point in opposite directions. Half a second
# here, or forty minutes and the wrong answer there.
gh api "repos/${REPO_SLUG}/actions/runs?per_page=1" >/dev/null 2>&1 || {
  fail "cannot read the Actions API on ${REPO_SLUG}. The token needs 'Actions: read' — see the permissions listed at the top of this file. Nothing has been pushed."
  exit 1
}

# Nothing else may already be mid-run. Every case gets a fixed branch name —
# `ci-verify/pint`, `ci-verify/gate` — so two `--run` executions against one repo
# share branches. The second push lands on the first run's branch and silently
# changes its pull request's head commit mid-flight, and whichever `cleanup()`
# reaches the end first closes and deletes *both* runs' work. The survivor is then
# told its checks never reported, on a branch that no longer exists, which reads
# as a dead pipeline and is not one — the same wrong diagnosis the Actions API
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
live_run() {
  local prs branches number head sha ref
  prs=$(gh pr list --repo "$REPO_SLUG" --state open --limit 100 \
          --json number,headRefName --jq '.[] | "\(.number)\t\(.headRefName)"' 2>/dev/null || true)
  while IFS=$'\t' read -r number head; do
    case "${head:-}" in
      "${BRANCH_PREFIX}/"*) printf '  pull request #%s on %s\n' "$number" "$head" ;;
    esac
  done <<< "$prs"

  branches=$(git ls-remote --heads origin "refs/heads/${BRANCH_PREFIX}/*" 2>/dev/null || true)
  while read -r sha ref; do
    [ -n "${ref:-}" ] || continue
    printf '  branch %s on origin\n' "${ref#refs/heads/}"
  done <<< "$branches"
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
pass "no other verification run is live on ${REPO_SLUG}"

git fetch origin "$BASE_BRANCH" --quiet
results=()

open_pr() {
  local slug="$1" title="$2"
  local branch="${BRANCH_PREFIX}/${slug}"
  git checkout -q -B "$branch" "origin/${BASE_BRANCH}"
  "break_${slug}" 2>/dev/null || true
  git add -A
  git commit -q -m "ci-verify: ${title}" -m "Deliberately broken. Opened by ci/verify-pipeline.sh to prove the merge gate works. Close it, do not merge it."
  git push -q -u origin "$branch" --force-with-lease
  gh pr create --base "$BASE_BRANCH" --head "$branch" \
    --title "[ci-verify] ${title} — do not merge" \
    --body "Automated verification of the merge gate (TWO-22). Expected to be **rejected**. Closed automatically by \`ci/verify-pipeline.sh --cleanup\`." \
    --draft >/dev/null
  echo "$branch"
}

# Every PR is opened before any waiting starts, so the runs happen in parallel
# rather than end to end. Six sequential CI runs is most of an hour.
log "Opening the broken pull requests"
for entry in "${CASES[@]}"; do
  IFS='|' read -r slug job why <<< "$entry"
  branch=$(open_pr "$slug" "$why")
  echo "  $branch"
done

log "Opening the clean pull request"
git checkout -q -B "${BRANCH_PREFIX}/clean" "origin/${BASE_BRANCH}"
printf '\n<!-- ci-verify: a no-op change so a clean PR has something to build. -->\n' >> README.md
git add -A
git commit -q -m "ci-verify: a clean PR goes green"
git push -q -u origin "${BRANCH_PREFIX}/clean" --force-with-lease
gh pr create --base "$BASE_BRANCH" --head "${BRANCH_PREFIX}/clean" \
  --title "[ci-verify] a clean PR goes green — do not merge" \
  --body "Automated verification of the merge gate (TWO-22). Expected to be **green**." --draft >/dev/null

git checkout -q "$BASE_BRANCH" 2>/dev/null || git checkout -q "origin/${BASE_BRANCH}"

# --- assert -----------------------------------------------------------------

# Results come from the Actions API, deliberately not from `gh pr checks`. Two
# reasons, both of which produced a *silent* wrong answer rather than an error
# (TWO-87):
#
#   * `gh pr checks --json` landed in gh 2.47. Debian ships 2.46, where the flag
#     does not exist: the command prints usage to stderr and exits, `|| echo ""`
#     swallows it, and an empty result reads as "no check ever reported" — eight
#     false failures in forty minutes.
#   * `gh pr checks` reads the Checks API, which a fine-grained token can only
#     touch with `Checks: read`. That is a permission nobody thinks to ask for
#     when the ask is "push access", so the credential arrives unable to read the
#     thing it was issued to read.
#
# The Actions API needs only `Actions: read`, and reports the same job names
# branch protection matches on.

POLL_INTERVAL=20
CHECK_TIMEOUT=2400

# $REPO_SLUG is resolved and proven readable in the preconditions above, before
# anything is pushed.

# The newest run of each workflow on this branch — concurrency cancels the older
# ones, and a cancelled predecessor is not the result we are asserting on.
branch_runs() {
  gh api "repos/${REPO_SLUG}/actions/runs?branch=$1&per_page=50" \
    --jq '[.workflow_runs[] | select(.event == "pull_request")]
          | group_by(.workflow_id) | map(max_by(.run_number))
          | .[] | "\(.id) \(.status)"' 2>/dev/null || true
}

# "<job>=<STATE>" per line. STATE is SUCCESS / FAILURE / CANCELLED / SKIPPED, or
# PENDING for a job that has not concluded.
branch_checks() {
  local id status
  while read -r id status; do
    [ -n "${id:-}" ] || continue
    gh api "repos/${REPO_SLUG}/actions/runs/${id}/jobs?per_page=100" \
      --jq '.jobs[] | "\(.name)=\(.conclusion // "pending" | ascii_upcase)"' 2>/dev/null || true
  done <<< "$(branch_runs "$1")"
}

# Blocks until every expected check has finished. Returns non-zero on timeout so
# the caller reports "never reported" rather than reading a half-finished run.
#
# "Every run we can see has completed" is not the same condition, and the gap is
# real: two workflows produce these checks — ci.yml and secret-scan.yml — and the
# API only lists a run once GitHub has created it. If one workflow's run exists and
# has finished while the other's has not been created yet, the weaker condition
# returns immediately and the assertion reports a check that never ran, on a branch
# where it was about to. Waiting on the check names the assertion actually uses
# closes that, and costs one extra API read per poll.
wait_for_checks() {
  local branch="$1" waited=0 runs pending reported missing
  while [ "$waited" -lt "$CHECK_TIMEOUT" ]; do
    runs=$(branch_runs "$branch")
    pending=$(awk '$2 != "completed"' <<< "$runs" | grep -c . || true)
    if [ -n "$runs" ] && [ "$pending" -eq 0 ]; then
      reported=$(branch_checks "$branch" | cut -d= -f1)
      missing=0
      for expected in "${EXPECTED_CHECKS[@]}"; do
        grep -qxF "$expected" <<< "$reported" || missing=1
      done
      [ "$missing" -eq 0 ] && return 0
    fi
    sleep "$POLL_INTERVAL"
    waited=$((waited + POLL_INTERVAL))
  done
  return 1
}

check_branch() {
  local branch="$1" expected_job="$2" expect_green="$3" why="$4"

  # Blocks until every check on the branch has reported.
  wait_for_checks "$branch" || true

  local checks
  checks=$(branch_checks "$branch")

  # A check that never reported is not a pass. If the workflow file has a syntax
  # error, or Actions is disabled on the repo, the API returns nothing at all — and
  # reading nothing as green is exactly the bug this script exists to catch, one
  # level up. The other two ways to get nothing back — an origin that is not a
  # GitHub repository, and a token without `Actions: read` — are ruled out in the
  # preconditions before anything is pushed, so if this fires the pipeline really
  # did not report.
  local absent=""
  for expected in "${EXPECTED_CHECKS[@]}"; do
    grep -q "^${expected}=" <<< "$checks" || absent="${absent} ${expected}"
  done
  if [ -n "$absent" ]; then
    fail "${why}: check(s)${absent} never reported on ${branch}. A required check that never arrives blocks the PR forever; one that is not required is not gating at all. Got: $(echo "$checks" | tr '\n' ' ')"
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
  if ! echo "$checks" | grep -q "^${expected_job}=FAILURE$"; then
    fail "${why}: expected job '${expected_job}' to fail, got: $(echo "$checks" | tr '\n' ' ')"
    return 1
  fi
  # The aggregate is what branch protection requires. A named job going red while
  # `tests` stays green is the exact failure this whole script exists to catch: it
  # looks like a working pipeline and merges anyway.
  if ! echo "$checks" | grep -q "^tests=FAILURE$"; then
    fail "${why}: '${expected_job}' failed but the required 'tests' check did not — the gate would let this merge"
    return 1
  fi
  pass "${why} (rejected by '${expected_job}')"
  return 0
}

log "Waiting for CI on all seven pull requests"
ok=0
for entry in "${CASES[@]}"; do
  IFS='|' read -r slug job why <<< "$entry"
  check_branch "${BRANCH_PREFIX}/${slug}" "$job" "no" "$why" || ok=1
done
check_branch "${BRANCH_PREFIX}/clean" "tests" "yes" "the happy path" || ok=1

log "Runtime of the clean run (the number the team has to tolerate)"
gh run list --branch "${BRANCH_PREFIX}/clean" --workflow CI --limit 1 \
  --json createdAt,updatedAt,conclusion \
  --jq '.[] | "  conclusion: \(.conclusion)  started: \(.createdAt)  finished: \(.updatedAt)"' || true

cleanup

if [ "$ok" -ne 0 ]; then
  fail "the merge gate does not catch everything it claims to. Do not sign off TWO-22."
  exit 1
fi

log "Merge gate verified: every deliberate breakage was rejected by the right job, and a clean PR went green."
