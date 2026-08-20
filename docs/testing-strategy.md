# Test strategy

Owned by QA. Changes to this document go through a PR like anything else.

The goal is not coverage. The goal is that **a broken join funnel cannot reach
production**, and that the suite stays fast enough that people actually run it.
Those two pull against each other, which is what the rest of this document is for.

---

## The shape we are aiming at

```
        Dusk  (E2E, real Chrome)          6 journeys, ~5 min      slowest, most valuable per test
      Feature (HTTP + real Postgres)      the bulk of the suite   where most bugs get caught
        Unit  (pure functions)            few, and only where earned
```

Most Laravel bugs are not logic bugs in a class. They are a wrong route, a missing
authorisation check, a bad query, a null from Discord nobody expected. Those are
**feature** tests. That is why the middle of our pyramid is the widest part and the
unit layer is deliberately thin — a pile of unit tests over a Laravel app buys
mocks, not confidence.

The failure mode we are guarding against is the inversion: E2E tests creeping down
to cover things a feature test could have caught, until the suite takes twenty
minutes, flakes twice a week, and everyone learns to re-run it.

---

## What earns a unit test

A unit test is for code that is **pure and non-obvious**: input in, value out, no
database, no HTTP, no facades.

Write one when:

- There is real logic worth stating in isolation — invite-code parsing, retention
  bucketing, a date window calculation, HMAC signature construction.
- The edge cases are the point. Empty input, boundary dates, a negative count, an
  emoji in a Discord display name.
- Reproducing the case through HTTP would take more setup than the logic deserves.

Do **not** write one for:

- A model that only declares `$fillable` and relationships. Testing Eloquent is
  testing Laravel.
- A method whose body is one facade call. You would be asserting that a mock was
  called, which is a test of your own wiring.
- Anything you had to build three mocks to reach. That is a signal the logic wants
  extracting into something pure — extract it, then unit test the extraction.

## What earns a feature test

**Default here.** If you cannot decide, write a feature test.

Every one of these earns one:

- **Every route.** Its happy path, and its unhappy path.
- **Every authorisation rule.** Not just "the owner can edit" — also **"a signed-in
  stranger gets a 403"**. A policy with only the positive test is an open door with
  a test suite that says it is locked.
- **Every validation rule**, including what the member sees when they break it.
- **Every place we touch the bot.** Both directions: the endpoint returns what we
  expect, *and* the endpoint is unreachable, slow, or returns nonsense. The bot
  being down is a normal Tuesday, not an exceptional case.
- **Every empty and error state** the Designer specified. An empty events list is a
  real state with a real design, not a blank div.
- **Every regression.** A bug fix without a test that fails before the fix is not a
  fix, it is a coincidence.

They run against **real Postgres**, never sqlite. We use `jsonb` and Postgres date
handling; a sqlite suite goes green on things that break in production. `phpunit.xml`
pins this and explains it.

### They do not run against built assets

`Tests\TestCase` calls `withoutVite()`, so `@vite` renders nothing in the PHP suite
and no test needs `npm run build` or `public/build/manifest.json`.

This is deliberate, and it is about determinism rather than speed. `public/build` is
gitignored, so it is a build artifact that exists on any machine where someone has
run a build once and on no clean checkout ever. Left alone, the suite passes on
every laptop and fails on every fresh runner — which is exactly what happened the
first time this pipeline ran on GitHub: six feature tests, every one of them a 500
from inside `@vite`, none of them about the thing under test.

The alternative was to build assets in the `pest` job. It works, but it buys nothing
and costs something. It buys nothing because no feature test asserts on `@vite`
output, and a real build is already rendered by `dusk` and `budgets`. It costs an
npm install on the PHP tier, which means the fast suite now goes red when the npm
registry has a bad afternoon — a flake vector on the one job that should have none.

So the coverage that would have been lost is bought back explicitly, not assumed:

| Failure | Caught by |
| --- | --- |
| The build is broken or missing | `dusk` and `budgets` — both build for real and render the layout in Chrome |
| `@vite` names an entrypoint that is not built | `tests/Unit/ViteEntrypointsTest.php` — statically, no build |
| `dusk` or `budgets` quietly stops building | `ci/verify-pipeline.sh --lint` check 8, which fails the `static` job |

That last row is the one that matters. The trade above is only sound while something
still exercises a real manifest, so the lint fails if either job drops its build step
— otherwise the day someone does, nothing goes red and the pipeline silently stops
covering a whole class of breakage.

Dusk is unaffected: `DuskTestCase` extends `Laravel\Dusk\TestCase`, not this one, so
the browser suite still gets the real, built assets.

## What earns a Dusk journey

A Dusk journey is expensive: real Chrome, real server, real database, tens of
seconds each, and the most likely thing in the repo to flake. It has to buy
something a feature test cannot.

It earns one when **the browser is genuinely part of the behaviour**: JavaScript,
Livewire round-trips, a redirect chain through a third party, focus and keyboard
handling, or a full multi-page path where the value is that the *whole thing* joins up.

The suite is a fixed list, and it is the funnel plus the money paths (TWO-34):

1. Land on the homepage → click join → the tracked invite link fires
2. Discord OAuth against a stubbed provider → land on the profile
3. View and edit the profile
4. RSVP to an event → the RSVP round-trips to Discord through the bot
5. Log out
6. **The degraded path** — the bot is unreachable: the site still renders, counters
   fall back to cache, queued actions retry, the member sees a clear message.
   Never a white screen.

Number 6 is not optional and it is not a nice-to-have. The bot and the site share a
database and a network; the bot *will* be down at some point. What a member sees
when that happens is a product decision we have already made, so it gets a test.

**Adding a seventh journey requires QA agreement.** Not because the list is sacred,
but because "we'll just add a Dusk test" is exactly how the pyramid inverts. Come
with the argument for why a feature test cannot do it.

---

## Rules that apply everywhere

**Tests are written first.** Box 1 of the merge gate. The test fails, then the code
makes it pass. A PR whose tests were clearly written after the fact — every test
passing on the first commit, no red anywhere in the history — gets asked about it.

**One reason to fail per test.** A test named `it works` that asserts eleven things
tells you nothing when it goes red.

**No conditionals in tests.** `if` in a test means it is two tests wearing a coat.

**Never assert on wall-clock time or ordering you did not set.** `Carbon::setTestNow`
exists. Unordered database results come back in whatever order Postgres feels like.

**Name the test after the behaviour, not the method.** `a signed-in stranger cannot
edit someone else's profile`, not `test_update_policy`. The name is what a future
reader sees when it breaks at 2am.

**Factories over fixtures.** A factory that builds the minimum, plus explicit
overrides for what the test is actually about. A shared fixture that forty tests
depend on becomes untouchable within a month.

**Nothing in the suite talks to the live Discord server.** Ever. Stubbed provider
locally and in CI; the staging bot against the staging Discord server for
integration (TWO-25). Destructive tests against the live server are a boundary, not
a preference.

---

## Where each thing runs

| | Local | CI (every PR) |
|---|---|---|
| Pint, PHPStan | `composer check` | `static` job |
| Pest unit + feature | `composer test` | `tests` job |
| Dusk | `composer test:e2e` | `dusk` job |
| Lighthouse + WCAG 2.2 AA | not usually | `budgets` job |

If `composer check` is red on a fresh clone, that is a bug in the scaffold or the
README. Say so — do not work around it.

See also: [flake-policy.md](flake-policy.md), [ci.md](ci.md).
