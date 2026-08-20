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
three `verify-pipeline.sh` needs, and a 404 from a token without it is
indistinguishable from "the branch is not protected". Check the permission before
believing the second reading.

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
- **Production is manual** — `workflow_dispatch`, through a GitHub environment with
  required reviewers. Never automatic.
- **GitHub Actions never SSHes into a server.** No deploy key lives in CI. A deploy
  is one authenticated POST to a Forge webhook; Forge pulls on the box, migrates,
  and swaps the symlink. That constraint is the Web Lead's and it is a good one.
- Both jobs **skip cleanly, green, when their secret is unset.** Nothing is
  provisioned yet. A missing deploy target must never look like a broken build —
  that is how a team learns to ignore a red X.

Secrets and variables to add once TWO-37 is approved and provisioned:

| Name | Kind | Value |
|---|---|---|
| `FORGE_STAGING_DEPLOY_HOOK` | secret | Forge staging deploy webhook URL |
| `FORGE_PRODUCTION_DEPLOY_HOOK` | secret | Forge production deploy webhook URL |
| `STAGING_URL` | variable | e.g. `https://staging.togetherweown.com` |

A 200 from Forge means the deploy was *queued*, not that it is live, so both jobs
then poll `/up` until the new release answers. Ten minutes of silence is a failure
and the previous release is one click away in Forge.

---

## Proving the gate actually gates

A pipeline nobody has watched fail is a pipeline nobody knows works.

```bash
./ci/verify-pipeline.sh --lint       # offline, half a second, no gh — runs in `static`
./ci/verify-lint-selftest.sh         # proves --lint still catches things — runs in `static`
./ci/verify-pipeline.sh              # dry run — prints what it would do
./ci/verify-pipeline.sh --run        # opens the PRs, waits, asserts, cleans up
./ci/verify-pipeline.sh --cleanup    # if a run was interrupted
./ci/verify-protection-selftest.sh   # proves the protection check catches holes — runs in `static`
./ci/verify-protection.sh            # reads the live rule — by hand, needs Administration: read
```

The first five prove the *jobs* go red for the right reasons. That is not the same
as proving a red job blocks the merge — see "Reading the rule, not the list" above
for the fact those five never read.

`--lint` is the only thing watching the gate, so nothing downstream notices if it
quietly stops catching anything. `verify-lint-selftest.sh` is the check on the
check: it mutates a throwaway copy of the workflow one defect at a time and asserts
`--lint` goes red *for the stated reason*. Ten cases, every one a mistake that has
actually been made on this repo or proposed for it. Both run in `static`, first,
before anything slow.

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
| Nothing wrong at all | nothing — goes green |

The Dusk case is hidden with CSS rather than deleted on purpose: the HTML still
contains the text, so the feature test passes and only the real browser notices.
A breakage that trips `pest` too would prove nothing about the browser job.

Each case also asserts that the aggregate `tests` check went red, not merely the
named job. A job failing while the required check stays green is the one failure
mode that lets a broken PR merge while looking perfectly healthy.

Run it when the repo lands, and again after any change to `ci.yml` that alters what
fails. **QA does not sign off TWO-22 until this has passed once, for real.**

## Release checklist

QA signs this off. Nothing reaches production without it.

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

If a deadline would require shipping something that has not passed this, that goes
to the CEO in writing. It is not QA's trade-off to make alone, and it is not the
Lead's either.

See also: [testing-strategy.md](testing-strategy.md), [flake-policy.md](flake-policy.md).
