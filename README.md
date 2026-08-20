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
npm install --include=dev && npm run build
```

Those five steps after `docker compose up -d` are exactly what `composer setup`
runs, if you would rather type one command. Either way the database has to be up
first, because it migrates.

### No PHP? No Docker? No root?

Some of our working environments ship Node and git and nothing else. Run:

```bash
bin/dev-bootstrap.sh
```

It downloads a self-contained PHP 8.3 and Composer into `.tooling/bin`
(gitignored), touches nothing system-wide, and exits early if you already have a
usable PHP. Add the printed line to your `PATH` and the rest of the README works
unchanged.

For the database, if `docker` is also missing, point `DB_HOST`, `DB_PORT`,
`DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` in `.env` at any Postgres 14+ you
can reach and are allowed to wipe. Nothing in the app cares where Postgres lives —
the test suite reads the same `.env` and only forces the database *name*, so create
`two_web_test` on that server as well (docker-compose does this for you):

```sql
CREATE DATABASE two_web_test;
```

That PHP build has no `intl`, which Filament wants — locally you will need
`--ignore-platform-req=ext-intl`. Real dev and production VMs have it, so this is
a laptop-shaped problem only.

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

nginx terminates TLS, so it **must** pass `proxy_set_header X-Forwarded-Proto
$scheme;` (or the fastcgi equivalent) — the application trusts it to know the page
is https. Without it the Discord login breaks outright, because the callback
address we send Discord would say `http` where the registered one says `https`.
PHP-FPM listens on a local socket and nothing but nginx can reach it, which is why
the app trusts any proxy; if that ever changes, name the proxy in
`bootstrap/app.php`.

**No hostname is written down anywhere in this code** — `APP_URL` drives all of it,
and `tests/Unit/NoHardcodedHostnamesTest.php` fails the build if that stops being
true. This site launches on a subdomain and takes over `togetherweown.com` later,
once the WordPress store there has actually been replaced; that is the only reason
the rule matters. See [`docs/dns.md`](docs/dns.md) before touching a DNS record or
writing an absolute URL.

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
resources/css/two.css
                     The design system. Vendored — do not edit. See below.
public/fonts/        Archivo, self-hosted. Vendored — do not edit.
resources/views/components/layouts/app.blade.php
                     The bare HTML shell. Structure and accessibility only —
                     visual design belongs to the Designer's specs.
```

### The design system is vendored, not authored here

Colours, type scale, spacing, radii and the `u-*` utilities live in the
**two-design** repo and are copied into this one. `resources/css/two.css` and
`public/fonts/*` are that copy. Editing them here puts us on a fork of our own
design system, which nobody will notice until two pages disagree.

To pull a newer version:

```bash
cp ../two-design/tokens/two.css        resources/css/two.css
cp ../two-design/assets/fonts/*.woff2  public/fonts/
cp ../two-design/assets/fonts/Archivo-OFL.txt public/fonts/

# Record what you copied. `vendored.design-system` is the manifest CI checks; the
# `upstream` line at the top of it takes the two-design commit you copied from.
sha256sum resources/css/two.css public/fonts/archivo-latin.woff2 \
          public/fonts/archivo-latin-ext.woff2 public/fonts/Archivo-OFL.txt

npm run build && php vendor/bin/pest --filter='DesignSystem|Vendored'
```

Updating the manifest is not paperwork. Nothing in this repo checks colour
contrast — two-design does, on 43 pairings against WCAG 2.2 AA — and that guarantee
reaches the site only while our copy is byte-identical. `tests/Unit/VendoredTokensTest.php`
is what keeps those two facts connected, so a hex edited here fails CI instead of
quietly shipping.

Three rules, all enforced by `tests/Unit/DesignSystemTest.php`:

- **No hex, rem or px literal in a Blade template**, and no arbitrary values
  (`text-[13px]`, `bg-[#333]`). If the value you need is not a token, the system
  is missing it — ask the Designer, do not invent it.
- **`font-family` appears in `two.css` and nowhere else.** A component that needs
  its own font declaration is a bug in the tokens; report it rather than patching
  around it locally.
- **The page is dark-only.** `<html class="dark">` plus `<meta name="color-scheme"
  content="dark">` are in the layout so the browser paints scrollbars and form
  controls dark before our stylesheet parses. There is no light mode in Phase 1.

The Archivo preload in `<head>` is load-bearing, not garnish: the headline uses
the font's width axis, and without the preload the hero reflows on first paint and
moves the join button under the reader's thumb.

Component states, breakpoints and responsive rules: `two-design/docs/COMPONENTS.md`.

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

The two Discord credentials here are the **OAuth client ID and secret**. They
belong to the same Discord application as the bot, on purpose: Discord only lets
the bot add a member to the server when the bot token comes from the application
that signed that member in. Split them into two applications and one-click join
quietly degrades to an invite link the member has to click themselves.

Same application, different credentials. **The Discord bot token is not one of
these and must never appear in this codebase.** One process holds that credential
and it is not this one. The OAuth secret can sign a member in; it cannot read,
use, or reset a bot token.

There is no `DISCORD_REDIRECT_URI`. The callback address is built from `APP_URL`
and the route, so an environment can only get it wrong by getting `APP_URL` wrong.
Discord matches that string character for character, and a mismatch stops the
member at Discord's error screen — `tests/Feature/Auth/DiscordRedirectUriTest.php`
pins what each environment sends against the list registered on the application.
**Moving the site means *adding* a redirect URI in the Discord portal before the
switch and removing the old one after. Swapping it is a login outage.**

If you need a credential you do not have, open a blocked issue naming the exact
credential and the CEO as the unblock owner. Do not improvise around it.

---

## Working here

Branch naming, commit style, and how a PR gets reviewed and merged are in
[CONTRIBUTING.md](CONTRIBUTING.md). `main` is protected — nothing lands on it
except by pull request.

- **Tests first.** Write the failing Pest test, then the code. The bar is not
  coverage, it is: does a test fail when you deliberately break the thing?
- **Dusk covers the journeys that matter.** A journey with no Dusk test is not done.
- **Smallest thing that works.** Any abstraction with one caller gets deleted in
  review. Adding a dependency needs a reason a reviewer can challenge, written down.
- **Don't put design opinions in the CSS.** Layout, visuals and copy are the
  Designer's call.
- **Nobody merges their own PR without QA sign-off.**

Three documents you are expected to have read before your first PR, owned by QA:

| | |
|---|---|
| [docs/testing-strategy.md](docs/testing-strategy.md) | what earns a unit test, a feature test, and a Dusk journey |
| [docs/flake-policy.md](docs/flake-policy.md) | a flaky test is a bug — it is fixed or deleted, never re-run |
| [docs/ci.md](docs/ci.md) | what CI runs, the budgets, the six-box merge gate, deploys |

Phase 1 is: landing page, Discord login, member profile, events calendar with RSVP,
and a Filament moderator admin. Anything else needs CEO sign-off before a line is
written.

<!-- ci-verify: a no-op change so a clean PR has something to build. -->
