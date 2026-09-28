# Environment variables (`docs/env.md`)

Source of truth for the file layout is `.env.example`. This document is the
reference for what each key means, whether it is required, what it should be
per environment, and what breaks when it is wrong.

Conventions used below:

- **Required** = the app cannot boot or a headline feature is dead without it.
- **Optional** = a working default exists in `config/`; set only to override.
- **Secret** = never in an issue comment, chat message, screenshot, log, or
  repository file. Secrets arrive through the secrets channel (or Paperclip /
  Coolify secret controls on staging/prod) and live only in the real `.env`.

Setup for a fresh checkout: copy `.env.example` to `.env`, run
`php artisan key:generate`, fill in the **Required, no default** rows, and
leave everything marked "leave blank/unset" alone.

## 1. App core

| Key | Required? | Local | Staging / Production | What breaks if wrong |
| --- | --- | --- | --- | --- |
| `APP_NAME` | Optional (default `Laravel` in `config/app.php`; `Together We Own` in example) | `Together We Own` | Same | Cosmetic: mail sender name fallback, cache prefix, page titles. |
| `APP_ENV` | **Required** | `local` | `staging`, `production` | Drives environment-gated behaviour (error detail, test seams). `production` enables production-only protections. Running prod with `local` leaks debug pages. |
| `APP_KEY` | **Required** (empty in example — generate it) | `php artisan key:generate` output | Unique per environment, via secret controls | Empty key: encrypted cookies/sessions fail and the app throws on boot. Reusing one key across envs means one leak decrypts all envs. Rotating without `APP_PREVIOUS_KEYS` invalidates every session and encrypted value. |
| `APP_PREVIOUS_KEYS` | Optional, not in `.env.example` | Unset | Previous key(s), comma-separated, during rotation only | Only needed for zero-downtime key rotation. Stale entries are harmless; removing the old key too early logs everyone out. |
| `APP_DEBUG` | **Required** (must be `false` outside local) | `true` | **`false`** | `true` in staging/prod exposes stack traces, env snippets, and file paths on error pages. |
| `APP_TIMEZONE` | Optional (default `UTC`) | `UTC` | `UTC` | Change only deliberately: scheduled jobs, log timestamps, and pruned-record cutoffs all shift. |
| `APP_URL` | **Required** | `http://localhost:8000` | Public canonical URL of that environment (https) | There is deliberately **no** `DISCORD_REDIRECT_URI` — the Discord callback is built as `APP_URL` + `/auth/discord/callback` and must match a redirect URI registered on the single Discord application. A wrong `APP_URL` means Discord rejects login at Discord's own error screen. Also used for absolute URLs in mail/notifications and `MAIL_EHLO_DOMAIN` fallback. |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` / `APP_FAKER_LOCALE` | Optional (defaults `en`/`en`/`en_US`) | `en`/`en`/`en_GB` | Same as local unless translating | Wrong locale: untranslated strings fall back silently; faker locale only affects seeded/dev data. |
| `APP_MAINTENANCE_DRIVER` / `APP_MAINTENANCE_STORE` | Optional, not in `.env.example` (defaults `file`/`database`) | Unset | Unset | Only matters for `php artisan down`. Wrong store means the maintenance-mode flag is invisible to some web nodes. |

## 2. Logging

| Key | Required? | Local | Staging / Production | What breaks if wrong |
| --- | --- | --- | --- | --- |
| `LOG_CHANNEL` | Optional (default `stack`) | `stack` | `stack` (or whatever the deploy target reads) | Wrong channel name: Laravel throws on first log write. |
| `LOG_STACK` | Optional (default `single`) | `single` | `single` (or `daily` if log rotation is wanted) | Comma-separated channel list. A typo'd channel inside the stack fails the same way. |
| `LOG_LEVEL` | Optional (default `debug`) | `debug` | `warning` or `error` | `debug` in prod is noisy and can log sensitive payloads; too-high a level hides the context needed for a post-mortem. |
| `LOG_DAILY_DAYS`, `LOG_DEPRECATIONS_CHANNEL`, `LOG_DEPRECATIONS_TRACE`, `LOG_SLACK_WEBHOOK_URL`, `LOG_SLACK_USERNAME`, `LOG_SLACK_EMOJI`, `LOG_PAPERTRAIL_HANDLER`, `PAPERTRAIL_URL`, `PAPERTRAIL_PORT`, `LOG_STDERR_FORMATTER`, `LOG_SYSLOG_FACILITY` | Optional, not in `.env.example` | Unset | Set only the sink in use (e.g. `LOG_SLACK_WEBHOOK_URL` **secret** if logging to Slack) | Each only matters when its channel is in the stack. A Slack webhook URL is a secret: anyone with it can post to the channel. |

