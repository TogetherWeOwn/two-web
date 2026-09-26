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
| `budgets` | Lighthouse mobile + axe-core at 360px and 1280px | LCP ≥ 2.0s, CLS ≥ 0.1, server response ≥ 600ms, or any WCAG 2.2 AA violation |
| `tests` | aggregates the four | any of them is not green, including *skipped* |

All five are required checks on `main`, plus `gitleaks` from `secret-scan.yml`.

`static` is deliberately first to finish — it catches the ordinary mistakes in under
a minute so you are not waiting on Dusk to be told about an unused import.

Locally, `composer check` runs the first two.

### Everything runs on GitHub-hosted runners

All nine jobs across the five workflows use `runs-on: ubuntu-latest` for
publication preparation ([TOG-4818](/TOG/issues/TOG-4818)). There is no self-hosted
fallback. Hosted quota exhaustion is a blocked run, never permission to route
untrusted PR code to the VPS or to incur unapproved spend.

**Disposable jobs, explicit services.** PHP 8.4 and Node 22 are installed by the
workflow. Dusk installs Chrome; service containers use dynamic Postgres ports.
The existing port allocation, reclaim/teardown and job-local opcache settings
remain as defensive harness behavior, not as a dependency on a shared host.
Performance and accessibility budgets, required check names and aggregate
failure handling are unchanged.

**Every job records where it ran.** After checkout, `ci/attest-runner.sh` rejects
anything other than `RUNNER_ENVIRONMENT=github-hosted` and emits a runner-name
annotation readable with `checks:read`:

```
GET /repos/TogetherWeOwn/two-web/check-runs/{id}/annotations
notice  runner  job=budgets runner_name=GitHub Actions 1 environment=github-hosted
```

This is evidence and regression detection, **not a security boundary against a
malicious workflow edit**. Before publication, an authorized administrator must
verify the repository cannot access VPS/self-hosted runner groups or repository
runners, and that no organization secrets are granted to it. Workflow text cannot
prove those settings. A green hosted run does not establish that access readback.

**PR CI has no custom secrets.** Dusk screenshots upload to GitHub Actions on both
pass and fail; the optional board-token upload is removed, including for same-repo
PRs. Reviewers may register the resulting artifact on the board separately. Only
the isolated staging deploy workflow references deployment credentials; those
must be repository-scoped, not inherited organization secrets.

**Deploy remains separate and fail-closed.** Automatic staging deployment requires
a successful CI run triggered by a push to this repository's `main`, not a PR run
or a fork's identically named branch. Manual dispatch is main-only. It checks out
trusted `main`, never a PR head or a PR-produced artifact. Missing target settings
still fail; publication preparation does not provision targets or authorize any
production deployment. `node ci/publication-isolation-selftest.mjs` tests this
boundary and mutation-controls each deploy guard.

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

One more assertion sits alongside them, and it is not a CEO budget:

- **Server response time < 600ms** for the main document.

That one is there because the LCP budget does not cover a slow server, which is
not obvious and cost us a green build over a three-second homepage (TWO-93).
Lighthouse runs with `throttlingMethod: 'simulate'`: it does not report the
timings Chrome observed, it rebuilds them, and it models a single server response
time per origin — the median across every request to that origin. The homepage
asks `127.0.0.1` for a document plus a stylesheet, a script, a font and a favicon,
and the last four come off disk in a millisecond. Median: one millisecond, applied
to the document too. A homepage that really took three seconds to answer simulated
in comfortably under 2.0s. The `server-response-time` audit reads the observed TTFB
straight off the document's network record and never touches the simulator, so it
is what actually goes red. 600ms is Lighthouse's own threshold for that audit, well
clear of the 2.0s budget: a tripwire for a server on the floor, not a second
performance target.

All three numbers are pinned by `verify-pipeline.sh --lint`, so relaxing one,
downgrading it to a warning or deleting it fails `static` rather than passing
quietly. The lint pins **the aggregation as well as the number**, and that is not
belt-and-braces: lhci defaults `aggregationMethod` to `'optimistic'`, which for a
`maxNumericValue` assertion compares the *best* of the three runs instead of the
median. Swapping one word turns every budget into best-of-3 while the threshold in
the diff still reads 2000, so `aggregationMethod: 'median'` is load-bearing and the
lint pins the whole options object, not just the number (TWO-101).

