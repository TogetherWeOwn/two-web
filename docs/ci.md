# CI, the merge gate, and deploys

**Red means nobody merges.** Not "nobody merges unless it's urgent."

---

## What runs on every pull request

`.github/workflows/ci.yml`. Four jobs in parallel, plus a gate.

| Job | What it does | Fails when |
|---|---|---|
| `static` | gate wiring, Pint `--test`, PHPStan level 8 | the gate stops gating, formatting drifts, or types do not hold |
| `pest` | Pest unit + feature, real Postgres 17 | any test fails |
| `dusk` | Laravel Dusk, real Chrome, real server | any journey fails |
| `budgets` | Lighthouse mobile + axe-core at 360px and 1280px | LCP ≥ 2.0s, CLS ≥ 0.1, or any WCAG 2.2 AA violation |
| `tests` | aggregates the four | any of them is not green, including *skipped* |

All five are required checks on `main`, plus `gitleaks` from `secret-scan.yml`.

`static` is deliberately first to finish — it catches the ordinary mistakes in under
a minute so you are not waiting on Dusk to be told about an unused import.

Locally, `composer check` runs the first two.

### Job names are a contract

Branch protection matches a required check against the **job id**, not the workflow
name. Rename a job and the required check never arrives, so the PR waits forever on
something that will never report — which looks exactly like CI being slow, for
hours. Rename in both places or not at all.

`two-bot/scripts/setup-github.sh` already requires two checks on `main`: `tests` and
`gitleaks` (TWO-39). So:

- **`tests` is the aggregate job**, not the Pest job. Pest is `pest`. If `tests` were
  only Pest, a red `dusk` or a blown `budgets` would still leave the required check
  green and the PR mergeable — a gate that reports on a quarter of the pipeline is
  worse than no gate, because it looks like one.
- **`gitleaks`** comes from `.github/workflows/secret-scan.yml`, job id `gitleaks`.
  Not defined here.

### GitHub's two asymmetric rules

Both have already produced a wrong protection rule on this repo. Learn them once:

- **A *skipped* required check counts as passed.** A job with a plain `needs:` is
  *skipped*, not failed, when one of its needs goes red. So a plain aggregate is a
  green light on a red pipeline. `tests` therefore carries `if: always()` and a
  guard step that fails on `failure`, `cancelled` **and** `skipped`. Dropping any
  one of those three disarms the gate while leaving it looking armed.
- **An *absent* required check blocks the PR forever.** Require a context that no
  job produces — the workflow *name* instead of a job id, a renamed job, a typo —
  and every PR waits, indefinitely, on a check that will never report. This looks
  exactly like CI being slow.

`./ci/verify-pipeline.sh --lint` asserts both directions offline, in half a second,
with no GitHub. Run it after any edit to `ci.yml` or to the protection rules.

### Required checks on `main`

Six, applied by the setup script (TWO-36). This is the list, and it is the same
list in `ci/verify-pipeline.sh` — `--lint` fails if the two disagree:

- `static` — Pint, PHPStan, and the gate's own wiring
- `pest` — unit + feature
- `dusk` — the browser journeys
- `budgets` — Lighthouse and WCAG 2.2 AA
- `tests` — the aggregate over the four above
- `gitleaks` — the secret scan

The four leaves are required *as well as* the aggregate, deliberately: protection
then does not depend on the aggregate's `if: always()` guard staying correct
through future edits. The price is that a newly added job is not required until
someone adds it here — so `--lint` prints a warning for every job that reports on
a pull request and is not on this list. Do not let that warning become furniture.

**`static` is not optional in that list**, and it is the one entry that cannot be
traded away to shorten it. It is the only required check that goes red on a pull
request which disarms the aggregate — `tests` is *skipped* on that pull request, and
skipped counts as passed. Dropping `static` while keeping `tests` is precisely the
protection rule under which a gate-disarming change merges clean. `--lint` fails,
rather than warns, if the job running `--lint` stops being required. See
*Which required check stops a gate-disarming pull request* below.

**There is no job called `ci`.** `CI` is the *workflow* name in `ci.yml`; protection
matches the check-run name, which is the job's `name:` if it sets one and its id
otherwise. Requiring `ci` blocks every pull request forever — see the second rule
above. It was briefly applied to this repo, and `--lint` now fails on it by name.