## 3. Database (app)

Postgres only. `docker compose up -d` starts one matching the example values
exactly. There is intentionally no Redis/broker — adding one is a CEO
conversation, not a quiet `.env` change (see `.env.example` header comment).

| Key | Required? | Local | Staging / Production | What breaks if wrong |
| --- | --- | --- | --- | --- |
| `DB_CONNECTION` | Optional (default `pgsql`) | `pgsql` | `pgsql` | Anything else is untested and unsupported. |
| `DB_HOST` / `DB_PORT` | Required | `127.0.0.1` / `5432` | Private-network host/port of the managed Postgres | Wrong host/port: everything database-backed (sessions, queue, cache, app data) fails at once. |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` (**secret**) | Required | `two_web` / `two_web` / `two_web` | Per-environment values via secret controls | Wrong credentials: same total outage as wrong host. The local password is a dev-only convenience; prod password comes from secret controls, never the repo. |
| `DB_SSLMODE` | Optional, not in `.env.example` (default `prefer`) | Unset | `require` (or `verify-full` if the provider supports it) | `prefer` against a DB that mandates TLS still negotiates TLS; setting `disable` where TLS is required refuses to connect. |
| `DB_CACHE_*` (`DB_CACHE_CONNECTION`, `DB_CACHE_TABLE`, `DB_CACHE_LOCK_CONNECTION`, `DB_CACHE_LOCK_TABLE`), `DB_QUEUE_*` (`DB_QUEUE_CONNECTION`, `DB_QUEUE_TABLE`, `DB_QUEUE`, `DB_QUEUE_RETRY_AFTER`), `QUEUE_FAILED_DRIVER` | Optional, not in `.env.example` | Unset (defaults: `cache`/`cache_locks` tables, `jobs` table, `default` queue, retry-after 90s) | Unset unless sharing a connection or renaming tables | Wrong table name: cache/queue reads fail with missing-relation errors after a fresh migrate. `DB_QUEUE_RETRY_AFTER` lower than the longest job runtime causes duplicate job execution. |

## 4. Sessions, queue, cache, mail, broadcast, filesystem

Everything runs on the database. Keep it that way.

| Key | Required? | Local | Staging / Production | What breaks if wrong |
| --- | --- | --- | --- | --- |
| `SESSION_DRIVER` | Optional (default database-backed anyway) | `database` | `database` | Any other driver is untested here. |
| `SESSION_LIFETIME` | Optional (default 120 min) | `120` | `120` | Minutes of inactivity before logout. Too low logs people out mid-write; too high extends a stolen-session window. |
| `SESSION_ENCRYPT` | Optional (default `false`) | `false` | `false` | `true` without a valid `APP_KEY` breaks all sessions. Only enable deliberately. |
| `SESSION_PATH` | Optional (default `/`) | `/` | `/` | Scoping it to a subpath logs users out everywhere else. |
| `SESSION_DOMAIN` | Leave `null` unless you know why | `null` | `null` | A shared parent domain (`.togetherweown.com`) would hand the session cookie to the WordPress store on the apex. See `docs/dns.md`. Setting this wrong logs everyone out or leaks the cookie cross-site. |
| `SESSION_SECURE_COOKIE` | Pinned `true` in `.env.example` (explicit, never framework-default — CISO bar rule 6) | `true` | `true` | Staging/prod terminate TLS at the edge and trust the proxy (`bootstrap/app.php`), so the cookie is always Secure in practice. Safe on `http://localhost` too — loopback is a secure context, so local logins keep working. Setting `false` would send the session cookie over plaintext on any non-loopback http. |
| `SESSION_SAME_SITE` | Pinned `lax` in `.env.example` (not Strict, deliberately) | `lax` | `lax` | The Discord OAuth callback is a top-level GET navigation and the state lookup needs the session cookie present — `Strict` would drop it and break login. See `docs/dns.md`. `none` would additionally require `Secure=true`. |
| `SESSION_HTTP_ONLY` / `SESSION_EXPIRE_ON_CLOSE` / `SESSION_PARTITIONED_COOKIE` / `SESSION_CONNECTION` / `SESSION_STORE` / `SESSION_TABLE` | Optional, not in `.env.example` (defaults `true`/`false`/`false`/default connection/default store/`sessions`) | Unset | Unset | Wrong `SESSION_TABLE` breaks login with a missing-relation error. The rest only matter when deviating from database sessions. |
| `QUEUE_CONNECTION` | Optional | `database` | `database` | Wrong value silently stops background jobs (Discord role grants, announcements, mail) — the site looks fine while nothing happens. |
| `CACHE_STORE` / `CACHE_PREFIX` | Optional | `database` / unset (derived from `APP_NAME`) | Same | Wrong store: rate limits and cached Discord data misbehave. Two apps sharing one DB-backed cache without distinct prefixes evict each other. |
| `FILESYSTEM_DISK` | Optional | `local` | `local` | Only change when object storage is actually wired; uploads vanish or 500 otherwise. |
| `BROADCAST_CONNECTION` | Optional | `log` | `log` | Only backs realtime fan-out; `log` means events are recorded, not pushed. |
| `MAIL_MAILER` | Optional | `log` | Real mailer (SMTP/Postmark/etc.) once sending real mail | `log` in prod means password resets and notifications go to the log file and never reach users. Switching to SMTP requires `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME` (**secret**), `MAIL_PASSWORD` (**secret**), `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`; optional `MAIL_SCHEME`, `MAIL_URL`, `MAIL_EHLO_DOMAIN`, `MAIL_SENDMAIL_PATH`, `MAIL_LOG_CHANNEL`, `POSTMARK_MESSAGE_STREAM_ID` (currently commented out in `config/mail.php`). |