It pins it by **loading the config through node and reading the value lhci will
read**, rather than by matching the text of the file. Matching text was guessing:
`'largest-contentful-paint'` is one of four ways JavaScript spells that key, and a
second entry written any of the other three ways — double-quoted, computed, or
spread in — becomes the effective budget while leaving the pinned line word for word
intact, because an object literal keeps the last entry for a key. Three of the four
went straight past the old pattern (QA, TWO-101), and widening it could never have
caught the spread, which has no key to match. The check now runs the same
`require()` lhci does and compares what comes back, so there is no fourth spelling
to miss. If `ci/lighthouserc.cjs` cannot be loaded at all, `--lint` goes red for
that too — a budget that cannot be read is not being enforced.

One known gap, so nobody has to rediscover it: a duplicate entry that is *identical*
to the pinned one passes, because the effective budget is still the right budget.
Verified, not assumed. It goes red the moment either line is edited, which is the
moment a second entry starts mattering.

Each budget also has a live case behind it in `verify-pipeline.sh`, which matters
because the two performance cases go red for different reasons and only one of them
is about LCP:

| Case | What actually reddens `budgets` |
|---|---|
| `slowserver` — three seconds of server think-time | `server-response-time`, for the reason above. **Not** `largest-contentful-paint`. |
| `lcp` — a 1.6 MB uncompressed hero above the fold | `largest-contentful-paint`, and nothing else |

The `lcp` case exists because without it the CEO's headline budget has no live proof
that it fires at all: `slowserver` is a server-side breach, so a broken
`largest-contentful-paint` assertion would be invisible to every job in the pipeline.
Measured on the settings in `ci/lighthouserc.cjs`, varying only the image:

| Above-the-fold image | Simulated LCP | |
|---|---|---|
| none | 752ms | |
| 148 KB | 1653ms | under budget |
| 1.6 MB | 9153ms | **4.6× over the 2.0s budget** |

CLS stays at 0 and `server-response-time` at single-digit milliseconds throughout, so
LCP is the only assertion that fails and the case proves the thing it is named for.
It is deterministic: under `simulate` the number comes from the byte count, not from
how fast the runner happened to be.

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

`.github/workflows/deploy.yml`. The deploy layer is **Coolify, self-hosted** (owner
decision, 2026-08-31 — TOG-780), which supersedes Forge (TOG-407 closed).

- **CI green on `main` → staging deploys automatically.** That is box 5, done for
  you.
- **This workflow deploys staging only.** Production is not in CI at all — see
  below. `workflow_dispatch` re-runs staging and takes no environment argument.
- **GitHub Actions never SSHes into a server.** No deploy key lives in CI. A deploy
  is one authenticated POST to a deploy webhook; the panel pulls on the box,
  migrates, and swaps the release. That constraint is the Web Lead's and it is a
  good one — it is why changing hosts from Forge to Coolify altered the name of a
  secret and nothing else about this file.
- **The job fails when it has no deploy target.** `staging` is red today, on every
  push to `main`, and stays red until Coolify is provisioned. That is expected, and
  it is not a broken build.

> #### `staging` is red on purpose, and it used to be the other way round
>
> Until TOG-913 this job did the opposite: with no secret set it skipped every step
> and **exited green in about three seconds having deployed nothing**. The reasoning
> was written down and was not silly — *"a missing deploy target must never look like
> a broken build; that is how a team learns to ignore a red X"* — but it was a bet
> that the target would arrive soon, and it did not.
>
> On TOG-48 an agent read that green `staging` check as "it shipped" and told the
> owner the landing page was live on staging. Nothing had been deployed. The agent
> was wrong and CI had told them so, which makes it a CI defect rather than an agent
> one: **a control that reports success for work it did not do keeps producing false
> claims regardless of who is on shift.**
>
> It was also unfixable by waiting, because `FORGE_STAGING_DEPLOY_HOOK` was a Forge
> secret and Forge is gone. It would have skipped, silently, reporting success,
> forever.
>
> So green now means *deployed, and answering*. Do not re-add a skip-and-pass guard.
> `ci/deploy-target-selftest.sh` runs in `static` and fails the pull request that
> tries — both by executing the guard with no target and by rejecting any step in
> `deploy.yml` gated on a secret or variable being set.

