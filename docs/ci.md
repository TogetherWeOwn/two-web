# CI, the merge gate, and deploys

**Red means nobody merges.** Not "nobody merges unless it's urgent."

---

## What runs on every pull request

`.github/workflows/ci.yml`. Five jobs in parallel, plus a gate.

| Job | What it does | Fails when |
|---|---|---|
| `static` | gate wiring, Pint `--test`, PHPStan level 8 | the gate stops gating, formatting drifts, or types do not hold |
| `pest` | Pest unit + feature, real Postgres 17 | any test fails |
| `dusk` | Laravel Dusk, real Chrome, real server | any journey fails |
| `budgets` | Lighthouse mobile + axe-core at 360px and 1280px | LCP ≥ 2.0s, CLS ≥ 0.1, server response ≥ 600ms, or any WCAG 2.2 AA violation |
| `deps-audit` | `composer audit` + `npm audit` on the lockfiles | a high/critical advisory, or an advisory with no severity |
| `tests` | aggregates the five | any of them is not green, including *skipped* |

Seven required checks on `main`: the five ci.yml leaves, the `tests` aggregate, plus `gitleaks` from `secret-scan.yml`.

`static` is deliberately first to finish — it catches the ordinary mistakes in under
a minute so you are not waiting on Dusk to be told about an unused import.

Locally, `composer check` runs the first two.

### Pull-request triggers are frozen (TOG-12060)

`ci.yml` and `pr-lint.yml` run on pull requests only via the opt-in `labeled`
trigger while legacy development is frozen. Opening, pushing to, editing or
reopening a PR runs only `secret-scan.yml` (`gitleaks`) and `codeowners.yml`;
every workflow still runs on push to `main`. The required checks are unchanged,
so a PR cannot merge until its head SHA has them. A maintainer adds any label to
the PR's exact head, and again (remove, then re-add) after every push or title/body
edit — the `labeled` event produces the checks as `pull_request` check runs, which
is what the required checks read. Dispatching the workflows is reviewer evidence
only: dispatched runs never enter the PR status rollup, so they do not satisfy the
required checks (TOG-12971). To unfreeze, widen the `pull_request` `types:` back to
the automatic events; `ci/verify-pipeline.sh` check 14 fails while only `labeled`
may run them.

### Everything runs on GitHub-hosted runners

Every job in every workflow is `runs-on: ubuntu-latest` — GitHub-hosted runners
(TOG-8909; the pre-flip self-hosted gate was TOG-2847). This repository is public,
so hosted minutes are free, and the private `two-selfhosted` runner group cannot
serve a public repo at all. `ci/attest-runner.sh` holds that contract; a job on
any other runner fails its first step. There are no `self-hosted` jobs in this
repository and no other fallback: a job that lands off the hosted runners does
not cost a little extra, it is misrouted and fails before its first step.

Three consequences you will actually run into:

**The runners are ephemeral.** Fresh VM per job: clean checkout, no ports in use,
no processes left over from a previous run. A process a job leaks dies with the
job instead of breaking the *next* run — but stop what you start with
`if: always()` anyway, so the shutdown is visible in the logs when you need it.

**Each job gets its own VM.** No shared network namespace, so parallel jobs never
bind the same socket and fixed host ports cannot collide between them.
`ci/runner-ports.sh` still derives the per-job port block — keep using it rather
than hardcoding a port. For service containers, map with no host port
(`ports: ["5432"]`) and read `${{ job.services.postgres.ports[5432] }}`.

**Every job attests where it ran.** `ci/attest-runner.sh` runs as the first step of
every job in every workflow and fails on a non-hosted runner — `runs-on:` is only
a request, and a label typo silently reroutes rather than erroring. It also emits
the runner name as a `::notice`, which lands in the check-run *annotations* API.
That is deliberate: it makes the per-job runner readable with `checks=read`,
without the `actions:read` scope this repo's token broker does not issue.