## 5. Auth scaffolding

Not in `.env.example`; framework defaults apply. Listed here so nobody adds
them speculatively.

`AUTH_GUARD` (default `web`), `AUTH_PASSWORD_BROKER` (`users`), `AUTH_MODEL`
(`User::class`), `AUTH_PASSWORD_RESET_TOKEN_TABLE`
(`password_reset_tokens`), `AUTH_PASSWORD_TIMEOUT` (10800s = 3h). Wrong
values break login/password-reset in obvious ways; leave unset.

## 6. Discord OAuth (login)

One OAuth application serves every environment. The callback path is always
`/auth/discord/callback`; only the host differs per environment and each
host must be **added** (not swapped) as a redirect URI in the Discord portal
before switching. Ask the CEO (TWO-21); values arrive through the secrets
channel, never in an issue comment or chat.

| Key | Required? | Local | Staging / Production | What breaks if wrong |
| --- | --- | --- | --- | --- |
| `DISCORD_CLIENT_ID` | **Required** for login | Real ID (secret-ish: treat as secret) | Same single application everywhere | Blank: Discord login button errors. |
| `DISCORD_CLIENT_SECRET` | **Required secret** for login | Real secret | Same | Blank/wrong: token exchange fails, login dead. **Never the bot token** — the bot token lives in the bot process and nowhere else, and these two values cannot be used to derive it. |
| `DISCORD_GUILD_ID` | Optional — **leave blank** | Blank (`config/services.php` defaults to the TWO server `326474832151838730`) | Blank | These **must** belong to the SAME Discord application as the bot. Discord's add-guild-member endpoint (behind one-click join) only accepts a user token from the same application as the calling bot token; a separate login app signs users in fine and silently kills one-click join. Set only to point a box at a different server (e.g. QA's own). Not a secret — verifiable via the guild widget URL. Blank used to mean nobody could sign in; the config default fixed that, so blank is now correct. |
| `DISCORD_INVITE_URL` | Optional — **leave blank** | Blank (defaults to the never-expiring unlimited-use WEB-HOMEPAGE campaign invite) | Blank | Set only to point a box at a different server. Must be an `https` URL on `discord.gg`/`discord.com` — anything else is refused and the hardcoded fallback is served, so this can never become an open redirect. The funnel must work on a fresh deploy with no secret store, so this stays a public link, not a secret. |
| `DISCORD_MODERATOR_ROLE_IDS` | Optional — blank = nobody is admin (safe default) | Blank, or test-server role IDs | Real moderator role IDs, comma-separated | Role **IDs (snowflakes), not names** — renaming a role must not silently grant/revoke admin. Blank means the admin panel has no moderators; a wrong ID locks out real moderators or grants the panel to the wrong roles. Reads through the panel are access-logged (section 8). |
| `DISCORD_API_BASE` | Optional, not in `.env.example` (default `https://discord.com/api/v10`) | Unset | Unset | Only for pointing at a mock in tests. Wrong in prod: all Discord calls fail. |
| `DISCORD_TIMEOUT_SECONDS` | Optional, not in `.env.example` (default 5) | Unset | Unset | Too low: flaky join/role calls under load. Too high: web requests hang on Discord outages. |