Secrets and variables to add once Coolify is provisioned (TOG-780). **Both** are
required: a hook without a URL is refused, because a deploy that cannot be
health-checked is the same false green in a smaller box.

| Name | Kind | Value |
|---|---|---|
| `COOLIFY_STAGING_DEPLOY_HOOK` | secret | Coolify staging deploy webhook URL, token included |
| `STAGING_URL` | variable | e.g. `https://staging.togetherweown.com` |

Do not add a production deploy hook as a repo secret. Nothing reads it, and the
staging job logs a warning if one appears.

### What goes in the staging box's own `.env`

Not in GitHub. The hosting panel holds the environment file on the server, and these
are read by the application at runtime, so putting them in the table above does
nothing.

| Variable | Value on staging | If it is missed |
|---|---|---|
| `DISCORD_MODERATOR_ROLE_IDS` | `508654771276873729` | Nobody is a moderator. Every member signs in fine and the admin link is never offered — no error, no log line. |

`508654771276873729` is `SySOp` in the TWO guild, decided on TOG-106 and wired by
TOG-427. It is a public snowflake, not a secret. **Do not add the other five roles
that carry ban/kick** (`Staff`, `Officer`, `Game Master`, `Captain`, `Lieutenant`) —
all five are deleted in Wave 6 of the server redesign, and a deleted snowflake stops
matching silently. `SySOp` is kept, renamed to `Owner`, and the rename is harmless
because the match is by ID.

There is deliberately **no default for this in `config/services.php`**, unlike
`DISCORD_GUILD_ID`. Blanking the variable is the revocation path — it un-grants
everyone with no deploy — and a default in code would take that away and hand the
admin panel to SySOp holders on every checkout that never decided to.

The parsing is pinned by `tests/Feature/Auth/DiscordModeratorRoleIdsTest.php`; what
a moderator and a member actually see is pinned by
`tests/Browser/DiscordLoginTest.php`. Neither can tell you the variable is set on a
real box — so check it on the box, with:

```
php artisan discord:check-moderators --require-configured
```

Exit status 0 means the grant on that server is the one TOG-106 signed off. It
reads `config()`, not `env()`, which is the only way to get a true answer on a
host running `config:cache` — `env()` returns null there even when the `.env` is
correct, and it also misses a stale cache still serving a value the `.env` no
longer has.

It fails on a blank list, on any of the five deleted ban/kick roles, on a role
name typed where an ID belongs, and on a well-formed snowflake that simply is not
SySOp. Without `--require-configured` a blank list passes, because blank is the
revocation path and is correct in local dev. `--json` emits the findings for a
pipeline.

Two things it deliberately cannot check, and it says so on every run: the live
moderator-vs-member login round trip needs real Discord consent, and the current
SySOp holder count needs a bot token (TOG-13). Granting SySOp to a second person
grants them this panel too.

Put it in Forge's deploy script too, so it is not left to somebody remembering.
The deploy script runs **on the box**, which is the one place the check means
anything — GitHub Actions never SSHes in, so this cannot go in `deploy.yml`:

```
php artisan config:cache
php artisan discord:check-moderators --require-configured || exit 1
```

After `config:cache`, deliberately: that is the state the application will serve
from, and caching a `.env` that is missing the variable is itself one of the ways
this goes wrong. The `|| exit 1` is the point — Forge marks the deploy failed and
you find out at deploy time rather than the first time a moderator says the admin
link is missing.

**The moderator panel does not go past staging until TOG-355 lands** (admin-panel
reads of member data must be logged first). That is a condition of the security
ruling, not a preference.