**Renaming a job breaks protection the same way.** Every job in `ci.yml` pins
`name:` equal to its id for this reason. Change one and the old required context
never arrives again; `--lint` catches that too, and it presents as CI hanging
rather than as a misconfiguration, so it is worth catching early.

Plus: no direct pushes to `main`, PR required, no self-approval, and dismiss stale
approvals on new commits.

### Reading the rule, not the list

Everything above is what this repo *believes* is required. `--lint` checks that
belief against `ci.yml` and against this document. None of the three is the rule
GitHub enforces, and the difference is not academic: in the TWO-22 acceptance run
the `ci-verify/gate` branch merged past a *skipped* aggregate and was stopped only
because `static` is required in its own right — an argument that holds only if
`static` really is a required context, which nothing was reading.

```
./ci/verify-protection.sh                       # reads the live rule on `main`
./ci/verify-protection.sh saved-response.json   # re-check a recorded rule, offline
```

It fails on: a check in the list that is not required (it runs, it reports, and a
PR merges over it red), a required context no job produces (every PR pending
forever), zero required approvals (self-merge, spelled differently), stale
approvals surviving a push, `enforce_admins` off, and force-push or delete allowed
on `main`.

Live mode needs **`Administration: read`** — a fourth permission, separate from the
three `verify-pipeline.sh` needs. A token without it gets a 404 whose *body* reads
much like "the branch is not protected", so the script reads the HTTP status line
and the error message rather than the body alone, and says which of the two it
got. It exits:

| exit | meaning |
|---|---|
| 0 | the rule was read and it enforces what the pipeline claims |
| 1 | the rule was read and it does not, or there is no rule — a finding |
| 2 | the rule could not be read — no verdict either way |

**2 is not a softer 1.** Treating "I could not check" as "I checked and it is bad"
is the TWO-96 bug: the script used to read `.enforce_admins` and the rest off a
403 error body, find every field absent, and print a full red report about a branch
nobody had read. Both exits fail closed, but only 1 is evidence, and only 1 may be
quoted in a sign-off.

It does not run on pull requests, for that reason. Its self-test does, in `static`.
Run the live check by hand after any change to the protection rule, and as part of
release sign-off.

---

## The budgets

Set by the CEO. Enforced as **failures, not warnings**.

- **LCP < 2.0s** on a mid-range phone profile — Moto G Power class, 4× CPU
  slowdown, simulated Slow 4G. Configured in `ci/lighthouserc.cjs`.
- **CLS < 0.1**, same profile.
- **WCAG 2.2 AA**, zero violations, in `ci/a11y.mjs`.

Measured against a production-shaped build: `composer install --no-dev`,
`APP_DEBUG=false`, config/route/view caches warm, real built assets. Measuring a
debug build flatters us and then production surprises us.

Three Lighthouse runs per URL, median asserted — see the flake policy on why that
is sampling and not a retry.

**Nobody lowers a budget to unblock a release.** Not QA, not the Lead, not the
Frontend Engineer. It is a CEO decision, made in writing on the issue, and then
landed here as its own commit that says so. A threshold quietly relaxed inside a
feature PR is the specific thing this file exists to prevent.

### Adding a page

`ci/pages.cjs` is the one list both budget tools read. **A route that is not in that
list has no performance budget and no accessibility check** — CI will go green on
it regardless. A PR that adds a route and not a line there is not ready.

### On the accessibility check

axe-core catches roughly a third of real barriers. Contrast over a gradient, an
illogical focus order, a screen reader reading "button button button" — all of those
pass this job. It is not proof the site is accessible; it is the third a machine can
check, on every PR, for free. The manual pass is part of launch hardening (TWO-35).

We do **not** use the Lighthouse accessibility score for this. It is a weighted
subset with no WCAG 2.2 rules in it at all.

**Every page is checked at 360px and at 1280px.** 360px is the tested floor of the
design system (TWO-19) — it is where WCAG 2.2's target-size and reflow rules bite
and where focus rings collide with the thing next to them. 1280px is where landmark,
heading-order and contrast problems on the wide layout live. A desktop-only pass
would never render the mobile nav; a mobile-only pass would never render the desktop
one. The failure annotation names the width, because "it looks fine on my laptop" is
the usual first reply.

