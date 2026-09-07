# Flake policy

**A flaky test is a bug. It gets fixed or it gets deleted. It never gets re-run
until it is green.**

That is the whole policy. The rest of this page is why, and what to do on the
morning it happens to you.

---

## Why we are this strict about it

A flaky test does more damage than no test, because it destroys the only thing a
suite is for: the sentence "CI is red, so something is broken." Once that sentence
stops being reliably true, the team stops reading failures and starts clicking
re-run. At that point every real bug the suite catches also gets clicked past. The
suite is now costing us money and catching nothing.

There is no gentle version of this. One tolerated flake teaches the habit.

## What counts as a flake

A test that passes and fails on the same commit, with no change to the code.

If you cannot tell whether the code changed, it is a flake. "It failed once last
week" is a flake. "It only fails on CI" is a flake — CI is where it matters.

## What you do

**1. Do not press re-run.** Not once. If you already did, note that in the issue.

**2. Open an issue immediately**, before you decide anything. Include the failing
test, the run URL, and the error. Dusk failures have a screenshot, the page source
and the browser console in the `dusk-failures` artifact — attach them.

**3. Then pick one of two options.** There is no third.

**Fix it.** Ideally within the day. The usual causes, roughly in order:

- *A real race in the application.* This is the good outcome — the test found a bug
  in the product, and the bug is that two RSVPs can land at once. Fix the app.
- *Waiting on time instead of a condition.* `pause(500)` is a guess. Dusk has
  `waitFor`, `waitUntilMissing`, `waitForText`, `waitForReload`. Use them.
- *Order dependence.* The test passes alone and fails in the suite because another
  test left a row behind. `RefreshDatabase` is applied to `Feature`; if you are
  relying on data another test created, that is the bug.
- *Real clock or real timezone.* `Carbon::setTestNow`.
- *Unordered results asserted in order.* Postgres owes you nothing without `ORDER BY`.
- *A real network call.* Nothing in the suite reaches the internet. Fake it.

**Delete it.** A legitimate ending, not a defeat. Delete when the test is not
earning its keep — it covers something a feature test already covers, or it is
asserting on incidental detail. Deleting a low-value flaky test makes the suite
*more* trustworthy, immediately.

Say which one you did in the issue, and close it.

## What you may not do

- Re-run until green.
- `->skip()`, `->todo()`, or a commented-out test, as a way to get past today. A
  skipped test is a deleted test that still shows up in the count and lies about
  coverage. If it needs to go, delete it and open the issue to bring it back.
- Wrap it in a retry. Retry logic in a test suite is a policy decision disguised as
  a helper, and the decision it makes is "we tolerate flakes."
- Widen an assertion until it stops failing.
- Merge on top of it. A red CI is a red CI regardless of whose fault it is
  (merge gate, box 1).

## The one sanctioned exception, and its shape

Some measurements are genuinely statistical. Lighthouse LCP on a shared GitHub
runner has real variance that is not a bug in our code.

The answer to that is **more samples, declared up front** — `numberOfRuns: 3` with
the median asserted, in `ci/lighthouserc.cjs`, in a config file anyone can read.
It is not "run it again and take the best."

The distinction that matters: sampling is part of the measurement and is written
down. A retry is discarding a result you did not like. If you ever want to argue
for an exception, it has to look like the first one.

## Suite health, reported weekly

QA reports these every week, to the CEO, whether or not anyone asks:

- **Total runtime**, wall clock, PR open to all checks green.
- **Flake rate** — distinct tests that failed and then passed on an unchanged
  commit, over the week.
- **Coverage of the critical journeys** — which of the six Dusk journeys are
  written, green, and running on every PR.

The report is executable, not a prose estimate:

```bash
node ci/collect-suite-health.mjs \
  --since 2026-09-01T00:00:00Z --until 2026-09-07T23:59:59Z \
  --failures failed-dusk-tests.json --output check-runs.json
node ci/suite-health.mjs --input check-runs.json
node ci/suite-health.mjs --validate-manifest
```

`ci/suite-health-input.example.json` documents the input shape and preserves the
measured PR #259 baseline. `ci/collect-suite-health.mjs` retains every check-run
attempt for each unchanged head SHA; when Dusk failed and then passed, the
`--failures` file attaches the failing test names extracted from the failed check
output or artifact. The collector refuses to call that history zero flakes when
those names are absent. GitHub's `filter=latest` response is insufficient because
it erases the failed half after a re-run. The six-journey
mapping lives in `ci/critical-journeys.json`; `static` runs its self-test on every
PR, and partial browser coverage is shown as **incomplete**, never promoted by a
feature test covering only the server-side half.

**The target for flake rate is zero.** Not "low". If the number is not zero, the
report says which test, which issue, and whether it is being fixed or deleted.

## When the suite gets slow

The number the team will tolerate is about **ten minutes** from opening a PR to all
checks green. The jobs run in parallel and Dusk is the long pole.

If we cross that, the answer is not to delete tests to get under it. The answer, in
order: cache more, parallelise the Dusk journeys, and push assertions down the
pyramid where a feature test can carry them. If none of that is enough, QA says so
out loud — early, not at the release.

See also: [testing-strategy.md](testing-strategy.md), [ci.md](ci.md).
