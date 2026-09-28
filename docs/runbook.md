# Production runbook: deploy, rollback, restore

How the TWO Web site gets onto the single VM, how it comes back off, and how
the database is brought back when that is needed. If you are on call and this
page does not answer your question, fix the page after you fix the outage.

**Target:** one Linux VM — nginx + PHP-FPM + systemd, on the same box as the
bot to start with (see `README.md`). Postgres 17 on the same box. No
containers in production; `docker-compose.yml` is local dev only.

**Scope:** this page describes the procedure. It activates nothing — no DNS
change, no deploy, no secret rotation happens by reading it.

Related pages: [`docs/ci.md`](ci.md) (CI gate, release checklist, staging
`.env`), [`docs/dns.md`](dns.md) (domains, the apex swap).

---

## Who runs what

| Role | Owns |
|---|---|
| DevOps | the VM, nginx/PHP-FPM/systemd config, Postgres, backups, Coolify targets, this page |
| QA & Release | the release checklist sign-off (`docs/ci.md`) — nothing reaches production without it |
| Deployer | the person holding the hosting-dashboard login — triggers the production deploy, and *is* the approval gate (there is no GitHub environment gate on our plan; see `docs/ci.md`) |
| CEO | deadline-vs-checklist trade-offs in writing, spend decisions (GitHub Team, extra infra) |

One rule: the person who wrote the release does not sign it off, and the
person who signs it off watches it after it goes out (`docs/ci.md` checklist).

---

## Deploy

### Staging: automatic

CI green on `main` → Coolify staging target deploys itself via the
`COOLIFY_STAGING_DEPLOY_HOOK` webhook (`ci/deploy-target.sh`, `docs/ci.md`).
A 200 from Coolify means the deploy was *queued*, not live — the job polls
`$STAGING_URL/up` for ten minutes and fails if the new release never answers.

If staging goes red on every push to `main`, that is expected until Coolify is
provisioned (TOG-780). Do not "fix" it with a skip-and-pass guard — see
`docs/ci.md`.

Staging prerequisites (one time, by DevOps):

1. Staging box provisioned behind basic auth / grey-cloud (see `docs/dns.md`).
2. Repo secret `COOLIFY_STAGING_DEPLOY_HOOK` and variable `STAGING_URL` set.
3. Staging `.env` on the box — notably `DISCORD_MODERATOR_ROLE_IDS=508654771276873729`
   (public snowflake, not a secret) and a **staging-only** `TWO_WEB_STAGING_QA_AUTH_TOKEN`
   via secret controls. Full table in `docs/ci.md`.

### Production: manual, from the hosting dashboard

Production is **never** deployed from CI. The deployer triggers it by hand in
the hosting dashboard, on the exact SHA QA signed off, after the full release
checklist in `docs/ci.md` — including `php artisan discord:check-moderators
--require-configured` **on the box being deployed**.