### The token gates live in `two-design`, not here

The design system verifies itself: `node tools/check-contrast.mjs` asserts all 43
colour pairings against WCAG 2.2 AA by parsing `tokens/two.css` directly, and
`node tools/build-preview.mjs && git diff --exit-code preview/` catches a token
edited without regenerating the preview. Both are blocking in that repo's own CI.

They check the **tokens**; axe checks the **pages**. Different failures — a page can
use perfectly compliant colours and still have no landmarks and an unlabelled
button. Neither replaces the other.

The tokens **are** vendored into this repo, which means we hold a copy that can
drift from its source, and two-design's contrast gate reaches our copy only while
the bytes are identical. `vendored.design-system` records the upstream commit and a
digest per file; `tests/Unit/VendoredTokensTest.php` asserts them on every run. Edit
`resources/css/two.css` here and `pest` goes red. Refreshing it is a deliberate
two-line commit, which is the point.

---

## The merge gate — definition of done

All six. A rejection cites the box by number.

1. **Tests written first and passing.**
2. **Dusk journey green** — the journey the change touches, not just the suite.
3. **Design spec matched**, visually signed off by the Designer.
4. **Accessibility and performance budgets met** — the `budgets` job.
5. **Deployed to staging.**
6. **QA signed off.**

Boxes 1, 2 and 4 are machine-checked and are exactly what CI reports. Boxes 3, 5
and 6 are human, and QA confirms 3 with the Designer before passing it.

QA does not block on style preference. Only on the six boxes, and always by number.

---

## Deploys

`.github/workflows/deploy.yml`, designed against the hosting decision in TWO-37.

- **CI green on `main` → staging deploys automatically.** That is box 5, done for
  you.
- **This workflow deploys staging only.** Production is not in CI at all — see
  below. `workflow_dispatch` re-runs staging and takes no environment argument.
- **GitHub Actions never SSHes into a server.** No deploy key lives in CI. A deploy
  is one authenticated POST to a Forge webhook; Forge pulls on the box, migrates,
  and swaps the symlink. That constraint is the Web Lead's and it is a good one.
- The job **skips cleanly, green, when its secret is unset.** Nothing is
  provisioned yet. A missing deploy target must never look like a broken build —
  that is how a team learns to ignore a red X.

Secrets and variables to add once TWO-37 is approved and provisioned:

| Name | Kind | Value |
|---|---|---|
| `FORGE_STAGING_DEPLOY_HOOK` | secret | Forge staging deploy webhook URL |
| `STAGING_URL` | variable | e.g. `https://staging.togetherweown.com` |

Do not add a production deploy hook as a repo secret. Nothing reads it, and the
staging job logs a warning if one appears.

A 200 from Forge means the deploy was *queued*, not that it is live, so the job
then polls `/up` until the new release answers. Ten minutes of silence is a failure
and the previous release is one click away in Forge.

### Production deploys are manual, in the hosting dashboard

Not in GitHub Actions, and not because nobody has got round to wiring it. `two-web`
is **private on GitHub Free**, and on that plan environments cannot be configured at
all — GitHub's own words: *"any configured protection rules or environment secrets
will be ignored, and you will not be able to configure any environments."*

So the `environment: production` gate this file used to describe was not an
unconfigured approval. It was an **ignored** one. There is no settings page to visit
and no reviewer to add. The day somebody created the production hook, anyone with
write access could have opened Actions, clicked Run workflow, and shipped — no
approval, no prompt, no record. Four teams have write access. The release checklist
below would have become advisory and QA's sign-off decorative, with nobody editing a
line of code to make it happen.

A gate that fails silently is worse than no gate, because the file says the gate is
there. So the job is gone rather than guarded (TWO-91). Production ships by hand from
the hosting provider's dashboard, after the checklist below. Whoever holds that login
is the approval — real access control we are paying for either way, instead of a
simulation of one.

This is the same root cause as the two other holes on record: no branch protection,
and CODEOWNERS not routing reviews. Three symptoms, one plan. GitHub Team would
restore all three; that is a spend decision for the founder and it should be answered
alongside the hosting decision on TWO-37, before production exists rather than after.