```
GET /repos/TogetherWeOwn/two-web/check-runs/{id}/annotations
notice  runner  job=budgets runner_name=github-hosted-abc123 environment=github-hosted
```

The hosted image ships a broad toolset (git, curl, docker, common languages);
tool versions are pinned in the workflow (setup-php, setup-node), never assumed
from the image. Anything else, install it in the job — `dusk` installs Chrome
that way. The runner user has passwordless sudo, so
`sudo apt-get install -y <pkg>` works.

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

Seven, applied by the setup script (TWO-36). This is the list, and it is the same
list in `ci/verify-pipeline.sh` — `--lint` fails if the two disagree:

- `static` — Pint, PHPStan, and the gate's own wiring
- `pest` — unit + feature
- `dusk` — the browser journeys
- `budgets` — Lighthouse and WCAG 2.2 AA
- `deps-audit` — `composer audit` + `npm audit`, high/critical (TOG-8405)
- `tests` — the aggregate over the five above
- `gitleaks` — the secret scan

The five leaves are required *as well as* the aggregate, deliberately: protection
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

Set by the maintainers. Enforced as **failures, not warnings**.

- **LCP < 2.0s** on a mid-range phone profile — Moto G Power class, 4× CPU
  slowdown, simulated Slow 4G. Configured in `ci/lighthouserc.cjs`.
- **CLS < 0.1**, same profile.
- **WCAG 2.2 AA**, zero violations, in `ci/a11y.mjs`.

One more assertion sits alongside them, and it is not a maintainers' budget:

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

The `lcp` case exists because without it the maintainers' headline budget has no live proof
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
Frontend Engineer. It is a maintainers' decision, made in writing on the issue, and then
landed here as its own commit that says so. A threshold quietly relaxed inside a
feature PR is the specific thing this file exists to prevent.

### On the bundle budget

Two earlier defects are why the built assets have ceilings of their own. An
`axios` import nothing called cost 48 KB of the 49 KB bundle on every page
(TOG-53), and `hallmark.css` listed unconditionally cost a second render-blocking
stylesheet on pages that never render it (TOG-3233). Both merged while every
check stayed green, because no check was measuring the bytes.

`ci/bundle-budget.json` caps every vite `input:` entrypoint twice: raw bytes
and gzip bytes, measured against `public/build/manifest.json` — the assets the
pages really load. Raw catches dependency accidents; gzip is what the Slow 4G
profile actually pays for. Raising a ceiling is a deliberate edit in its own
commit that says what grew and why, plus the matching `--lint` pin — the same
standard as the Lighthouse budgets above.

Three layers, because each one covers a failure the others cannot see:

| Layer | Where it runs | What it catches |
|---|---|---|
| `node ci/check-bundle-budget.mjs` | `budgets`, right after `npm run build` | the built bytes over a ceiling — fails the job |
| `tests/Unit/ViteBundleBudgetTest.php` | `pest`, no build | an input with no budget, a stale ceiling, a non-positive number |
| `--lint` check 12 in `ci/verify-pipeline.sh` | `static` | a ceiling quietly raised, an entry deleted, the enforcement step dropped |
| `node ci/check-bundle-budget.mjs --selftest` | `static` | the checker itself going quiet — a neutered checker and a fitting bundle look identical otherwise |

Baseline (vite 7.3.6, 2026-09-27): app.css 62,560 raw / 11,719 gzip against
76,800 / 15,360; theme.css 342,710 / 33,013 against 375,000 / 38,000;
hallmark.css 10,853 / 2,651 against 15,360 / 4,096; app.js 1 / 21 against
5,120 / 2,048. theme.css is the tightest ceiling on purpose: it is Filament's
vendor stylesheet (342 KB of hand-authored component CSS, see the header of
`resources/css/filament/admin/theme.css`), so most of its bytes are not ours to
shrink — but a vendor upgrade that grows it is not our regression to absorb
silently either. ~9% raw / ~15% gzip headroom means an upgrade that meaningfully
grows the panel goes red and gets a decision, while patch releases pass.

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
3. **Design spec matched**, visually signed off by a maintainer against the
   design-system specs.
