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
#   ./ci/verify-pipeline.sh --run        # actually opens the PRs
#   ./ci/verify-pipeline.sh --cleanup    # delete leftover branches and close PRs
#
# Needs: gh, authenticated, with push access. Opens PRs against `main`. Never
# pushes to `main`, never force-pushes anything, closes every PR it opens.

set -euo pipefail

BRANCH_PREFIX="ci-verify"
BASE_BRANCH="main"
MODE="${1:---dry-run}"

# Each case: <slug>|<expected failing job>|<what it proves>
CASES=(
  "pint|static|badly formatted PHP is rejected"
  "phpstan|static|a type error is rejected"
  "pest|pest|a failing feature test is rejected"
  "tokens|pest|an edit to the vendored design system is rejected"
  "dusk|dusk|a broken page is caught in a real browser"
  "a11y|budgets|a WCAG 2.2 AA violation is rejected"
  "lcp|budgets|an LCP breach is rejected"
)

log()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
fail() { printf '\033[31mFAIL: %s\033[0m\n' "$*" >&2; }
pass() { printf '\033[32mPASS: %s\033[0m\n' "$*"; }

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

if [ "$MODE" != "--run" ]; then
  log "Dry run. Nothing will be pushed. Re-run with --run to execute."
  for entry in "${CASES[@]}"; do
    IFS='|' read -r slug job why <<< "$entry"
    printf '  %-10s -> expects job %-8s : %s\n' "$slug" "$job" "$why"
  done
  printf '  %-10s -> expects job %-8s : %s\n' "clean" "tests" "a clean PR goes green"
  exit 0
fi

command -v gh >/dev/null || { fail "gh is not installed"; exit 1; }
gh auth status >/dev/null 2>&1 || { fail "gh is not authenticated"; exit 1; }
[ -z "$(git status --porcelain)" ] || { fail "working tree is dirty — commit or stash first"; exit 1; }

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

check_branch() {
  local branch="$1" expected_job="$2" expect_green="$3" why="$4"

  # Blocks until every check on the branch has reported.
  gh pr checks "$branch" --watch --interval 20 >/dev/null 2>&1 || true

  local checks
  checks=$(gh pr checks "$branch" --json name,state --jq '.[] | "\(.name)=\(.state)"' 2>/dev/null || echo "")

  if [ "$expect_green" = "yes" ]; then
    if echo "$checks" | grep -qv 'SUCCESS$' && [ -n "$(echo "$checks" | grep -v 'SUCCESS$')" ]; then
      fail "clean PR was not green: $(echo "$checks" | tr '\n' ' ')"
      return 1
    fi
    pass "clean PR went green"
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