Restore the job with `git revert` of the TWO-91 commit **only** once the repo is on a
plan that enforces environment protection rules. Do not hand-rebuild it.

---

## Proving the gate actually gates

A pipeline nobody has watched fail is a pipeline nobody knows works.

```bash
./ci/verify-pipeline.sh --lint            # offline, half a second, no gh — runs in `static`
./ci/verify-lint-selftest.sh              # proves --lint still catches things — runs in `static`
./ci/verify-pipeline.sh --assert-selftest # proves --run's assertions, and its cleanup, say what they claim
./ci/verify-pipeline.sh                   # dry run — prints what it would do
./ci/verify-pipeline.sh --run             # opens the PRs, waits, asserts, cleans up
./ci/verify-pipeline.sh --cleanup         # if a run was interrupted
./ci/verify-protection-selftest.sh        # proves the protection check catches holes — runs in `static`
./ci/verify-protection.sh                 # reads the live rule — by hand, needs Administration: read
```

The first six prove the *jobs* go red for the right reasons. That is not the same
as proving a red job blocks the merge — see "Reading the rule, not the list" above
for the fact those six never read.

`--lint` is the only thing watching the gate, so nothing downstream notices if it
quietly stops catching anything. `verify-lint-selftest.sh` is the check on the
check: it mutates a throwaway copy of the workflow one defect at a time and asserts
`--lint` goes red *for the stated reason*. Twelve cases, every one a mistake that has
actually been made on this repo or proposed for it. Both run in `static`, first,
before anything slow.

A thirteenth case is not about `--lint` at all: it runs `--assert-selftest`, which feeds
recorded check conclusions through the assertions `--run` makes and checks each is
accepted or rejected as intended. Those assertions otherwise execute only during a
live run, which is how a wrong one survived review and cost forty minutes of runner
time to find (TWO-94).

`--assert-selftest` covers cleanup for the same reason, with `gh` and `git` shadowed
so the real function runs against synthetic responses. Cleanup had the identical
defect in a blunter form: every call was `|| true` and the `closed PR #N` line
printed unconditionally, so the acceptance run reported nine pull requests closed
while closing none, and left three open titled "do not merge". Three cases now hold
it: all closes succeed, all closes fail, and — the one only a re-read catches —
`gh pr close` exits 0 while the pull request is still there. Cleanup reads the live
list at the end rather than trusting its own loop, which also sweeps up anything an
interrupted earlier run left behind.

Most of those cases are about the gate reporting a result that is not the pipeline's
result. One is not: `--lint` also fails if `dusk` or `budgets` stops running
`npm run build`. The PHP suite stubs Vite so it needs no build artifact (see
`docs/testing-strategy.md`), which is only sound while those two still build for
real. Drop it and nothing in the pipeline exercises a real manifest — with every job
still green, which is why a human will not notice and the lint has to.

It opens one deliberately broken PR per failure mode and asserts that the **named
job** went red — not merely that something somewhere was unhappy. A `budgets` job
that fails because the server never started looks identical in the UI to one that
caught a real LCP breach, and only one of those means the gate works.

| Breakage | Must be caught by |
|---|---|
| Badly formatted PHP | `static` |
| A type error at level 8 | `static` |
| A failing feature test | `pest` |
| A hex nudged in the vendored `resources/css/two.css` | `pest` |
| A heading hidden with CSS — visible in the HTML, invisible in the browser | `dusk` |
| An image with no alt text | `budgets` |
| Three seconds of server think-time before paint | `budgets` |
| Deleting the aggregate's `if: always()` | `static` — see below |
| Nothing wrong at all | nothing — goes green |

The Dusk case is hidden with CSS rather than deleted on purpose: the HTML still
contains the text, so the feature test passes and only the real browser notices.
A breakage that trips `pest` too would prove nothing about the browser job.

Seven of the eight cases also assert that the aggregate `tests` check went red, not
merely the named job. A job failing while the required check stays green is the one
failure mode that lets a broken PR merge while looking perfectly healthy.

### Which required check stops a gate-disarming pull request