## 7. Bot internal-action endpoint

The bot's internal action endpoint (role grants, announcements, Discord
events). Private network only; the shared secret signs the HMAC on every
request.

| Key | Required? | Local | Staging / Production | What breaks if wrong |
| --- | --- | --- | --- | --- |
| `BOT_ENDPOINT_URL` | Required where bot features are used | `http://127.0.0.1:3001` | Private-network URL of the bot | Wrong URL: role grants, announcements, and Discord-event calls fail (typed client errors, one attempt, no retry). Must never be a public URL. |
| `BOT_KEY_ID` | Required alongside the secret | `web-local` | The key id provisioned for that environment | Names which of the bot's `TWO_INTERNAL_KEYS` pairs the secret belongs to. Rotation = add second pair on the bot, point this at it, retire the first. A wrong id returns `unauthorized`, byte-identical to a forged signature — the failure does not tell you which it was. |
| `BOT_SHARED_SECRET` | **Required secret** | Real secret via secrets channel | Per-environment secret via secret controls | Blank/wrong: same indistinguishable `unauthorized` as a wrong key id; all bot actions fail. |
| `BOT_TIMEOUT_SECONDS` | Optional (default 5) | `5` | `5` | Same tuning trade-off as the Discord timeout. |

## 8. Bot Postgres views (read-only)

Read-only Postgres role the bot grants us for its published views (TWO-23).

| Key | Required? | Local | Staging / Production | What breaks if wrong |
| --- | --- | --- | --- | --- |
| `BOT_DB_HOST` / `BOT_DB_PORT` / `BOT_DB_DATABASE` | Optional until the views exist | `127.0.0.1` / `5432` / `two_bot` | Bot's database host/port/name | The site must degrade, not crash, when these are unreachable — leave blank locally until the views exist. |
| `BOT_DB_USERNAME` / `BOT_DB_PASSWORD` (**secret**) | Optional, **leave blank locally** until the views exist | Blank | Read-only credentials provisioned by the bot side | Blank = bot-sourced views unavailable (degraded, by design). Wrong = connection errors on the views that need them. |
| `BOT_DB_TIMEOUT` | Optional, not in `.env.example` (default 2s) | Unset | Unset | Too high: pages depending on bot views hang on bot-DB outages instead of degrading fast. |

## 8b. Join funnel and agent replay-store retention

`join_attempts` grows by one row per join attempt and
`agent_event_idempotency_keys` by one row per agent operation; both are pruned
daily by `model:prune` on their `prunable()` scopes (`routes/console.php`),
which can only ever match rows older than the configured window. The admin
funnel widget counts the table it sees — the retention window, not all time.

| Key | Required? | Local | Staging / Production | What breaks if wrong |
| --- | --- | --- | --- | --- |
| `JOIN_ATTEMPT_RETENTION_DAYS` | Optional (default 90) | `90` | `90` | Narrows or widens the funnel window the admin widget shows; same window as the access log on purpose. |
| `AGENT_EVENTS_IDEMPOTENCY_RETENTION_DAYS` | Optional (default 90) | `90` | `90` | Well past any retry horizon (job backoffs top out at hours). A retry arriving after its row was pruned re-executes; the quota and optimistic-concurrency guards make that duplicate-safe. |

## 9. Member-data access log

Reads of member data through the admin panel are logged (who, when, which
members). See `docs/member-data-access-log.md`. The log is the condition on
configuring the moderator roles (section 6) narrowly.