What the dashboard runs on the box, in order (narrate this to the reviewer —
if a step is missing from your panel's script, stop and fix the script):

```bash
# 0. Record where you are. You need this for rollback.
git rev-parse HEAD > /var/www/two-web/PREV_SHA   # previous release SHA
pg_dump -Fc -U two_web -h 127.0.0.1 two_web \
  > /var/backups/two-web/pre-deploy-$(date -u +%Y%m%dT%H%M%SZ).dump

# 1. Fetch the signed-off SHA and install dependencies (no dev).
git fetch origin && git checkout <SIGNED_SHA>
composer install --no-dev --optimize-autoloader

# 2. Build front-end assets.
npm ci && npm run build

# 3. Migrate, then cache. Cache AFTER the .env check below.
php artisan migrate --force
php artisan config:cache
php artisan discord:check-moderators --require-configured || exit 1
php artisan route:cache
php artisan view:cache

# 4. Restart services so the new code serves.
sudo systemctl reload php8.4-fpm    # match the installed PHP version
sudo systemctl restart two-web-queue
sudo systemctl reload nginx         # only if nginx config changed

# 5. Verify. Ten minutes of silence is a failure.
curl -fsS https://togetherweown.com/up
```

Notes on the steps:

- **Backup first, always** (step 0). A deploy without a fresh backup is a
  deploy without a rollback. The dump takes seconds on this database; there is
  no reason to skip it.
- **`migrate --force`**, never interactive in production. Review the forward
  path and the backward path in the PR *before* sign-off — the checklist
  requires it. A migration with no backward path (dropped column, rewritten
  data) must say so in the PR, with the restore-from-backup plan attached.
  That is the rollback for that release.
- **`config:cache` before the moderators check, deliberately**: that is the
  state the app will serve from, and caching a `.env` missing the variable is
  itself a failure mode (`docs/ci.md`). `env()` returns null under a cached
  config — the check reads `config()`, which is why it is trustworthy here.
- **The queue worker is systemd** (`two-web-queue` unit, `queue:work` on the
  `database` connection — no broker). Restart it on every deploy so queued
  jobs run the new code; stale workers serving old code after a deploy is the
  classic "but I deployed the fix" outage.
- **nginx**: `reload`, not `restart`, and only when its config changed. A
  reload keeps serving; a restart drops connections. Validate first:
  `sudo nginx -t`.
- **`/up`** is the explicit health probe (TOG-8711: routes/health.php,
  `App\Http\Controllers\HealthCheckController`). It answers 200 with no auth
  and carries the database signal as JSON — `db` plus `pending_migrations` —
  so a release whose migrate failed answers 503 instead of reading as
  healthy. If it does not answer, you have not deployed — see rollback.

---

## Rollback

Rollback is **one release back in the dashboard plus a decision about the
database**. Most failed deploys need only the first half.

### 1. Code rollback (minutes)

```bash
git checkout <PREV_SHA>              # the SHA saved in step 0
composer install --no-dev --optimize-autoloader
npm ci && npm run build             # only if assets changed between releases
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl reload php8.4-fpm
sudo systemctl restart two-web-queue
curl -fsS https://togetherweown.com/up
```

In Coolify this is the "redeploy previous release" button — same effect, same
verification. Either way, **poll `/up` until it answers**; a rollback that was
queued but never verified is the staging false-green all over again.

### 2. Database decision (decide, do not improvise)

Ask one question: **did the bad release migrate?**

- **No migrations ran** (deploy failed before step 3, or the release had
  none): code rollback is the whole rollback. Done.
- **Migrations ran and are reversible** (`php artisan migrate:rollback --force`
  covers them): roll the code back, then roll the migrations back, then
  re-cache and restart as above. Verify `/up`.
- **Migrations ran and are NOT reversible** (columns dropped, data rewritten):
  do **not** run `migrate:rollback` — it will fail partway and leave the
  schema between releases. Restore from the pre-deploy dump instead (next
  section). This case must have been named in the PR with its restore plan;
  if it was not, that is a process failure to record on the release card.

When in doubt, restore from the dump. A known-good backup is always safer
than a half-remembered rollback path at midnight.

---

## DB restore

Backups live in `/var/backups/two-web/` as timestamped custom-format dumps
(`pre-deploy-<UTC>.dump` before every deploy; plus the nightly cron —
DevOps owns the cron, and a backup with no restore test is a rumour, so the
restore below is rehearsed on staging quarterly).

```bash
# 1. Stop the writers. A restore against a live app loses whatever lands mid-restore.
sudo systemctl stop two-web-queue
sudo systemctl stop php8.4-fpm     # serves 502 briefly; say so in #ops first

# 2. Restore into a scratch database first, so a corrupt dump fails safely.
createdb -U two_web -h 127.0.0.1 two_web_verify
pg_restore -U two_web -h 127.0.0.1 -d two_web_verify /var/backups/two-web/<dump>
# sanity: table count, latest migration, a row you expect
psql -U two_web -h 127.0.0.1 -d two_web_verify -c \
  "select count(*) from migrations; select * from migrations order by batch desc limit 3;"
dropdb -U two_web -h 127.0.0.1 two_web_verify

# 3. The real restore. This REPLACES two_web — there is no undo past this line.
pg_restore -c -U two_web -h 127.0.0.1 -d two_web /var/backups/two-web/<dump>

# 4. Bring the app back on the code that matches this schema.
git checkout <SHA-matching-the-dump>   # usually PREV_SHA
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo systemctl start php8.4-fpm
sudo systemctl start two-web-queue
curl -fsS https://togetherweown.com/up
```

Rules:

- **Restore to the code that matches the dump.** A new-schema app on an
  old-schema database (or the reverse) is a second outage stacked on the
  first. The pre-deploy dump pairs with `PREV_SHA` — keep them together.
- **Never restore production onto staging's database or the reverse without
  saying so out loud.** Member data crosses environments that way. If a
  staging rehearsal needs production shape, use a scrubbed copy and record it.
- **Discord secrets are not in the dump's threat model** — OAuth client
  secret, bot shared secret and the QA auth token live in the box `.env` /
  secret controls, never in Postgres. A restore does not rotate them and does
  not leak them. If the incident *is* a credential leak, rotation is a
  separate procedure owned by the CISO, not this page.
- After any restore: note the dump file, the SHA served, and the data-loss
  window (writes between the dump and the stop) on the incident card. The
  window is why step 1 stops the writers first.

### Rehearsing this locally (backup + restore proof)

The quarterly staging rehearsal above is the real test, but the mechanics —
take the dump the same way, prove it restores — run on a laptop with nothing
but docker. `bin/pg-backup.sh` wraps both halves; `ci/pg-backup-selftest.sh`
pins the script's guards offline (15 cases, runs in `static`), so a guard that
quietly stopped guarding goes red on the next PR rather than at the rehearsal.

```bash
docker compose up -d          # the Postgres this proves against

./bin/pg-backup.sh backup
# -> backups/two-web-20260927T034500Z.dump

./bin/pg-backup.sh restore-proof
# -> PROOF OK — every table's row count matches (the script prints the count)
```

What `restore-proof` does: copies the dump into the compose container (`docker
cp`, so `docker-compose.yml` needs no proof-only mount), restores it into a
scratch database `two_web_restore_proof`, compares every table's exact row
count against the live database, prints the verdict, and drops the scratch
database — on success *and* on failure. A `PROOF FAILED` names the table and
prints the diff; a corrupt dump fails at `pg_restore` before any comparison.

Rules for the local proof, all enforced by the script:

- **Local docker only.** A `.env` pointing `DB_HOST` anywhere but this machine
  is refused outright. Production restores are the procedure above, never this
  script.
- **The dump is the same shape as production's** (`pg_dump -Fc`), so the proof
  exercises the format the runbook restores from — not a SQL text dump that
  would pass here and behave differently there.
- **Row counts, not estimates.** `pg_stats` resets on restore, so estimates
  would compare unequal on identical data; the proof uses `count(*)` per table.
- **No Postgres client needed on the laptop.** Everything runs inside the
  compose container; the password is forwarded as `-e PGPASSWORD` (name only)
  and never appears in a command line.

---

## Quick-reference: "it is down, what do I do"

1. `curl -sSI https://togetherweown.com/up` — is the app answering at all?
2. `sudo systemctl status nginx php8.4-fpm two-web-queue postgresql` — what is not running?
3. `journalctl -u php8.4-fpm --since '15 min ago' | tail -30` and
   `tail -30 /var/www/two-web/storage/logs/laravel.log` — what broke?
4. If the last change was a deploy: **roll back first** (above), diagnose
   second. A known-good release serving while you read logs beats a mystery
   you solve while the site is down.
5. If the database is the problem: stop writers, restore from the newest good
   dump (above).
6. Write down what happened on the incident card, then fix this page if it
   let you down.