The eighth case is the exception, and it is worth understanding rather than
memorising. It is the one case where `tests` is **not** what stops the pull request,
and it cannot be.

That case deletes `if: always()` from the aggregate. Deleting it is exactly what
makes the aggregate *skip* when a need goes red — so on that pull request `tests`
reports `skipped`, which GitHub counts as passed. No edit to `ci.yml` can make
`tests` go red on a pull request that removes the mechanism which would make it go
red. The first live run asserted `tests=FAILURE` there and failed while the gate was
working perfectly (TWO-94); the assertion was unreachable by construction.

**What actually stops it is `static`.** `static` runs `./ci/verify-pipeline.sh --lint`
as its first step, before anything slow; `--lint` fails on an aggregate with no
`if: always()`; and `static` is a required check in its own right. This is the entire
payoff of requiring the four leaves *as well as* the aggregate: protection does not
depend on the aggregate's guard staying correct, because the pull request that breaks
that guard is rejected by a different required check.

So the case now asserts what is load-bearing:

- `static` is red, **and** it is a required check — so something required is blocking;
- `tests` did **not** report `SUCCESS`. `skipped` is accepted, because that is what a
  deleted `if: always()` produces. `SUCCESS` is not: an aggregate that ran and passed
  over a red need is a genuine guard defect and still fails the case.

**Requiring `tests` alone would not cover this.** Reduce the required checks to the
aggregate — the shape a smaller protection rule naturally takes, and the shape
`setup-github.sh` originally had — and a pull request that disarms the merge gate
merges clean, with a green tick, because the only red job is not required. That is
not hypothetical: nothing is mechanically enforced on GitHub Free today (see
*Production deploys are manual* below), so this list is the plan for the day
protection becomes enforceable, and `static` is the load-bearing entry in it.

Two checks keep that argument from rotting, and they read different things:

- `--lint` check 9 reads *our list*. It fails if no job runs `--lint` on a pull
  request, and it fails if the job that does is not in `REQUIRED_CHECKS`.
- `./ci/verify-protection.sh` reads *the live rule* — see
  [Reading the rule, not the list](#reading-the-rule-not-the-list). Check 9 proves
  we intend `static` to be required; only that proves GitHub agrees.

`./ci/verify-pipeline.sh --assert-selftest` tests these assertions themselves,
offline, against recorded check conclusions — including the exact ones from the
acceptance run. That exists because the wrong assertion here was only reachable by a
forty-minute live run, so it survived review and cost a full run to find.

Run it when the repo lands, and again after any change to `ci.yml` that alters what
fails. **QA does not sign off TWO-22 until this has passed once, for real.**

## Release checklist

QA signs this off. Nothing reaches production without it.

This checklist **is** the approval gate. Nothing in GitHub enforces it on our plan
(see *Production deploys are manual* above), so it is enforced by the person who
triggers the deploy refusing to trigger it unsigned. Note the commit SHA you signed
off, and deploy that SHA.

- [ ] `main` is green — all of `static`, `pest`, `dusk`, `budgets`, and the `tests` aggregate
- [ ] All six Dusk journeys present and passing, including the degraded path
- [ ] Flake rate for the week is zero, or every open flake has an issue and a decision
- [ ] Staging deployed from this exact commit, and smoke-tested by hand
- [ ] Integration run against the **staging** bot on the **staging** Discord server (TWO-25)
- [ ] Degraded path verified for real: bot killed on staging, site still renders,
      counters fall back, queued actions retry, member sees a clear message
- [ ] Manual accessibility pass — keyboard only, and a screen reader on the join path
- [ ] Budgets met on staging, not only on the CI runner
- [ ] Designer has signed off (box 3)
- [ ] Migrations reviewed for a safe forward path, and a rollback that is understood
- [ ] No secret in the diff, no secret in the history
- [ ] Someone is available to watch it after it goes out
- [ ] **Then, and only then:** the production deploy is triggered by hand in the
      hosting dashboard, on the signed-off SHA, by the person holding that login

If a deadline would require shipping something that has not passed this, that goes
to the CEO in writing. It is not QA's trade-off to make alone, and it is not the
Lead's either.

See also: [testing-strategy.md](testing-strategy.md), [flake-policy.md](flake-policy.md).