| Key | Required? | Local | Staging / Production | What breaks if wrong |
| --- | --- | --- | --- | --- |
| `MEMBER_ACCESS_LOG_RETENTION_DAYS` | Optional (default 90) | `90` | `90` | Pruned daily via `routes/console.php`. Shorter is legitimate with a reason; longer needs one too — this is retention of who-read-what, not a tuning knob. |
| `MEMBER_ACCESS_LOG_ENFORCE` | Optional (default `true` **everywhere, including local, on purpose**) | `true` | `true` | `false` means serving member data with no record of who read it. An enforcement switch that is off by default is off in the one place it mattered; a developer who never sees it fail closed will not know that it does. Setting `false` must be an obvious, deliberate line — never a default nobody chose. When enforcement is on and the log write fails, the read is refused with an error. |

## 10. Staging QA seam

| Key | Required? | Local | Staging / Production | What breaks if wrong |
| --- | --- | --- | --- | --- |
| `TWO_WEB_STAGING_QA_AUTH_TOKEN` | Staging only — **unset everywhere else** | Unset | Provisioned through Paperclip/Coolify secret controls; accepted only in the `X-TWO-QA-Auth` header | Set in prod: an extra auth bypass exists where it should not. Leaked (URL, body, log, screenshot, repo): rotate via secret controls. Never put it in a URL, request body, log, screenshot, or repository file. |

## 11. Paperclip (restart-card / operator integration)

Used by `RestartCardClient` (`config/services.php` `paperclip.*`). The client
throws `PaperclipNotConfiguredException::missing(<KEY>)` naming the exact
missing key — the site boots and runs without these; only the Paperclip
restart-card/operator calls fail.

| Key | Required? | Local | Staging / Production | What breaks if wrong |
| --- | --- | --- | --- | --- |
| `PAPERCLIP_API_URL` | Required only for Paperclip calls | Unset (or dev endpoint) | Real API URL | Missing/wrong: restart-card calls throw `missing('PAPERCLIP_API_URL')` / connection errors. |
| `PAPERCLIP_API_TOKEN` | **Required secret** only for Paperclip calls | Unset | Via secret controls | Missing: throws `missing('PAPERCLIP_API_TOKEN')`. Leaked: whoever holds it acts as the integration. |
| `PAPERCLIP_COMPANY_ID` | Required only for Paperclip calls | Unset | Real company id | Missing: throws `missing('PAPERCLIP_COMPANY_ID')`. Wrong: cards land on the wrong company. |
| `PAPERCLIP_API_TIMEOUT_SECONDS` | Optional, not in `.env.example` (default 5) | Unset | Unset | Same tuning trade-off as the other timeouts. |
| `PAPERCLIP_OPERATOR_LABEL_ID` | Required only for restart-card calls | Unset | UUID of the `operator` label (pinned by provisioning card TOG-3573) | Missing: throws `missing('PAPERCLIP_OPERATOR_LABEL_ID')`. Wrong: cards get the wrong label. |
| `PAPERCLIP_PARENT_ISSUE_ID` | **Required**, fail-closed, no default | Unset | Parent issue UUID (pinned by TOG-3573) | The token is a least-privilege `task_bridge` key scoped to one parent issue — every card is a child of this. Missing: throws `missing('PAPERCLIP_PARENT_ISSUE_ID')`; the settings-save action turns that into a rejected save, so unprovisioned environments keep cold settings read-only. Wrong: cards file under the wrong parent. |
| `PAPERCLIP_RESTART_ASSIGNEE_AGENT_ID` | **Required**, fail-closed, no default | Unset | Agent id that runs the restart, e.g. DevOps (pinned by TOG-3573) | The token can assign only to allowlisted agents, never to board users. Missing: throws `missing('PAPERCLIP_RESTART_ASSIGNEE_AGENT_ID')`. Wrong: the card assigns to the wrong agent. |
| `PAPERCLIP_RESTART_BOT_ENVIRONMENT` | **Required**, fail-closed, no default | `staging` | `staging` on staging, `production` on prod | Must be exactly `staging` or `production` (`BotEnvironment` enum). Unknown is as good as missing — a typo throws `missing('PAPERCLIP_RESTART_BOT_ENVIRONMENT')` rather than restarting the wrong bot, so staging can never file a card that restarts production. |

## 12. Test / Dusk seams

