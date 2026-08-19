# TWO — the Together We Own website

The community website for TWO. It exists to do one thing: get more people to join
the Discord and stay active. Every feature here should plausibly move that number.

The Discord bot is a separate service in its own repository (`two-bot`). This app
never holds the Discord bot token.

---

## Get it running (about ten minutes, most of it downloads)

You need: **PHP 8.2+** with the `pdo_pgsql` and `intl` extensions, **Composer**,
**Node 20+**, and **Docker** (for the local database only).

```bash
git clone <this repo> two-web && cd two-web

docker compose up -d          # Postgres 17 on localhost:5432
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
npm install && npm run build
```

Then start everything with one command:

```bash
composer dev                  # web server, queue worker, log tail, Vite
```

Open <http://localhost:8000>. You should see a placeholder page saying the scaffold
is running. <http://localhost:8000/up> is the health check.

### Check it actually works

```bash
composer check                # formatting, static analysis, and the test suite
```

All three must be green before you open a pull request. If `composer check` is red
on a fresh clone, that is a bug in this README or in the scaffold — say so, do not
work around it.

---

## Day-to-day commands

| Command | What it does |
|---|---|
| `composer dev` | Server, queue worker, logs and Vite, all at once |
| `composer test` | Pest — unit and feature tests |
| `composer test:e2e` | Dusk — real Chrome, real journeys |
| `composer lint` | Pint, fixes formatting in place |
| `composer analyse` | PHPStan level 8 |
| `composer check` | `lint:check` + `analyse` + `test` — what CI runs |
| `php artisan migrate:fresh` | Wipe and rebuild your local database |

---

## How this is put together

**Laravel 12 · Blade + Livewire 3 + Tailwind 4 · Postgres · Pest · Dusk · Pint ·
PHPStan.** No SPA. No Redis, no message broker, no container platform in
production. Sessions, cache and the queue all run on Postgres.

Production is one Linux VM: nginx + PHP-FPM + systemd, on the same box as the bot
to start with. Docker is a local-development convenience and nothing more.

### Deliberate deletions

These are gone on purpose. Putting them back needs a reason.

- **`users.email` and `users.password`.** Members sign in with Discord and only
  with Discord. We never authenticate against an email or a password, so we do not
  store either. No `password_reset_tokens` table for the same reason.
- **Every database driver except Postgres.** Tests run on Postgres too, so the
  suite cannot go green on something that breaks in production.
- **Redis, Memcached, DynamoDB, SQS, Beanstalkd, S3.** We do not run them.
- **`laravel/sail`.** `docker-compose.yml` starts the one container we need.

### Directory notes

```
app/Models/          User, Profile, Event, Rsvp — thin, no business logic yet
app/Enums/           EventStatus, RsvpStatus
database/migrations/ The website's own tables, and only those
resources/views/components/layouts/app.blade.php
                     The bare HTML shell. Structure and accessibility only —
                     visual design belongs to the Designer's specs.
```

---

## The database

We share one Postgres server with the bot, but not one set of tables.

**We own** `users`, `profiles`, `events`, `rsvps`, plus Laravel's `sessions`,
`cache` and `jobs`. We migrate these.

**The bot owns** everything else. We read its data through **read-only views it
publishes and versions** (`config/database.php`, connection `bot`), using a role
that has `SELECT` on those views and nothing else. We never read the bot's internal
tables and we never write to its database. The contract lives in the `two-bot`
repository.

### Why `profiles` is separate from `users`

Every field on `users` is overwritten from Discord on each login. Everything on
`profiles` is written by the member. Splitting them means a re-sync can never wipe
someone's bio. There is a test for exactly that (`tests/Feature/SchemaTest.php`).

### The bot will be down sometimes

When it is, the site degrades — it does not white-screen. Live counters fall back
to the last cached value. Anything that needs a Discord action is queued, retried
with backoff, and tells the member plainly what state it is in. `rsvps` carries a
`synced_to_discord_at` column so a page can tell the truth about whether Discord
knows yet. QA tests this path deliberately, so build for it.

---

## Secrets

`.env.example` lists every credential the app expects. None of the real values live
in this repository, in an issue comment, or in a chat message — they come through
the secrets channel.

The two Discord credentials here are for the **OAuth application**, which is a
different application from the bot. **The Discord bot token is not one of them and
must never appear in this codebase.** One process holds that credential and it is
not this one.

If you need a credential you do not have, open a blocked issue naming the exact
credential and the CEO as the unblock owner. Do not improvise around it.

---

## Working here

- **Tests first.** Write the failing Pest test, then the code. The bar is not
  coverage, it is: does a test fail when you deliberately break the thing?
- **Dusk covers the journeys that matter.** A journey with no Dusk test is not done.
- **Smallest thing that works.** Any abstraction with one caller gets deleted in
  review. Adding a dependency needs a reason a reviewer can challenge, written down.
- **Don't put design opinions in the CSS.** Layout, visuals and copy are the
  Designer's call.
- **Nobody merges their own PR without QA sign-off.**

Phase 1 is: landing page, Discord login, member profile, events calendar with RSVP,
and a Filament moderator admin. Anything else needs CEO sign-off before a line is
written.
