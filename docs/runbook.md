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
| Maintainers | deadline-vs-checklist trade-offs in writing, spend decisions (GitHub Team, extra infra) |

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
- **`/up`** is Laravel's built-in health route (`health: '/up'` in
  `bootstrap/app.php`). It answers 200 with no auth. If it does not answer,
  you have not deployed — see rollback.

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
  separate procedure owned by the maintainers, not this page.
- After any restore: note the dump file, the SHA served, and the data-loss
  window (writes between the dump and the stop) on the incident card. The
  window is why step 1 stops the writers first.

### Rehearsing this locally (backup + restore proof)

The quarterly staging rehearsal above is the real test, but the mechanics —
take the dump the same way, prove it restores — run on a laptop with nothing
but docker. `bin/pg-backup.sh` wraps both halves; `ci/pg-backup-selftest.sh`
pins the script's guards offline (22 cases, runs in `static`), so a guard that
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

### Backup retention + offsite copy

Dumps accumulate; a disk that fills at 03:00 is an outage with a timestamp.
The rule, enforced by `bin/pg-backup.sh rotate`:

- **Dailies: keep the newest 7.** The nightly cron dumps, then rotates. The
  8th-oldest daily is deleted.
- **Weeklies: keep the newest 4.** Once a week (Sunday, after the nightly
  dump), the cron runs `promote-weekly` — a byte copy of the newest daily
  under a `two-web-weekly-<UTC>.dump` name — then rotates. Weeklies never
  count against the daily quota.
- **Prove it before you trust it:** `./bin/pg-backup.sh rotate --dry-run`
  prints `keep:`/`delete:` lines and removes nothing. The acceptance proof
  for this rule is the `rotate-dry-run` case in `ci/pg-backup-selftest.sh`:
  8 dailies in, newest 7 kept, the 8th named for delete, nothing removed.

```bash
./bin/pg-backup.sh backup            # nightly: dump, then copy offsite
./bin/pg-backup.sh rotate            # nightly: prune to 7 dailies + 4 weeklies
./bin/pg-backup.sh promote-weekly    # Sundays: snapshot newest daily as weekly
./bin/pg-backup.sh rotate --dry-run  # anytime: show what rotate would do
```

`rotate` and `promote-weekly` touch only files — no docker, no database — so
they run on the production cron box and in CI. `backup` and `restore-proof`
keep the local-docker-only refusal.

**Offsite copy — where the second copy lives.** When `BACKUP_COPY_DEST` names
a directory, every finished `backup` is also copied there with `cp`, and a
missing destination fails the run loudly (backup kept, exit 1) rather than
passing silently. Production value, set in the cron environment on the box
(never in the repo): `BACKUP_COPY_DEST=/mnt/offsite/two-web` — the mounted
offsite volume DevOps owns. The mount itself (what backs `/mnt/offsite`,
its credentials, its own rotation) is DevOps-owned box config, not this
page. No paid service, no new credential: `cp` to a mount the box already
has. Local dev leaves `BACKUP_COPY_DEST` unset and keeps one copy.

---

## Staging QA sign-in seam (staging only)

**What it is.** `GET /auth/qa/{identity}` (route `qa.login`) signs in one
of two deterministic fixtures with no Discord round-trip, so staging QA can
exercise the real session and Filament authorisation paths. It returns `204`
with an empty body and a session cookie — no redirect, no token in the
response. The only valid identities are `qa-member` (plain member) and
`qa-moderator` (holds `SySOp` `508654771276873729`, so `/admin` allows them).
Anything else — including `qa-unknown` — is a `404`.

Two gates, both fail closed with an identical `404` that reveals nothing:

1. **Route gate** (`routes/web.php`): the route registers only when
   `APP_ENV=staging`. Production never registers it
   (`tests/Unit/ProductionRouteAllowlistTest.php` pins the absence), and the
   controller re-checks the environment so a cached or hand-registered route
   still `404`s outside staging.
