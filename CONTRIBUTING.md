# Contributing to two-web

## Local setup, start to finish

The [README](README.md) is the canonical setup guide — it is the one a new person
follows, so it is the one that gets fixed when it is wrong. The short version:

You need **PHP 8.2+** with the `pdo_pgsql` and `intl` extensions, **Composer**,
**Node 20+**, and **Docker** for the local database.

```bash
git clone git@github.com:TogetherWeOwn/two-web.git
cd two-web

docker compose up -d    # Postgres 17 on localhost:5432 — start this first
composer setup          # install, .env, app key, migrate, npm install, build
composer dev            # server + queue worker + logs + Vite
```

Then open <http://localhost:8000>. <http://localhost:8000/up> is the health check.

`composer setup` needs the database to already be up, because it runs migrations.
There is no SQLite fallback anywhere in this app: the test suite runs on Postgres
too, deliberately, so the suite cannot go green on a database we do not ship on.

If it is not working within ten minutes, that is a bug in the README — say so and
we will fix it, rather than you keeping the workaround to yourself.

## The commands

| Command | What it does |
|---|---|
| `composer check` | `lint:check` + `analyse` + `test`. This is what CI runs. |
| `composer test` | Pest. Must pass before you open a PR. |
| `composer test:e2e` | Dusk — real Chrome, real journeys. |
| `composer lint` | Pint, fixes formatting in place. Run before committing. |
| `composer analyse` | PHPStan level 8. |
| `composer dev` | Server, queue worker, logs and Vite, all at once. |
| `php artisan migrate:fresh --seed` | Reset your local database. |

## Secrets

Never commit one. `.env` is gitignored; `.env.example` holds the *names* with
empty values and is committed.

The Discord **OAuth** client id and secret belong to the website. The Discord
**bot token** does not — the website never holds it, and talks to the bot over
its signed internal endpoint instead. That boundary is deliberate: a leak of
the site's credentials must not become a leak of the bot's.

In CI and production, secrets come from GitHub Actions secrets and the server
environment. If you find one in the repo, **rotate it** — deleting the line
does not help, it is in the history from the moment it was pushed.

## Branches

`main` is protected. No direct pushes, for anyone. Everything arrives by PR.

Name branches `type/short-description`:

```
feat/discord-oauth-login
fix/rsvp-double-submit
docs/local-setup-php-version
```

Types: `feat`, `fix`, `docs`, `test`, `chore`, `refactor`.

## Commits

Imperative subject, under 72 characters, no trailing period:

```
Add Discord OAuth login and role to permission mapping
Fix RSVP count when a member cancels twice
```

Reference the issue in the body when there is one (`TWO-27`).

## Pull requests

1. Branch off `main`.
2. Open the PR. CI must be green — tests, static analysis, formatting, and the
   secret scan.
3. A code owner approves — see [.github/CODEOWNERS](.github/CODEOWNERS).
   **You cannot approve your own PR.** That applies to everyone including
   whoever wrote this.
4. Merge. The branch deletes itself.

A red PR does not merge. If CI is wrong, fix CI in its own PR rather than
routing around it.

## Things that will get a PR sent back

- A secret in the diff.
- `vendor/`, `node_modules/`, or a real `.env` in the diff. Check `git status`
  before your first commit.
- Member personal data stored where the feature does not need it.
- Anything that DMs or mass-messages members. That needs maintainer approval on
  an issue before it is written.
- A page with no empty state and no error state. Both count as part of the
  feature.
- An abstraction with one caller.
