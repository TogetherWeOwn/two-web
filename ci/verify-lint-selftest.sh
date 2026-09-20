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
  # The lint loads lighthouserc.cjs through node now, and that file requires
  # pages.cjs. Without it every case would go red on a missing module rather than
  # on the defect it was written for — including `clean`.
  cp "$REPO_ROOT/ci/pages.cjs" "$dir/ci/"
  # Check 11 reads the secret-scan config. It lives at the repository root rather
  # than under ci/, so it is copied by name.
  cp "$REPO_ROOT/.gitleaks.toml" "$dir/"
  echo "$dir"
}

# A content digest of every file a mutation could touch, so "did this mutation do
# anything at all?" is answerable. Sorted for stability; the value is compared to
# itself, never to a stored constant, so it needs no pinning.
fixture_digest() {
  find "$1" -type f -exec sha256sum {} + | sed "s|$1||" | sort | sha256sum
}

# expect_fail <slug> <expected substring of the failure> <mutation...>
# The mutation runs with the fixture as cwd.
expect_fail() {
  local slug="$1" expected="$2"; shift 2
  local dir out status before after
  n=$((n + 1))
  dir="$(fixture "$slug")"
  before="$(fixture_digest "$dir")"
  ( cd "$dir" && "$@" ) || { fail "$slug: the mutation itself failed to apply"; rc=1; return; }
  after="$(fixture_digest "$dir")"
  # A mutation that changed nothing is not a passing case, it is an absent one.
  # `sed` exits 0 when its pattern matches no line, so a case pinned to a literal
  # keeps reporting PASS after a refactor renames that literal — the lint is then
  # being handed a pristine file and correctly says nothing is wrong. That is how
  # `budget-relaxed` and `budget-aggregation-removed` went vacuous when the LCP
  # threshold moved behind `buildAssertions(...)` (TOG-54). Compare the fixture
  # before and after so the no-op is a failure here, where it is legible, rather
  # than years later as coverage nobody knew had stopped.
  if [ "$before" = "$after" ]; then
    fail "$slug: the mutation changed nothing — the case is vacuous, not passing"
    rc=1
    return
  fi
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
  local dir out status before after
  n=$((n + 1))
  dir="$(fixture "$slug")"
  before="$(fixture_digest "$dir")"
  ( cd "$dir" && "$@" ) || { fail "$slug: the mutation itself failed to apply"; rc=1; return; }
  after="$(fixture_digest "$dir")"
  if [ "$before" = "$after" ]; then
    fail "$slug: the mutation changed nothing — the case is vacuous, not passing"
    rc=1
    return
  fi
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
#
# Addressed to `buildAssertions(2000)` rather than to `maxNumericValue: 2000`,
# which is where the public LCP budget now lives: TOG-54 split the thresholds into
# an assertMatrix and made the number an argument, so the old literal stopped
# existing and this mutation quietly became a no-op against a pristine file. The
# vacuity guard in expect_fail is what turned that into a red instead of a
# permanent green.
expect_fail budget-relaxed 'budget `largest-contentful-paint`' \
  sed -i 's/buildAssertions(2000)/buildAssertions(4000)/' ci/lighthouserc.cjs

# The TTFB gate deleted. Nothing else in the pipeline notices a slow server: under
# `throttlingMethod: 'simulate'` a three-second document response is medianed away
# before LCP is ever computed (TWO-93). Remove this line and the LCP budget goes on
# reporting green over a homepage that takes three seconds to answer.
expect_fail ttfb-gate-removed 'budget `server-response-time`' \
  sed -i "/'server-response-time':/d" ci/lighthouserc.cjs

# The same gate downgraded to a warning, which reads like keeping it and is not.
expect_fail ttfb-downgraded 'budget `server-response-time`' \
  sed -i "s/'server-response-time': \['error'/'server-response-time': ['warn'/" ci/lighthouserc.cjs

# The number kept, the aggregation swapped. One word, and every budget goes from
# median-of-3 to best-of-3: with `optimistic`, @lhci/utils/src/assertions.js takes
# `Math.min` over the runs for any `max*` assertion. The threshold still reads 600
# in the diff, so this is the quietest way to relax a budget there is.
expect_fail budget-aggregation-swapped 'budget `server-response-time` is not asserted' \
  sed -i "s/maxNumericValue: 600, aggregationMethod: 'median'/maxNumericValue: 600, aggregationMethod: 'optimistic'/" ci/lighthouserc.cjs

# The aggregation deleted rather than swapped. Same effect: lhci defaults
# `aggregationMethod` to `'optimistic'`, so `'median'` is load-bearing and removing
# it is best-of-3 by another route. This used to be caught by luck — the old grep
# needed a trailing comma and `{ maxNumericValue: 2000 }` has none. Now it is
# caught on purpose, and this case is what keeps it that way.
#
# Since TOG-54 the LCP assertion is built from `lcpBudgetMs`, so the deletion is
# addressed to that line rather than to a literal 2000 that no longer appears.
# One `sed`, and both the public and the /admin budget lose their median — which
# is the honest blast radius of removing it from a shared builder.
expect_fail budget-aggregation-removed 'budget `largest-contentful-paint` is not asserted' \
  sed -i "s/{ maxNumericValue: lcpBudgetMs, aggregationMethod: 'median' }/{ maxNumericValue: lcpBudgetMs }/" ci/lighthouserc.cjs

# The /admin exception widened. This is what the public budget is protected *by*:
# the reason the CEO's 2000 above survives contact with a slow vendor panel is
# that the panel has a ceiling of its own that nobody may nudge. Widen that and
# there is no pressure left on the public number — which makes an unnoticed edit
# here strictly worse than an edit to the 2000, because it looks like it costs
# nothing.
#
# TOG-1008 lowered it from 3200 to 3000 against a measurement, which is the
# standard: a deliberate edit here in a commit that says what it measured. The
# lint reads the effective value through node, so raising the literal, downgrading
# the exception to a warning, or dropping its median all read the same way — red.
expect_fail admin-exception-widened 'the relaxed `/admin` LCP budget reads' \
  sed -i 's/buildAssertions(3000)/buildAssertions(4000)/' ci/lighthouserc.cjs

# A second entry appended for an audit that already has one. The pinned line is
# left exactly as it was — and a JavaScript object literal keeps the *last*
# duplicate key, so lhci loads the new one and the CEO's LCP budget is gone. The
# three cases above all edit the pinned line in place; this one does not touch it,
# which is why it slipped past the first version of the check (QA on TWO-93). It is
# also the variant that looks least like tampering and most like a bad merge.
#
# Loosened from `is asserted 2 times` when the lint stopped counting keys and
# started reading the effective value through node: there is no count to report
# any more, and the failure now names the budget and prints what lhci actually
# loads. Same defect, same red, different sentence — and the same expectation as
# its three siblings below, which is the point of them being siblings.
expect_fail budget-duplicated 'budget `largest-contentful-paint`' \
  sed -i "/'server-response-time':/a\\        'largest-contentful-paint': ['warn', { maxNumericValue: 99999 }]," ci/lighthouserc.cjs

# The same duplicate, spelled the three other ways JavaScript allows. The case
# above counts `'largest-contentful-paint':` — single quotes, literal key — and
# that is one spelling of four. Each of these loads as the effective budget and
# each leaves the pinned line untouched and still matching the grep, so `--lint`
# exits 0 while the CEO's 2000ms LCP budget is a warning at 99999. Confirmed by
# loading the mutated config through node and printing what lhci would read
# (QA, TWO-101).
#
# The expectation is only that the lint goes red naming the budget, not that it
# says any particular sentence: how this gets covered is the author's call. Worth
# saying that a grep widened to accept `["']` closes the first two and cannot
# close the third — a spread has no key to match. Reading the effective value out
# of the config with node covers all four at once and cannot drift from what lhci
# loads, because it is the same require(). The runners carry node before
# `setup-node` runs, so the `static` job can do this where it already stands.
expect_fail budget-duplicated-double-quoted 'budget `largest-contentful-paint`' \
  sed -i "/'server-response-time':/a\\        \"largest-contentful-paint\": ['warn', { maxNumericValue: 99999 }]," ci/lighthouserc.cjs

expect_fail budget-duplicated-computed-key 'budget `largest-contentful-paint`' \
  sed -i "/'server-response-time':/a\\        ['largest-contentful-paint']: ['warn', { maxNumericValue: 99999 }]," ci/lighthouserc.cjs

# The one that looks most like a merge artefact and least like a key at all.
expect_fail budget-duplicated-spread 'budget `largest-contentful-paint`' \
  sed -i "/'server-response-time':/a\\        ...{ 'largest-contentful-paint': ['warn', { maxNumericValue: 99999 }] }," ci/lighthouserc.cjs

# --- The secret scan stops scanning for anything but Discord (TOG-297) ---------
#
# Every case below leaves `secret-scan.yml` untouched, the `gitleaks` job green in
# every other respect, and the required check arriving on time. What changes is
# what the scan is looking for. Nothing live covers this: the `secret` case in
# `--run` commits a value shaped to match our own `discord-bot-token` rule, on
# purpose, so a check-run conclusion cannot say whether the default rule set is
# still on (docs/ci.md). These are that missing half, offline.
#
# The four `useDefault` cases were each run against the pinned gitleaks 8.30.1 on
# a repository holding an AWS key pair and a real-shaped Discord bot token — four
# findings on the real config. Numbers below are measured, not argued.

# The line deleted outright. Four findings become two: the AWS pair goes quiet and
# nothing but the three Discord rules is left. This is the case the card was filed
# for and the only one of the four a grep for the line would also catch.
expect_fail gitleaks-usedefault-deleted 'does not resolve to the boolean `true`' \
  sed -i '/^useDefault = true$/d' .gitleaks.toml

# The line left word for word, and a stanza inserted above it. `useDefault = true`
# now belongs to the table immediately above — it sets `rules[0].useDefault`, which
# nothing reads — and `[extend]` is empty. Measured: defaults off, two findings.
# This is why the check parses instead of matching text. It is also the variant
# that looks least like tampering: adding a rule is the ordinary edit to this file.
expect_fail gitleaks-usedefault-rehomed 'does not resolve to the boolean `true`' \
  bash -c "sed -i \"/^useDefault = true\$/i[[rules]]\\nid = \\\"placeholder\\\"\\nregex = '''nothing-in-particular'''\\n\" .gitleaks.toml"

# Quoted. TOML types are not shell truthiness: gitleaks unmarshals this field into
# a Go bool, and a string is not one. `\"false\"` is the dangerous spelling of the
# two — measured, the config loads clean, the defaults are off and the scan exits
# 0. (`\"true\"` fails the load outright and is loud; this one is silent.)
expect_fail gitleaks-usedefault-quoted 'does not resolve to the boolean `true`' \
  sed -i 's/^useDefault = true$/useDefault = "false"/' .gitleaks.toml

# `[extend]` has a second field, and it undoes the first one a pattern at a time.
# `disabledRules` subtracts from the set being extended, so this is `useDefault =
# true` with the AWS rule quietly carved out of it — and the line above still
# reads `true`, so the config looks fully armed in the diff.
#
# Measured on the pinned 8.30.1, against a repository with an AWS pair and a
# real-shaped bot token: the control reports aws-access-token, generic-api-key
# and discord-bot-token; with this entry the AWS finding is gone and the other two
# remain. Names a stock rule rather than one of ours deliberately — `disabledRules`
# reaches the default set only, and an entry naming `discord-bot-token` changes
# nothing at all (also measured). A case built on that would have gone red here
# while the scan it claimed to protect was unharmed.
expect_fail gitleaks-stock-rule-disabled 'switches off aws-access-token' \
  bash -c "sed -i \"/^useDefault = true\$/a disabledRules = ['''aws-access-token''']\" .gitleaks.toml"

# The rule deleted rather than disabled. The stock rule set has no Discord token
# pattern at all, so with this gone nothing anywhere is looking for the one
# credential this repository must never hold — and `useDefault = true` above it
# is intact, so the config still looks like it is doing its job.
expect_fail gitleaks-rule-deleted 'no longer defines the rule `discord-bot-token`' \
  bash -c "awk '
    /^\[\[rules\]\]\$/                      { block = \"\"; collecting = 1 }
    collecting                              { block = block \$0 \"\\n\"
                                              if (\$0 == \"\") {
                                                if (block !~ /discord-bot-token/) printf \"%s\", block
                                                collecting = 0
                                              }
                                              next }
                                            { print }
  ' .gitleaks.toml > .gitleaks.mutated && mv .gitleaks.mutated .gitleaks.toml"

# One bare `.*` appended to the allowlist. `regexes` is matched against every
# candidate finding, so this is not a widening — it is an off switch for the whole
# scan. Measured: the four-finding repository reports none, `discord-bot-token`
# included, and the job goes green. It is one line, it reads like a broadening of
# an existing narrow entry, and the file's prose asking people not to do it was
# until now the only thing standing in the way.
expect_fail gitleaks-allowlist-trivial 'match the empty string' \
  bash -c "sed -i \"s|^regexes = \\[\$|regexes = [\\n  '''.*''',|\" .gitleaks.toml"

# The same, in the other list. `paths` allowlists by file rather than by value and
# has exactly the same reach — measured, also zero findings. Covered separately
# because a check written for `regexes` alone passes this one.
expect_fail gitleaks-allowlist-trivial-path 'match the empty string' \
  bash -c "sed -i \"s|^regexes = \\[\$|paths = ['''.*''']\\nregexes = [|\" .gitleaks.toml"

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