4. **Accessibility and performance budgets met** — the `budgets` job.
5. **Deployed to staging.**
6. **Maintainer signed off.**

Boxes 1, 2 and 4 are machine-checked and are exactly what CI reports. Boxes 3, 5
and 6 are human, and the reviewer confirms 3 before passing it.

Review does not block on style preference. Only on the six boxes, and always by number.

---

## Deploys

`.github/workflows/deploy.yml`. The deploy layer is **Coolify, self-hosted** (owner
decision, 2026-08-31 — TOG-780), which supersedes Forge (TOG-407 closed).

- **CI green on `main` → staging deploys automatically.** That is box 5, done for
  you.
- **Staging deploys automatically; production is dispatch-only plus
  reviewer-gated.** The `production` job runs only from `workflow_dispatch` with
  `production` chosen, on `main`, behind the `production` environment's required
  reviewer — enforceable on the org's Enterprise Cloud plan (verified TOG-6912
  via host-token readback TOG-7649: required reviewer Rick7C2,
  prevent_self_review, protected branches on; TWO-91 superseded). It stays
  gated until [TOG-6902] says TWO Web may go live.
  Every deploy in either job posts a post-deploy smoke (`bin/smoke-staging.sh`)
  against its URL.
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
| `CF_ACCESS_CLIENT_ID` | secret | Cloudflare Access service-token client ID, so the staging smoke probe passes the edge |
| `CF_ACCESS_CLIENT_SECRET` | secret | Cloudflare Access service-token secret, same purpose |
| `COOLIFY_PRODUCTION_DEPLOY_HOOK` | secret | Coolify production deploy webhook URL, token included. Only the reviewer-gated `production` job reads it (via `ci/deploy-target-production.sh`); the staging job warns and never touches it. Do not set until [TOG-6902] says TWO Web may go live. |
| `PRODUCTION_URL` | variable | e.g. `https://togetherweown.com` (public apex; also the production environment's display URL). Same gate as the hook. |

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

### Queue workers drain on deploy (TOG-7288)

Staging runs a second Coolify app, `two-web-staging-worker` (TOG-2625): a
`queue:work --queue=default --sleep=1 --tries=6 --timeout=30` daemon that shares
the web app's repository, environment, database and cache — no FQDN, no exposed
port. A web deploy never touches it, so without a drain a worker keeps
processing jobs on old code mid-deploy, and a deploy can strand running jobs.

The lever is the post-deployment command on `two-web-staging` (set once, in the
Coolify panel — [TOG-7486](/TOG/issues/TOG-7486)):

```
php artisan queue:restart
```

This broadcasts an `illuminate:queue:restart` timestamp through the default
cache. The worker daemon compares it after every job (`Worker::daemon` →
`stopIfNecessary`) and exits 0, and Coolify restarts the container — on the
worker app's *current* image, which is why the two-step release below
redeploys the worker app rather than trusting this signal alone. A
running job finishes — none is killed, none runs twice. Per-job `tries` still
win over the worker's `--tries` flag (`markJobAsFailedIfAlreadyExceedsMaxAttempts`
prefers the job's own `maxTries()`), so the restart changes *when* workers
recycle, not how often a job is attempted.

Post-deployment, not pre, and on the web app, not the worker: the signal must
fire after the new release answers, so respawned workers boot new code rather
than the release being replaced. This is a box-side setting, like the
moderator `.env` above — GitHub Actions never SSHes in, so it cannot go in
`deploy.yml`.

The bound, stated plainly: the signal restarts processes — it does not ship code. It recycles the worker onto whatever release the worker app is running —
if the worker app itself never redeploys, the worker runs new timestamps on old
code forever. Today both apps share the repository so they move together; if
that ever stops being true, the worker needs its own deploy or redeploy step,
and this section needs rewriting, not rereading.

A staging release is therefore two steps, in order — the web deploy never
rebuilds the worker app, so `queue:restart` alone recycles the worker onto
whatever release the worker app is already running:

1. Deploy `two-web-staging` (automatic on green `main`, or Redeploy in the
   dashboard). Wait for `GET <staging>/up` to answer 200 on the new release.
2. Redeploy `two-web-staging-worker` (Redeploy in the dashboard, or its deploy
   webhook) so the worker image matches the web release. The restart timestamp
   from step 1 is already broadcast; the fresh worker boots new code and the
   running job finishes first — none is killed, none runs twice.
3. Verify: the worker app shows a fresh container, staging `/up` answers 200,
   and `php artisan queue:check-depth --json` on the box drains toward 0.

Rollback is the mirror: roll the web release back in the dashboard, redeploy
the worker app so its image matches, then run `php artisan queue:restart` once
in the web container so the worker rejoins the rolled-back release. Rolling
back the web release without redeploying the worker leaves new-code workers
on an old release — the same skew in the other direction.

Pinned by `tests/Unit/QueueDrainOnDeployTest.php`, which asserts this section
still names the command, the placement, and the bound.

### Queue drill: prove a stuck queue and a dead job both surface (TOG-6948)

Two probes, one drill, run **on the staging box** after each deploy until the
worker story settles. Both read the box's own database, which is the one place
the answer means anything — CI never sees staging's queue.

1. **Stuck queue:** `php artisan queue:check-depth --json`. Pending climbing
   past `--warn` (default 20) is RSVP lag a member can see; past `--critical`
   (default 100) points at the worker being down rather than busy. The counting
   is pinned by `tests/Feature/Console/CheckQueueDepthCommandTest.php`.
2. **Dead job:** `php artisan queue:poison-probe --json`, then tail the log for
   `Queue job failed.` — one critical line per failed job, with the class,
   queue and exception message, logged by the `Queue::failing` listener in
   `AppServiceProvider`. The probe dispatches a self-failing job, runs the
   worker once against it, and reports the `failed_jobs` row it landed in.
   Clean the probe row up afterwards with `php artisan queue:forget <uuid>`
   (the uuid is in the probe output). Do not `queue:retry`: retry would
   restore the poison to its single-use isolated queue where no worker
   listens — re-run the probe for a fresh drill instead. The probe refuses
   to run while the app is down for maintenance, so run the drill with the
   app up.
   The chain is pinned by `tests/Feature/Console/QueuePoisonProbeTest.php`.

A real failure lands the same way: the worker already owns recovery (the
ten-minute `events:reconcile` pass re-dispatches what never mirrored), and the
critical line is the part that tells a tired person to go look. If neither
probe reports and no critical line appears, the queue is healthy — the drill
existing is what makes that reading trustworthy, in the same tradition as
`discord:check-moderators` above.

### Pre-deploy DB snapshot lives in Coolify, not in `deploy.yml` (TOG-9253)

Migrations run on the box, inside Coolify's `post_deployment_command`
(`php artisan migrate --force`), after the deploy webhook fires. The staging
database (`two-web-staging-db`) is not public and GitHub Actions never SSHes
in, so no step in `deploy.yml` can reach it — and `bin/pg-backup.sh` refuses
any host that is not local docker, so it cannot run there either. A snapshot
step in the workflow would be TOG-913 theater: green without doing anything.
That is why `deploy.yml` has no snapshot step, and why none should be added:
the snapshot is a Coolify database backup instead (provider-native `pg_dump`
custom format — the same shape `docs/runbook.md` restores from).

1. **Scheduled backup on `two-web-staging-db`**, daily, keeping the newest 7
   local copies — the same 7-daily rule `bin/pg-backup.sh rotate` enforces
   ([TOG-8418](/TOG/issues/TOG-8418)). Set once, in the Coolify panel
   (database → Backups → Add). Agents have no host access, so enabling it was
   the one host step for this wiring.
2. **Before any deploy carrying migrations: Backup Now** on the same schedule,
   and log the execution ID and size on the release card. The execution ID is
   the proof a snapshot exists; the deploy log's migration lines are the proof
   it ran before them. The release checklist (QA-owned, below) names whether a
   release migrates — when it does, this Backup Now is mandatory, not optional.
3. **Restore rehearsal stays quarterly on staging** (`docs/runbook.md`); a
   backup with no restore test is a rumour.

The bound, stated plainly: the schedule is cron-based, not per-deploy — a
deploy nobody flagged as migration-carrying has only the last daily snapshot
to fall back on. That is why step 2 keys off the checklist, not off the clock.

Pinned by `tests/Unit/PreDeploySnapshotDocTest.php`, which asserts this section
still names the schedule, the Backup Now rule, the bound — and that
`deploy.yml` still carries no snapshot step of its own.

### Who polls `/up`, and who gets paged (TOG-7327)

`/up` is the deploy health check (the funnel's `GET /up` in
`routes/funnel.php` replaces the framework's `health: '/up'` closure in
`bootstrap/app.php`, pinned by `tests/Feature/HealthCheckTest.php`). A ready
app answers 200; an unreachable database or pending migrations answers 503
`degraded` (TOG-8711), so the deploy poll fails instead of shipping a
not-ready box. What polls it today, and what does not:

- **Deploy time.** `deploy.yml` polls `${STAGING}/up` for up to ten minutes
  after triggering the Coolify staging deploy — a queued deploy that never
  answers fails the job, and a release whose database is unreachable or
  whose migrations are pending answers 503, failing the job the same way.
  Then `bin/smoke-staging.sh` asserts `/up` → 200
  again, alongside `/discord`, `/`, and `/events.json`. Green `staging`
  means the new release answered, and nothing more.
- **CI time.** The Dusk, budgets, and opcache jobs poll a local `/up` to
  learn when the throwaway `artisan serve` under test is ready, and how fast
  it answers. Each migrates before serving, so the poll also proves the
  schema is current. That proves the build boots; it says nothing about any
  deployed host.
- **Continuously, on any environment: nobody.** No cron, no scheduled
  workflow, and no third-party pinger polls `/up` on a deployed host (this
  repo uses no paid services) — the scheduled workflows that do exist,
  `codeowners.yml`'s weekly owners check and `deploy-records-prune.yml`'s
  weekly deployment-records prune (TOG-9273), have nothing to do with `/up`. When
  staging or production stops answering between deploys, nothing notices
  until a human loads the page or the next deploy's poll fails. The release
  checklist's "someone is available to watch it after it goes out" is,
  today, the entire paging policy: the person who triggered the deploy
  watches it by hand.

The bound, stated plainly: every `/up` poll in this repo is attached to a
deploy or a CI run. There is no standing watch, and this section must not be
read as one. Adding one is a small, free step for the day production exists
— a Coolify HTTP healthcheck on the app pointed at `/up`, or a scheduled
workflow that curls the production URL and files an issue on failure — but
that step is not taken here: no prod activation happens on this card, and no
monitor is wired to a host that does not exist yet.

Pinned by `tests/Unit/HealthMonitoringRunbookTest.php`, which asserts this
section still names each poller, the gap, and the bound.

### Error drill: prove a 500 pages (TOG-8730)

The queue drill proves dead jobs surface. This proves 500s do too — same
shape, different half: `php artisan error-alert:probe --json` throws a marker
exception through the `report` listener in `bootstrap/app.php` and reports
whether the `Unhandled exception.` alert fired and the repeat was muted (the
`ErrorAlertRateLimit` noise guard: one alert per exception class + route per
5 minutes). Then tail the log for the line. The cron watcher
(`bin/error-log-watch.sh`, every 5 minutes — docs/runbook.md "Error
alerting") scans the delta for that line and `Queue job failed.` and mails
on either, so a 500 in staging produces an operator-visible alert within the
documented path. The chain is pinned by
`tests/Feature/Console/ErrorAlertProbeTest.php`. No Sentry, no Flare, no
Bugsnag — none installed, none allowed.

### Deployment-records retention: newest 30 per environment, weekly (TOG-9273)

Every merge to `main` creates a GitHub Deployment record — the `environment:`
keys in `deploy.yml` do that implicitly, with statuses — and nothing ever
deleted them: 148 at the TOG-7649 readback, +1 per deploy after. The Shipping
KPI reads these records, so the fix is retention, not silence: keep the
newest 30 per environment, delete the rest, oldest first. This is NOT
[TOG-8728](/TOG/issues/TOG-8728), which is log rotation on a different store —
the gap list names this one separately and unowned.

- **The schedule is `.github/workflows/deploy-records-prune.yml`**, Sundays
  06:17 UTC (off the hour, like `codeowners.yml`: GitHub drops scheduled runs
  under load at popular times, and a prune that silently does not run is
  unbounded growth wearing a schedule), plus `workflow_dispatch` for a manual
  pass. It runs `ci/prune-deployments.sh --keep 30 --env staging,production
  --apply`. Dry run is the script's default; `--apply` is what makes the
  schedule real rather than TOG-913 theater.
- **Retention is per environment, not global.** Staging deploys on every merge
  and production deploys almost never; a global keep-30 would let staging
  churn evict the whole production history the KPI reads. The environment is
  the unit the API organises records by and the unit the KPI reads, so it is
  the unit the prune keeps.
- **Keep-30 means the last 30 deploys, not the last 30 days.** A burst week of
  merges narrows the window; a quiet month widens it. The schedule is weekly,
  not per-deploy, and history depth is measured in releases — that is the
  documented bound, not a defect.
- **The credential is `GITHUB_TOKEN` with `deployments: write`, minted per
  run.** That scope is the only one the job holds: list, retire, delete. No
  box-side token exists for this — putting a GitHub token in the box `.env`
  for a janitor job would be credential distribution, which is
  owner-reserved. That is also why there is no artisan command and no
  scheduler entry: the prune lives in Actions, where the credential is born
  and dies with the run.

The bound, stated plainly: with more than one deployment, GitHub deletes only
**inactive** records — anything else is a 422. A record goes inactive when a
newer `success` status lands on the same environment, so ordinarily every
prune candidate already is. When one is not, the script retires it first (one
`inactive` status naming the script and the card) and deletes it in the same
run, so the transient state is invisible. If a record cannot be retired or
deleted, the run fails naming its id — one stuck record never blocks the
rest, and a prune that quietly skipped is the defect this card exists to fix.

Pinned two ways: `ci/prune-deployments-selftest.sh` executes the pruner
against a stub `gh` and pins that the newest are kept, the oldest go first,
active records retire, stuck ids fail loudly, and API errors are never read
as empty environments — it runs in `static`, offline. Large API pages are
processed through stdin, ids are deduplicated before retention, and any JSON
planning failure stops the environment before deletion. The scheduled job
uses `ubuntu-latest`, matching the hosted-runner attestation above. And
`tests/Unit/DeploymentRecordsPruneTest.php` pins this section's lines, so a
future edit cannot silently drop the schedule, the per-environment rule, or
the bound while deploys keep looking green.

### Production deploys are dispatch-only, behind a required reviewer

Production ships from GitHub Actions, and only ever that way: `workflow_dispatch`
with `production` chosen, on `main`, behind the `production` environment's
required reviewer. Reaching the deploy step already means a human asked and a
reviewer approved. The checklist below is still the release sign-off — a maintainer
owns it — and the environment gate is its technical half.

This used to say "manual, in the hosting dashboard", and that was right at the
time. When this file was written, `two-web` was private on GitHub Free, and on
that plan environments cannot be configured at all — *"any configured protection
rules or environment secrets will be ignored"*. So the `environment: production`
gate was not unconfigured but **ignored**: the day somebody created the hook,
anyone with write access could have shipped from the Actions tab with no
approval. A gate that fails silently is worse than no gate, so the job was gone
rather than guarded (TWO-91, TOG-118).

That premise is superseded. The org is on paid GitHub Enterprise Cloud (owner
decision 2026-08-27, TOG-382 → TOG-564): the `production` environment carries
required reviewers plus a branch policy, and `main` is ruleset-protected —
verified live on TOG-6912 via host-token readback (TOG-7649, 2026-09-28).
The job is therefore restored (TOG-6912). It stays
gated until the Ship target card ([TOG-6902]) says TWO Web may go live — the
first production launch needs owner approval. Do not set
`COOLIFY_PRODUCTION_DEPLOY_HOOK` / `PRODUCTION_URL` until then.

Like staging, the production job fails when it has no target
(`ci/deploy-target-production.sh`), polls `/up` until the release answers, and
then runs `bin/smoke-staging.sh` against the apex. No target is a failure, never
a skip (TOG-913).

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

`--run` is the expensive one, and it got more expensive twice: the `lcp` case
split off `slowserver`, and the `audit` case split off the job list. **Twelve
pull requests** now, eleven deliberate breakages and one clean control, each
waiting on a full CI run. They run concurrently, so the cost is
one CI run's wall clock plus the pushes, not twelve of them. Measured end to end on
2026-08-25 against `72f3dea`: **under five minutes**, of which the clean control's
own six checks were 2m34s. "Budget most of an hour", which this used to say, was a
guess written before anyone had sat through one — and it was the reason to put the
run off. Do not put it off; sit with it. One of the twelve also pushes a 1.6 MB image on
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
| A dependency with a known-high advisory in the lockfile | `deps-audit` |
| A budget threshold relaxed, downgraded to a warning, deleted, shadowed by a second entry, or its aggregation swapped | `static` |
| Deleting the aggregate's `if: always()` | `static` — see below |
| A credential committed to a tracked file | `gitleaks` — see below |
| Nothing wrong at all | nothing — goes green |

The Dusk case is hidden with CSS rather than deleted on purpose: the HTML still
contains the text, so the feature test passes and only the real browser notices.
A breakage that trips `pest` too would prove nothing about the browser job.

Eight of the ten cases also assert that the aggregate `tests` check went red, not
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
not hypothetical: on GitHub Free with a private repo nothing was mechanically
enforced (see *Production deploys are dispatch-only* below for how the deploy
side of that changed on Enterprise Cloud), so this list is the plan for the day
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
fails. **Nobody signs off TWO-22 until this has passed once, for real.**

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

A maintainer signs this off. Nothing reaches production without it.

This checklist **is** the release sign-off. The `production` environment's required
reviewer is its technical half on our plan (see *Production deploys are
dispatch-only* above) — the reviewer approves only a signed-off SHA — and the
person triggering the deploy refusing to trigger it unsigned is the other half.
Note the commit SHA you signed off, and deploy that SHA.

- [ ] `main` is green — all of `static`, `pest`, `dusk`, `budgets`, `deps-audit`, and the `tests` aggregate
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
- [ ] Design spec matched and signed off (box 3)
- [ ] Migrations reviewed for a safe forward path, and a rollback that is understood
- [ ] No secret in the diff, no secret in the history
- [ ] Someone is available to watch it after it goes out
- [ ] **Then, and only then:** the production deploy is triggered from Actions
      (`workflow_dispatch` → `production`, on the signed-off SHA), and the
      environment's required reviewer approves it. Stays gated until [TOG-6902]
      says TWO Web may go live.

If a deadline would require shipping something that has not passed this, that goes
to a maintainer in writing. It is not one reviewer's trade-off to make alone.

See also: [testing-strategy.md](testing-strategy.md), [flake-policy.md](flake-policy.md).