2. **Token gate**
   (`app/Http/Controllers/Auth/StagingQaLoginController.php`): the request
   must carry the staging-only secret in the `X-TWO-QA-Auth` **header**.
   The controller strips the header before anything can throw or log, then
   hash-compares it against `TWO_WEB_STAGING_QA_AUTH_TOKEN`
   (`config/services.php` `staging_qa_auth.token`). Blank configured token,
   missing header, or wrong token all `404` — byte-identical to an unknown
   identity, so neither the secret nor the fixture list leaks.

Cloudflare Access in front of staging stays the outer gate; this token is the
inner one. Source of truth for rotation and hygiene is
[`docs/env.md` §10](env.md#10-staging-qa-seam).

**Who may use it.** Named QA engineers behind Cloudflare Access, plus the
staging test automation — nobody else. Owner: maintainers; day-to-day rotation is
executed by DevOps through Coolify secret controls; max credential age 90
days (rotate sooner on suspected leak, QA-team change, or an env-parity
`PROD-HAS-STAGING-ONLY` hit). Never demo credentials, never prod debugging,
never shared outside the staging QA group.

**Staging-vs-prod tell (no secret needed).** On the box, the route list is
the tell:

```bash
php artisan route:list --name=qa.login
```

- **Staging:** lists `GET|HEAD auth/qa/{identity}`.
- **Production:** lists nothing. Any `GET /auth/qa/<anything>` there is a
  `404`, with or without the header — that is the seam correctly absent, not
  an outage.

**Use it on staging, verbatim.** The token comes from Coolify secret
controls (staging), never from chat, logs, or the repo. Header only — never
in a URL, body, screenshot, or file.

```bash
STAGING=https://<staging-host>   # e.g. the STAGING_URL from docs/ci.md

# 1. Sign in as the member fixture. Expect HTTP 204, empty body.
curl -i -H "X-TWO-QA-Auth: <token>" "$STAGING/auth/qa/qa-member"
# HTTP/2 204
# (a Set-Cookie session header; no Location header, no body)

# 2. Keep the session in a cookie jar for the next checks.
curl -s -c /tmp/qa-jar.txt -o /dev/null -w "%{http_code}\n" \
  -H "X-TWO-QA-Auth: <token>" "$STAGING/auth/qa/qa-member"
# 204

# 3. Member authorisation: profile opens, admin refuses.
curl -s -b /tmp/qa-jar.txt -o /dev/null -w "profile:%{http_code}\n" "$STAGING/profile"
# profile:200
curl -s -b /tmp/qa-jar.txt -o /dev/null -w "admin:%{http_code}\n" "$STAGING/admin"
# admin:403

# 4. Moderator authorisation (fresh jar so the sessions do not mix).
curl -s -c /tmp/qa-mod-jar.txt -o /dev/null -w "%{http_code}\n" \
  -H "X-TWO-QA-Auth: <token>" "$STAGING/auth/qa/qa-moderator"
# 204
curl -s -b /tmp/qa-mod-jar.txt -o /dev/null -w "admin:%{http_code}\n" "$STAGING/admin"
# admin:200

rm -f /tmp/qa-jar.txt /tmp/qa-mod-jar.txt
```

Negative checks (same host, still no questions to ask anyone):

```bash
# Wrong token and unknown identity are the same 404 as a missing token.
curl -s -o /dev/null -w "%{http_code}\n" \
  -H "X-TWO-QA-Auth: wrong-token" "$STAGING/auth/qa/qa-member"
# 404
curl -s -o /dev/null -w "%{http_code}\n" \
  -H "X-TWO-QA-Auth: <token>" "$STAGING/auth/qa/qa-unknown"
# 404
```

Rules: throttled to 10/min per IP (`throttle:10,1`) — a `429` means slow
down, not a broken seam. If the correct token returns `404` on staging,
check `APP_ENV=staging` on the box and that the token in Coolify matches
what you sent; if `/auth/qa/*` returns `404` on prod, that is correct —
stop and use real Discord login there.

---

## Staging-to-prod env parity (`bin/env-parity.sh`)

**What it is.** A key-names-only diff of staging-vs-prod against the
required set (`.env.example` plus the documented-but-not-in-example keys in
`docs/env.md` §§ 1–13). It prints key names and categories — **never
values**: values are cut in the extraction pipeline before they touch a
variable, so a snapshot of live secrets prints the same output as one of
placeholders. Values are also never *compared* (`APP_KEY`, `DB_PASSWORD`,
`APP_URL` and friends are expected to differ — see `EXPECTED_DIFFER` at the
top of the script). Full category reference is
[`docs/env.md` §14](env.md#14-staging-to-prod-parity-binenv-paritysh).

**Run it, verbatim.** Snapshots are files you create outside the repo from
the Coolify dashboard (or env export) and never commit. Replace the two
paths with wherever you saved them.

```bash
# 1. Prove the tool itself first (offline, fake secrets, ~1s).
bin/env-parity.sh --selftest
# SELFTEST PASS

# 2. Run the real check. Filenames appear in the output; values never do.
bin/env-parity.sh /tmp/staging.env /tmp/prod.env
# env-parity staging=/tmp/staging.env prod=/tmp/prod.env example=.env.example
# checked <N> required keys against /tmp/staging.env and /tmp/prod.env
# PARITY OK — values never compared (expected to differ: ...)   # exit 0
#   -- or --
# MISSING prod: SOME_KEY
# PARITY DRIFT — resolve per docs/env.md §14 (no values shown above, by design)  # exit 1

# 3. Delete the snapshots when done (they held live secrets).
rm -f /tmp/staging.env /tmp/prod.env
```

Exit codes: `0` parity, `1` drift (the lines below), `2` called wrongly
(missing/unreadable file, bad flag — the usage line says so).

**Read the diff.** Each line names a key and a category; fix per
`docs/env.md` §14:

| Report line | Meaning | Fix (via Coolify env config / secret controls, never the repo) |
|---|---|---|
| `MISSING staging/prod: KEY` | Required key absent on that side | Add it. If it is genuinely unneeded there, the decision belongs in `.env.example` / `docs/env.md` — update the source of truth, do not silence the check. |
| `PROD-HAS-STAGING-ONLY TWO_WEB_STAGING_QA_AUTH_TOKEN` | The staging QA seam exists in prod | Remove it from prod, then **rotate** it per `docs/env.md` §10 — it existed where it must not. |
| `FORBIDDEN …: DUSK_TEST_SEAMS` / `DUSK_DISCORD_PROVIDER_URL` | A test seam left the test suite | Unset it immediately on that environment; `true` outside tests lets anyone sign in as anyone. |
| `UNKNOWN …: KEY` | In an env but in neither `.env.example` nor `docs/env.md` | Document it (with required/secret/staging-vs-prod columns) and add it to the example if it belongs there — or remove it from the env. |

---

## Error alerting (TOG-8730)

The uptime ping above answers "is the site down". This section answers "is
the site broken while still answering" — a 500 on one route, a job the
worker gives up on. No Sentry, no Flare, no Bugsnag: none installed, none
allowed. The channel is the log the box already tails, plus cron mail as
the pager.

**Two alert lines, both already in the log.** The application emits them:

1. `Unhandled exception.` — one critical line per distinct unhandled
   failure, logged by the `report` listener in `bootstrap/app.php`, with
   the exception class, route and message as structured context. It runs
   after the framework's own dont-report list, so 404s, 403s, validation
   and throttles never alert — only genuine 500s. A per-fingerprint rate
   limit (`App\Support\ErrorAlertRateLimit`: one alert per exception
   class + route per 5 minutes) mutes repeats, so a crashing deploy
   produces one line, not thousands. If the limiter store itself is down,
   the guard degrades to unmuted rather than silent — a second mail beats
   a swallowed outage.
2. `Queue job failed.` — one critical line per failed job, logged by the
   `Queue::failing` listener in `AppServiceProvider` (TOG-6948).

**Who watches:** cron on the box, owned by DevOps (same box and same
ownership as the uptime ping). The watcher is `bin/error-log-watch.sh` —
it scans the log delta since the last run for those two lines and exits
nonzero with the lines attached when one landed, so cron mail is the
pager. The QUIET path prints nothing — stock cron mails on *any* job
output regardless of exit code, so a chatty QUIET would page the on-call
every 5 minutes forever. **No mail is QUIET, and an `ALERT` mail is a
page** (`--verbose` restores the QUIET one-liner for hand runs).

**Cadence:** every 5 minutes, production and staging (staging first —
staging proves the path before production needs it):

```cron
MAILTO=devops@example.com
*/5 * * * * /var/www/two-web/bin/error-log-watch.sh
```

(Use the real on-call address for `MAILTO`, set in the cron environment on
the box — never in the repo. The log path defaults to the checkout's own
`storage/logs/laravel.log`; a Coolify deploy whose log lives elsewhere
passes `--log` explicitly.)

**The drill — prove a 500 pages (staging, after each deploy until this
settles):**

```bash
php artisan error-alert:probe --json   # alert fires once, repeat muted
bin/error-log-watch.sh --verbose       # ALERT mail content, by hand
```

The probe throws a marker exception through the same `report` listener a
real 500 travels and reports whether the alert fired and the repeat was
muted; the watcher half is a log tail by hand. Pinned by
`tests/Feature/Console/ErrorAlertProbeTest.php`.

**Honest limits, same as the ping:**

- A rotated log re-reads from the top (offset past EOF), so one duplicate
  mail follows each rotation. The price of a pager that never goes blind.
- An alert already mailed is not re-mailed: the offset advances even on
  ALERT, so the mail carries the lines once. The next *new* alert still
  pages. If paging ever needs re-mail-until-acknowledged, that is a new
  decision with a new card.
- Like the ping, this complains when broken — if cron itself dies, no mail
  arrives and nothing pages.

**Proving the watcher:** `bin/error-log-watch.sh --selftest` drives the
real script against fixture logs (quiet, fires, queue-half, rotation,
missing log, usage) entirely offline, the same pattern as
`bin/uptime-ping.sh --selftest`. Run it after any edit to the script.

---

## Finding CSP violation reports (TOG-9276)

**What it is.** `POST /csp-reports` is a log-only sink (TOG-8403): each
sampled report lands as one `csp.report.violation` warning row in the app
log, carrying the fixed key set (`blocked_uri`, `violated_directive`,
`document_uri`, `source_file`, `line_number`) — never the raw body. There
is no dashboard and no table to query; the log line is the store. This
section is the documented provider path: how a reviewer triggers a
violation on staging and finds it.

**Read it on staging, verbatim.** Same box access as any outage read
(quick-reference step 3 below):

```bash
grep 'csp.report.violation' /var/www/two-web/storage/logs/laravel.log | tail -30
# [2026-09-29 14:19:38] local.WARNING: csp.report.violation {"blocked_uri":"inline","violated_directive":"script-src","document_uri":"...","source_file":"...","line_number":1}
```

What the fields mean: `violated_directive` names the policy clause that
fired (`script-src` and friends); `blocked_uri` is what the browser refused
(`inline` for an inline script); `document_uri` is the page that produced
the report. A burst of `script-src` / `inline` rows on a page with no inline
script of ours is the signal to tune the policy, not a user to chase —
reports carry no user id, no session, and no IP beyond what the log line
itself holds.

**Trigger one on purpose.** Flip `CSP_REPORT_ONLY=true` on staging (Coolify
env config, [`docs/env.md` §15](env.md#15-csp-report-only-mode)), load any
page, then fire a violation from devtools — appending an inline script
suffices (`document.body.appendChild(Object.assign(document.createElement('script'),{textContent:'void 0'}))`).
Report-only mode logs it without blocking anything. Then run the grep above.
Flip the flag back when done: report-only left on is an unenforced policy,
and `docs/env.md` §15 says so.

**The bounds, stated plainly.** `CSP_REPORT_SAMPLE_RATE=0.0` means valid
reports are parsed but never logged (blind) — the grep finds nothing and
that is configuration, not absence of violations. Oversize bodies log under
`csp.report.dropped_oversize`, not here, so a flood shows up as drops, not
violations. Log retention is [TOG-8728](/TOG/issues/TOG-8728) (backlog, not
this card): whatever rotates `laravel.log` bounds how far back this grep
reaches.

Pinned by `tests/Unit/CspReportQueryDocTest.php`, which asserts this section
still names the grep string, the trigger, and the bounds.

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
