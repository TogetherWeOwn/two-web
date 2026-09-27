# Publication scrub map — two-web (TOG-5363, leaf of TOG-4818)

Every live role annotation / Discord snowflake and every internal-DNS /
infrastructure reference in `two-web` on `main` (base `1e622f9`), **outside the
two paths the sibling sweep already covers** (`docs/dns.md` and the quoted-ID
example in `app/Console/Commands/CheckDiscordModerators.php`, both rewritten on
branch `TOG-4818-two-web-publication-prep-sweep-busl-hosted-ci`, commits
`eb990de` / `485171a` — neither is an ancestor of this branch, so they are
listed in §5 as overlap, not re-done here).

Disposition for each marker is exactly one of:

- **KEEP** — with the justification for keeping it verbatim at publication.
- **PARAMETERIZE** — with the replacement. Code changes for these belong on
  follow-up cards; this leaf only records them.

Standing rule used below: Discord snowflakes (guild, role, channel, user IDs)
are **public, not secret** — every member of the server can read them, and the
guild ID is served unauthenticated by the widget endpoint. This is already the
documented position in `.gitleaks.toml` ("There is nothing to rotate"). What
must never be published is anything that *grants* access or reaches private
infrastructure: tokens, secrets, private hostnames, deploy hooks.

## 1. Live Discord snowflakes in code/config (KEEP — all of them)