A 200 from Coolify means the deploy was *queued*, not that it is live, so the job
then polls `/up` until the new release answers. Ten minutes of silence is a failure,
and the previous release is one rollback away in the Coolify dashboard.

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
./ci/verify-pipeline.sh --lint             # offline, half a second, no gh — runs in `static`
./ci/verify-lint-selftest.sh               # proves --lint still catches things — runs in `static`
./ci/verify-pipeline.sh --assert-selftest  # proves --run's assertions, and its cleanup, say what they claim — runs in `static`
./ci/verify-run-preconditions-selftest.sh  # proves --run still refuses, and --cleanup still clears — runs in `static`
./ci/verify-pipeline.sh                    # dry run — prints what it would do
cd "$(./ci/scratch-clone.sh)"              # --run needs a checkout of its own — see below
./ci/verify-pipeline.sh --run              # opens the PRs, waits, asserts, cleans up
./ci/verify-pipeline.sh --cleanup          # if a run was interrupted — clears every ci-verify/*
./ci/verify-protection-selftest.sh         # proves the protection check catches holes — runs in `static`
./ci/verify-protection.sh                  # reads the live rule — by hand, needs Administration: read
```

Everything above the last two proves the *jobs* go red for the right reasons. That
is not the same as proving a red job blocks the merge — see "Reading the rule, not
the list" above for the fact none of them ever read it.

`--run` is the expensive one, and it got more expensive when the `lcp` case split
off `slowserver`: **eleven pull requests** now, ten deliberate breakages and one
clean control, each waiting on a full CI run. They run concurrently, so the cost is
one CI run's wall clock plus the pushes, not eleven of them. Measured end to end on
2026-08-25 against `72f3dea`: **under five minutes**, of which the clean control's
own six checks were 2m34s. "Budget most of an hour", which this used to say, was a
guess written before anyone had sat through one — and it was the reason to put the
run off. Do not put it off; sit with it. One of the eleven also pushes a 1.6 MB image on
purpose — the `lcp` breakage below — and since TWO-109 `--cleanup` deletes the
`ci-verify/*` refs as well as closing the pull requests, so that blob does not
outlive the run.

`--lint` is the only thing watching the gate, so nothing downstream notices if it
quietly stops catching anything. `verify-lint-selftest.sh` is the check on the
check: it mutates a throwaway copy of the workflow one defect at a time and asserts
`--lint` goes red *for the stated reason*. Twenty cases, every one a mistake that has
actually been made on this repo or proposed for it. Both run in `static`, first,
before anything slow.

Three further cases do not mutate the workflow at all. One runs the unmutated repo and
asserts it still lints green. One greps `verify-pipeline.sh` for `producer | grep -q
pattern` and fails on a hit: `grep -q` exits the instant it matches, a producer with
output still buffered takes SIGPIPE, `set -o pipefail` hands 141 up as the pipeline's
status, and a check that *found what it was looking for* reports red. That is invisible
in review and reproduces on maybe one run in three, so the ban is only a ban while
something enforces it (TWO-87).

The third runs `--assert-selftest`, which feeds recorded check conclusions through the
assertions `--run` makes and checks each is accepted or rejected as intended. Those
assertions otherwise execute only during a live run, which is how a wrong one survived
review and cost forty minutes of runner time to find (TWO-94).

`static` also runs `--assert-selftest` directly, as its own step, immediately after
this one (TOG-8). That is deliberately a second route to the same 29 cases — 17
assertion, 9 wait, 3 cleanup — and neither is redundant in the way that word usually
means. This case reaches them through a fixture copy, which is what proves the lint
and the acceptance script agree about the file on disk; the named step reaches the
working tree directly, and states the dependency somewhere a reader of `ci.yml` can
see it. Before TOG-8 the coverage existed only here, hanging off one line inside a
case about *linting*, where nothing recorded that deleting it would silently retire
every test of `--run`.

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
| Three seconds of server think-time before paint | `budgets` (via `server-response-time`) |
| A 1.6 MB uncompressed hero image above the fold | `budgets` (via `largest-contentful-paint`) |
| A budget threshold relaxed, downgraded to a warning, deleted, shadowed by a second entry, or its aggregation swapped | `static` |
| Deleting the aggregate's `if: always()` | `static` — see below |
| A credential committed to a tracked file | `gitleaks` — see below |
| Nothing wrong at all | nothing — goes green |

The Dusk case is hidden with CSS rather than deleted on purpose: the HTML still
contains the text, so the feature test passes and only the real browser notices.
A breakage that trips `pest` too would prove nothing about the browser job.

Seven of the nine cases also assert that the aggregate `tests` check went red, not
merely the named job. A job failing while the required check stays green is the one
failure mode that lets a broken PR merge while looking perfectly healthy.

### Which required check stops a gate-disarming pull request

The `gate` case is the first of two exceptions, and it is worth understanding rather
than memorising. It is the one case where `tests` is **not** what stops the pull
request, and it cannot be.

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

### Which required check stops a committed credential

The `secret` case is the second exception, and it is the same argument stripped down
to its last plank. `gitleaks` is not a job in `ci.yml` — it comes from
`secret-scan.yml` — so it is in no aggregate's `needs:` and nothing it does can make
`tests` red. On that pull request every job in `ci.yml` is green, `tests` is green,
and the only thing standing between a committed credential and `main` is that
`gitleaks` is a required check in its own right.

So the case asserts three things:

- `gitleaks` is red;
- `gitleaks` is a required check — with it off the list, *every* required check on
  that pull request is green and the credential merges;
- `tests` is **green**, not merely not-red. A breakage that also took a `ci.yml` job
  down would satisfy the first two lines while proving nothing about the secret
  scan, which is why the credential goes in a root-level `.txt` no other job reads.
  Same reasoning as hiding the Dusk heading with CSS instead of deleting it.

The value it commits is fake and is shaped to match **our** `discord-bot-token` rule
in `.gitleaks.toml`, not one of gitleaks' stock patterns. That is deliberate: the
string is verifiably *not* a finding under the default rule set alone, so a run in
which `.gitleaks.toml` stopped being read — renamed, unparsed, or allowlisted into
silence — goes green here and the case catches it. A stock AWS key would have gone
red in that run and told us nothing.

`break_secret()` assembles that value from three fragments at runtime rather than
holding it as a literal, and that is not style. `gitleaks git .` reads the whole
history and `ci/verify-pipeline.sh` is part of it, so a credential-shaped literal in
that file is a finding *in that file*: the pull request adding this case would fail
the check it exists to prove, and after a merge every pull request against `main`
would fail it forever, because the string would be in the history. Deleting the line
later does not help. The only other way out is an allowlist entry, and widening the
allowlist to make room for a secret-scan test is exactly the trade `.gitleaks.toml`
tells you not to make. If someone inlines it for readability, `gitleaks` goes red on
that pull request and names the file — re-split it, do not allowlist it.

What that trade costs, said plainly: this case does not prove `useDefault = true` is
still on. One check-run conclusion is one bit, so a pull request that trips both rule
sources cannot say which one fired, and the repo-specific rules are the half with no
other coverage anywhere. The trade stands; the other half is covered offline instead,
by check 11 below.

### That the scan is still looking for more than three things

`.gitleaks.toml` opens with `[extend] useDefault = true`, and that one line is what
keeps every provider pattern gitleaks maintains — AWS, GCP, Stripe, GitHub PATs,
private keys — switched on here. Delete it and the scan falls back to our three
hand-written Discord rules and nothing else, with every job in the pipeline green
while it happens. The live `secret` case above cannot see this, by design, so
`--lint` check 11 reads the config directly (TOG-297). It asserts four things:

- `[extend] useDefault` resolves to the boolean `true`;
- `discord-bot-token`, `discord-mfa-token` and `discord-webhook` are still defined —
  the stock rule set has no Discord pattern of its own, so deleting one of those
  blocks removes the only thing anywhere looking for that value;
- `[extend] disabledRules` is empty. It subtracts from the set being extended, so
  every entry is one provider pattern carved back out of `useDefault = true` while
  that line goes on reading correctly;
- nothing in `[allowlist] regexes` or `paths` matches the empty string. A pattern
  that matches inside the empty string matches inside every string, so one bare `.*`
  there is an off switch for the entire scan.

It **parses the file with a TOML parser** rather than matching its text, for the same
reason check 10 loads `lighthouserc.cjs` through node. Three of these leave
`useDefault = true` in the file word for word, and all were measured against the
pinned gitleaks 8.30.1 on a repository holding an AWS key pair and a real-shaped
Discord bot token — three findings on the real config:

| edit | findings left |
|---|---|
| *(unmodified control)* | `aws-access-token`, `generic-api-key`, `discord-bot-token` |
| `useDefault` line deleted | `discord-bot-token` |
| a `[[rules]]` stanza inserted **above** the line, re-homing the key | `discord-bot-token` |
| `useDefault = "false"` — a string, loads clean, exits 0 | `discord-bot-token` |
| `disabledRules = ['aws-access-token']` | `generic-api-key`, `discord-bot-token` |
| `discord-bot-token` rule block deleted | `aws-access-token`, `generic-api-key` |
| `regexes` or `paths` gains a bare `.*` | **nothing at all** |

Only the first of those is visible to a grep for the line.

One thing worth writing down because the obvious guess is wrong: `disabledRules`
reaches the **default** rules only. An entry naming our own `discord-bot-token` does
nothing — the rule goes on firing — which is also why a self-test case built on that
would have gone red while the scan it claimed to protect was unharmed. Measured, not
assumed. If a stock rule genuinely false-positives on this repository, as
`discord-client-id` does on public snowflakes, allowlist the specific value rather
than disabling the pattern.

`--lint` also goes red if `.gitleaks.toml` is missing, if it cannot be parsed as
TOML, or if `python3` is not on `PATH` — a config that cannot be read is not being
enforced, same rule as the budgets. `tomllib` has been in the standard library since
3.11 and the runners are Ubuntu 24.04 (3.12), so this needs no install step in
`static`.

Until this case existed, `gitleaks` was the one required check with no live proof it
fails — a check nobody had watched work, guarding the one kind of breakage a revert
does not undo (TOG-20). It was seen catching a real finding by hand when it was
first wired up (TWO-39), which is exactly the standard the rest of this file was
built to replace.

### What the first live run of this case found

The first `--run` after the case landed did what the case was added to do, and then
found a second thing nobody was looking for. Both are worth keeping.

The `secret` case passed: `gitleaks` went red on its pull request, alone, with `tests`
still green. And the **clean** pull request went red on `gitleaks` at the same time —
reporting `ci-verify-credential.txt` at a commit that belonged to a *different* pull
request, one whose branch the clean run had never touched.

`gitleaks git .` does not scan the branch you are on. It scans **every ref in the
clone**, and `fetch-depth: 0` fetches all of them. So while `ci-verify/secret` sat on
origin — which is the whole of its job — every open pull request in the repository
scanned it and went red, each naming a file its author had never created and could
not remove by changing their own branch. The comment in `secret-scan.yml` asserted
the opposite ("walks every commit reachable from HEAD"), and had done since the job
was written; the scan had simply never had a secret on a sibling branch to find.

Confirmed against the pinned 8.30.1 on a two-branch repository built for the purpose:
with the credential on a side branch and `HEAD` clean, `git log -p HEAD` matches
nothing and the scan still reports `2 commits scanned` and `leaks found: 1`.

The fix is `--log-opts=HEAD` on the scan. It restores the documented behaviour and
keeps the property `fetch-depth: 0` is there for — a secret added and then deleted
earlier in HEAD's own history is still caught, verified in the same repository. It
narrows what a pull request scan reads; it does not narrow what can reach `main`,
because anything merging to `main` is in HEAD's history for both the pull request
scan and the `push: main` scan. What it gives up is a secret on a branch that never
approaches `main`, which a per-pull-request required check was never the right
instrument for — the author it blocks is not the author who can fix it.

This is the argument for the whole file in miniature. The gate had a mode in which it
went red for a reason unrelated to the change under review, and no amount of reading
it found that; opening a pull request that deliberately breaks one thing, and then
insisting the *clean* one comes back green, is what found it.

`./ci/verify-pipeline.sh --assert-selftest` tests these assertions themselves,
offline, against recorded check conclusions — including the exact ones from the
acceptance run. That exists because the wrong assertion here was only reachable by a
forty-minute live run, so it survived review and cost a full run to find.

Run it when the repo lands, and again after any change to `ci.yml` that alters what
fails. **QA does not sign off TWO-22 until this has passed once, for real.**

### One `--run` at a time

Every case uses a fixed branch name — `ci-verify/pint`, `ci-verify/gate`. Two runs
at once therefore share branches: the second push lands on the first run's branch
and silently changes its PR's head commit mid-flight, and whichever `cleanup()`
finishes first closes and deletes *both* runs' work. The survivor is then told its
checks never reported on a branch that no longer exists — a dead-pipeline diagnosis
for something that was never about the pipeline. This happened on 2026-08-20:
nine `ci-verify/*` PRs opened and closed under an unrelated run (TWO-103).

So `--run` refuses to start when any `ci-verify/*` pull request is open or any
`ci-verify/*` branch is on the remote, and names what it found. Unique per-run
branch names would let both proceed, and two people running the acceptance suite
at once is a thing to notice, not a thing to support. If the branches are leftovers
from a run that was killed, `--cleanup` clears them — **all** of them, by the same
`ci-verify/*` glob the guard refuses on, not just the case names this checkout
happens to ship. Those were different sets until TWO-109: a `ci-verify/*` branch
whose slug was not in `CASES` was blocked on forever and cleaned never, and
`--cleanup` exited 0 without saying so. It now prints what it removed, or `nothing
to clean`. The script has no `trap`, so a Ctrl-C or a dropped connection leaves
branches behind — this is the ordinary way a run ends badly, not an edge case.

If one of the two live-run probes cannot be read — `gh pr list` returning an API
error looks exactly like an empty list — the run starts anyway and says which probe
it could not read, rather than printing a pass it did not earn. That is safe only
because `open_pr()` pushes the branch *before* it opens the pull request, so the
`ls-remote` half sees the same state over a different transport. If `ls-remote` is
the half that failed there is nothing left to fall back on, and `--run` refuses.

`--run` has six such preconditions, all of which fire in the first second rather
than forty minutes in: it is running in a checkout of its own (see below), `gh` is
authenticated, `origin` is a GitHub repository (not a workspace clone — the results
come from the GitHub Actions API), the working tree is clean, the Actions API is
readable, and no other run is live.
`verify-run-preconditions-selftest.sh` is the check on those: it builds a throwaway
repo whose `origin` reads as GitHub while its bytes go to a bare repo next door,
stubs `gh`, and asserts each guard refuses for its own reason — and that nothing
was pushed on the way out. Seventeen cases: one per precondition, two for the
live-run guard (an open pull request and a leftover branch refuse independently),
three for the scratch-clone guard (a shared checkout, another run's clone, and that
it stays quiet outside Paperclip), one pinning that the scratch-clone guard is
checked *before* the dirty-tree one, a negative control proving none of them fires
on a normal repository, three for an unreadable probe, and three for `--cleanup`,
because a refusal that points at a recovery command has to be a refusal that command
can actually clear. Every case is mutation-checked: breaking the guard it covers
reddens that case and no other.

### The workspace checkout is not private to a run

An agent's workspace directory is shared by every run of that agent, and runs
overlap — one heartbeat can still be finishing while the next has started. So a
`git checkout`, `git am`, `git rebase` or `git reset --hard` in that directory is a
write to another run's working tree.

**A run must not do branch work in the workspace checkout.** Clone it:

```bash
cd "$(./ci/scratch-clone.sh)"
```

That is `git clone --shared` into `$PAPERCLIP_RUN_SCRATCH_DIR` — no network, no copy
of the object store, a couple of hundred kilobytes, and Paperclip deletes it when the
run ends. The clone keeps the source's `origin` (GitHub, which is what `gh` and the
Actions API need), keeps the path it came from as a remote called `workspace`, copies
the git identity — which is set per-repository in these workspaces, not globally, so a
plain clone cannot commit — and stamps the owning run id into `paperclip.runScratch`.
`--run` refuses to start unless it finds its own id there, which is what makes this a
gate rather than a note in a document.

This is TWO-103 one level up: the same cause, a fixed name that nobody owns. It bit
twice on 2026-08-20, both times inside a single agent's own workspace. One tree was
checked out, `git am`-ed, aborted and hard reset onto a fetched head three minutes
after a commit; nothing was lost only because the work was already pushed. In the
other, nine `ci-verify-two97/*` branches were committed and pushed out of a workspace
after the run that owned it had ended.

`reset --hard` and `checkout` leave a reflog entry for committed work. For
uncommitted work they leave nothing at all — no undo, no trace, and no way to tell
afterwards that anything was there. That is why this is a different directory rather
than a rule to remember.

One caveat with `--shared`: the clone borrows the source's object store rather than
copying it, so a `git gc --prune` in the source can remove objects the clone was
reaching through it. Commits pushed to `origin` are safe. A scratch clone kept past
the end of its run is not — another reason `--run` refuses to use one it does not own.

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
- [ ] `php artisan discord:check-moderators --require-configured` exits 0 **on the
      box being deployed** — not on a runner, not locally. This is the only check
      that reads the environment the application will actually serve from, and the
      misconfiguration it catches is invisible: the site is up, login works, and
      the admin panel is offered to nobody with no error anywhere
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