| Key | Required? | Local | Staging / Production | What breaks if wrong |
| --- | --- | --- | --- | --- |
| `DUSK_DRIVER_URL` | Local/test only | `http://localhost:9515` | Unset | Wrong: browser tests cannot reach chromedriver. Never needed in staging/prod. |
| `DUSK_TEST_SEAMS` | Test only, not in `.env.example` (default `false`) | `true` only under Dusk | **`false` / unset — never `true`** | `true` outside tests registers the fake `DiscordProvider` and test-only auth paths. Enabling in staging/prod would let anyone sign in as anyone. |
| `DUSK_DISCORD_PROVIDER_URL` | Test only, not in `.env.example` | Test-provider URL under Dusk | Unset | Only read when test seams are on; harmless otherwise, but has no business in a real environment. |
| `CI` | Set by the CI runner, not by humans | Unset locally | Set by the runner | Some tooling changes behaviour (e.g. non-interactive output) when `CI` is present. Setting it locally can mask interactive prompts. |

## 13. Frontend

| Key | Required? | Local | Staging / Production | What breaks if wrong |
| --- | --- | --- | --- | --- |
| `VITE_APP_NAME` | Optional | `"${APP_NAME}"` (interpolates at build time) | Same | Only the display name baked into built assets. Note the quoting: it references `APP_NAME`, so renaming means rebuilding frontend assets. |

## Quick checklists

**New developer:** copy example → `key:generate` → fill `DISCORD_CLIENT_ID` /
`DISCORD_CLIENT_SECRET` (CEO, secrets channel) → `BOT_SHARED_SECRET`
(secrets channel) → `docker compose up -d` → migrate. Leave `DISCORD_GUILD_ID`,
`DISCORD_INVITE_URL`, `SESSION_DOMAIN`, `BOT_DB_USERNAME`/`BOT_DB_PASSWORD`
blank.

**New staging/prod deploy:** `APP_ENV` + `APP_URL` (https, canonical) →
`APP_KEY` (fresh, secret controls) → `APP_DEBUG=false` → real `DB_*` (secret
controls) → `SESSION_DOMAIN=null` → `TWO_WEB_STAGING_QA_AUTH_TOKEN` staging-only
(section 10) → register `APP_URL/auth/discord/callback` in the Discord portal
**before** switching traffic → `MAIL_MAILER` off `log` if sending real mail →
`LOG_LEVEL` up from `debug`.

**Rotating the bot secret:** add the new pair to the bot's
`TWO_INTERNAL_KEYS` → set `BOT_KEY_ID` + `BOT_SHARED_SECRET` here → verify a
bot action → retire the old pair. `unauthorized` after a change means either
the id or the secret is wrong — check both, the error will not say which.

## 14. Staging-to-prod parity (`bin/env-parity.sh`)

`bin/env-parity.sh STAGING_ENV PROD_ENV` diffs the **key names** present in
two dotenv-format snapshots against the required set (`.env.example` plus the
documented-but-not-in-example keys from §§ 1–13). It prints key names and
categories only — **never values**. A snapshot full of live secrets produces
the same output as one full of placeholders, because values are dropped in
the extraction pipeline before they touch a variable. Values are also never
compared: `APP_KEY`, `DB_PASSWORD`, `APP_URL` and friends are *expected* to
differ per environment, so comparing them would cry wolf on every run.

Snapshots are files you create outside the repo from the Coolify dashboard
(or env export) and never commit. Exit `0` = parity, `1` = drift (see below),
`2` = called wrongly. `bin/env-parity.sh --selftest` exercises the contract
offline, including a check that fixture secret values never reach the output.

Drift categories and what to do about each:

| Report line | Meaning | Fix |
| --- | --- | --- |
| `MISSING staging/prod: KEY` | Required key absent on that side | Add it via secret controls (secret) or env config (non-secret). If the key is genuinely not needed there, the decision belongs in `.env.example` / this doc, not in a quieter env — update the source of truth, don't silence the check. |
| `PROD-HAS-STAGING-ONLY TWO_WEB_STAGING_QA_AUTH_TOKEN` | The staging QA seam exists in prod | Remove it from prod, then **rotate** it: it existed where it must not (§10). |
| `FORBIDDEN …: DUSK_TEST_SEAMS` / `DUSK_DISCORD_PROVIDER_URL` | A test seam left the test suite | Unset it immediately on that environment; `true` outside tests lets anyone sign in as anyone (§12). |
| `UNKNOWN …: KEY` | In an env but in neither `.env.example` nor this doc | Either document it here (with required/secret/staging-vs-prod columns) and add it to the example if it belongs there, or remove it from the env. Unknown keys are how quiet `.env` changes become launch-day surprises. |