| Marker | Where | Disposition |
|---|---|---|
| Guild `326474832151838730` (the ONE TWO server) | `config/services.php:73` default for `services.discord.guild_id`, with docblock + public widget check URL (`:62-63`) | KEEP — public ID; the default is load-bearing (a blank guild stops every member at the door invisibly). Override exists via `DISCORD_GUILD_ID`. |
| Same guild ID, comment only | `.env.example:66,69` | KEEP — comment text, no credential. |
| Moderator role `508654771276873729` (`SySOp`, TOG-106 signed-off staff list) | `CheckDiscordModerators.php:59` (`SYSOP` const); `app/Http/Controllers/Auth/StagingQaLoginController.php:21` (`MODERATOR_ROLE_ID`); `docs/ci.md:398,400` (staging `.env` table + prose) | KEEP — public role ID; the grant itself stays env-only (`DISCORD_MODERATOR_ROLE_IDS`, deliberately **no** code default so blank = revocation). Hardcoding the *reference* value is what lets the check command and the staging QA identity verify the live grant instead of a guess. |
| Retired privileged roles `1078757544169848933` (Officer), `1087192823767515219` (Staff), `1078757266469175386` (Game Master), `1078757184021733426` (Captain), `1078756990710452365` (Lieutenant) | `CheckDiscordModerators.php:80-86` (`DOOMED` map with holder counts + deletion waves) | KEEP — all five roles are **deleted** (Wave 6 / role-consolidation), so the snowflakes match nobody, forever. They are the documented plausible-wrong-answer the check guards against; removing them would re-open the widening TOG-106 reversed. |
| WordPress `/discord` flow `client_id=456483983870394368` | `ci/cutover-check.mjs:58` (`OLD_APP_ID`) | KEEP — public OAuth client_id (it appears in the live site's redirect URL today); the check needs it to assert the old flow is gone after cutover. |
| Invite `https://discord.gg/4GwEDNRTtx` (`WEB-HOMEPAGE`, TOG-96) | `config/services.php:97` default + `DiscordInviteController.php:57` (`FALLBACK_INVITE`) | KEEP — public join link by design; the duplication is deliberate and pinned by `DiscordFunnelTest` (the two must match). Rotating it reattributes web joins, so it rotates deliberately, not by scrub. |

## 2. Synthetic / fixture IDs (KEEP — not live, listed so nobody re-audits them)

`9000000000000xxx` series, `111222333444555666`, `424242424242424242`,
`111111111111111111`, faker `numberBetween(10^17, 10^18-1)` in
`database/factories/UserFactory.php:15`, `random_int(...)` in
`IssueCiSessionCookie.php:55`, `bot.internal` / `configured.internal` /
`control.example.test` / `twitch.tv/togetherweown` in bot/Paperclip tests —
all fixtures in `tests/`, `ci/dusk-stub.mjs`, or CI-only commands. One to know
about: `CheckDiscordModeratorsTest.php:147` uses `1112759027554844763` as a
"live, non-doomed role" to exercise the UNKNOWN path. Its liveness is
unverified and irrelevant — the test only needs *a well-formed snowflake that
is not SySOp and not doomed*. KEEP as fixture; do not "scrub" test data into
unreadability.

`MemberDataAccessLogTest.php:50,60` reuses the *guild* ID as a `discord_id`
fixture. Sloppy but harmless (tests only). KEEP; optional tidy-up, not a
publication blocker.

## 3. Hostnames / DNS / infra references (KEEP, except one PARAMETERIZE)

| Marker | Where | Disposition |
|---|---|---|
| `Sitemap: https://togetherweown.com/sitemap_index.xml` | `public/robots.txt:3` | **PARAMETERIZE** — the only hardcoded production hostname in a served file, and `NoHardcodedHostnamesTest` does not scan `public/`, so nothing pins it. Serve `robots.txt` from a route using `url()`/`APP_URL` (the sitemap body already does this via `route()`), or extend the test's `$scan` dirs to include `public/`. Follow-up card, not this leaf. Publication-safe either way (the apex is the public canonical host), but it contradicts the repo's own no-hardcoded-hostname rule. |
| `staging.togetherweown.com` in the `X-Robots-Tag` map | `nginx.template.conf:35` (+ self-test `ci/php-runtime-config-selftest.sh:72,81`) | KEEP — the mechanism that keeps staging out of indexes; it *must* name the staging host to match on it. Not secret (staging name is operational, and the record is currently deleted per TOG-1160). |
| `127.0.0.1` defaults (DB, mail, `fastcgi_pass 127.0.0.1:9000`, `BOT_ENDPOINT_URL=http://127.0.0.1:3001`) | `.env.example`, `config/database.php:23,60`, `config/mail.php:44`, `nginx.template.conf:62` | KEEP — loopback, not routable, standard local-dev defaults. The bot URL default is loopback-only, which is the correct posture (private network). |
| `localhost:8000`, `localhost:5432`, `localhost:9515` | `README.md`, `CONTRIBUTING.md`, `.env.example`, tests | KEEP — local-dev documentation and fixtures. |
| `staging.` / apex callback examples | `tests/Feature/Auth/DiscordRedirectUriTest.php:28-29,57-72`, `bootstrap/app.php:47-48` (comments) | KEEP — pinned registered-URI examples the redirect builder is tested against; changing them breaks the Discord character-for-character match coverage. |
| Public-DNS operational checks | `ci/cutover-check.mjs`, `ci/mail-auth-check.mjs`, `ci/staging-access.mjs`, `ci/staging-exposure-check.mjs`, `ci/live-seo-probe.mjs` + selftests | KEEP — these *are* the published inventory tooling; they query public DNS and assert on it. Scrubbing the domain names out of them would destroy their function. |
| `git@github.com:TogetherWeOwn/two-web.git`, composer name `togetherweown/two-web` | `CONTRIBUTING.md:12`, `composer.json:3`, `ci/verify-run-preconditions-selftest.sh:76` | KEEP — the public repo path post-publication. |
| `togetherweown.com` mentions in prose docs | `docs/member-data-model.md:110` (withdrawn import), `README.md:112` (subdomain launch language — **stale**: `docs/dns.md` now recommends skipping the intermediate subdomain and launching at the apex), `docs/wordpress-apex/README.md` | KEEP, with one note: README's "launches on a subdomain" paragraph drifts from the current apex-swap decision. Docs tidy-up, not a scrub issue. |
| `Paperclip/Coolify` in `.env.example:89` (comment), `docs/cold-setting-restart-cards.md:96` (restart command deliberately **not** pinned — host-ops knowledge, fail-closed via `RestartCommandNotPinnedException`) | comments + decision record | KEEP — no live command, hostname, or credential is present; the decision record explicitly refuses to fabricate one. |
| `services.paperclip` block (`PAPERCLIP_API_URL/TOKEN/COMPANY_ID/OPERATOR_*`) | `config/services.php:148-159` | KEEP — already fully parameterized (env-only, fail-closed when absent). No live URL, token, or UUID in the repo. |
| Audited runner prefixes (`coolify-vps-*`, `ci-rbx1-*`, `ci-w2494-*` — operator-audited per `ab9d101`), `COOLIFY_*_DEPLOY_HOOK`, `STAGING_URL` | `.github/workflows/*.yml`, `ci/deploy-target.sh`, `ci/attest-runner*.sh`, `ci/runner-ports.sh`, `ci/reclaim-ports.sh` | KEEP while private — see §4. |

## 4. Build / deployment separation while private

No production deployment can be triggered from this repo by an untrusted
actor today: `ci/deploy-target.sh` fails closed when the Coolify hooks are
unset (TOG-913), there is no `production` job in `deploy.yml`, and every job
attests its runner (`ci/attest-runner.sh`, TOG-2847). The workflow files
reference self-hosted labels (`two-selfhosted`) and secret names
(`COOLIFY_STAGING_DEPLOY_HOOK`, `COOLIFY_PRODUCTION_DEPLOY_HOOK`) — names,
not values; nothing to scrub, and renaming them would break the staging path
before the parent's CI migration (TOG-4818 work item 3, hosted-only runners)
replaces them. **Disposition: KEEP while private; the hosted-runner migration
owns their removal, not this leaf.** Publication approves no production
deployment (parent gate).

## 5. Overlap with the sibling sweep (not re-done here)

- `docs/dns.md` full inventory (live `client_id`, `wpcomstaging` CNAME,
  Cloudflare proxy IPs, `cloudflare_dns_token_two_gg` secret name /
  `CLOUDFLARE_DNS_TOKEN` binding, DMARC `rua` mailbox, SPF includes) —
  rewritten deployment-neutral in `eb990de` on the sibling branch.
- `CheckDiscordModerators.php:229` quoted live-ID example — synthetic in
  `eb990de`.
- `SYSOP` + 5 retired IDs moved to
  `services.discord.sysop_role_id` / `retired_moderator_role_ids` config +
  `DiscordModeratorReferenceConfigTest` — `485171a` on the sibling branch.
- Publication CI isolation on GitHub-hosted runners — `205bde6` on the sibling
  branch.

At merge time, whichever branch lands second rebases onto the first; the
union of §1–§4 above with the sibling diff is the complete map (zero live IDs
unmapped — the acceptance for this leaf).

## Verification (this leaf)

- `grep -rEn '[0-9]{17,20}'` over the repo minus `vendor/node_modules/.git`:
  every hit is classified in §1, §2, or §5 above; no unmapped live snowflake
  remains.
- `grep -rEn 'togetherweown|two\.gg|discord\.gg|bot\.internal|coolify|forge'`
  over `app/ config/ routes/ resources/ lang/ database/ bootstrap/ public/
  storage/ docker/`: every hit is classified in §3–§5; the single
  PARAMETERIZE (`public/robots.txt:3`) is recorded with its follow-up.
- `NoHardcodedHostnamesTest` scope (`app/ config/ routes/ resources/views/`)
  is clean by construction — confirmed by grep returning zero hostname hits
  in those dirs except test fixtures using `discord.gg` example URLs (covered
  by the test's own design, not exceptions).
- Docs-only change: no app code touched, no test semantics altered.
