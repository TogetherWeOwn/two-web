# Domains and DNS

Everything we own, what each name points at today, and what has to change. If you
are looking at DNS at 2am, this is the page.

**Last verified against live DNS and HTTP:** 19 August 2026 · **Issue:** TWO-38

---

## Where this is going

The new Laravel site **replaces the WordPress site** — founder's decision.

1. **Now:** build on `staging.togetherweown.com`, walled off.
2. **Launch:** the **apex**, `togetherweown.com`. One record change, scheduled by
   **TWO-61**, once the site is proven on staging.

**There is no intermediate public subdomain, and that is a change from the previous
version of this page.** That version assumed a live WooCommerce Subscriptions store
at the apex that had to keep earning, so the new site had to launch somewhere else
and move later. That store does not exist. The apex is a WordPress.com install from
1 August 2026 with **no products, no sitemap, and one page** — measured below. The
only thing on it worth protecting is the `/discord` link, and protecting that is
easier at the apex than anywhere else.

So the intermediate subdomain buys nothing and costs a lot: a name nobody agreed on,
a permanent 301 source we maintain forever, a forced logout for every member on the
day we move, and a second set of OAuth redirect URIs. **Recommendation: skip it.**
Launch is the apex swap. Rollback is putting the old record back — WordPress stays
intact and paid for through the swap and for two weeks after.

**Nothing in TWO-38 touches the apex `A` record.** TWO-38's job is to make the day
someone does touch it cost seven minutes — see the checklist at the bottom.

---

## What is actually live today

Measured 19 August 2026, not assumed. The apex serves Cloudflare's bot challenge to
a plain `curl`, so **send a browser user agent or you will document a `403`**:

```bash
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'
curl -sSI -A "$UA" https://togetherweown.com/
curl -s -H 'accept: application/dns-json' 'https://cloudflare-dns.com/dns-query?name=<host>&type=TXT'
```

| Name | What it does right now | Verdict |
|---|---|---|
| `togetherweown.com` | WordPress.com, **empty** — title `Together We Own -`, one outbound link, no sitemap, no store | Cut over to us |
| `www.togetherweown.com` | Same origin, same challenge — no redirect to the apex | Fix at cutover |
| `togetherweown.com/discord` | **302 into a live Discord OAuth join flow.** The only working web→Discord path we have | **Must survive. See below** |
| `togetherweown.com/join` | 301 → `/join/` → **HTTP 200, titled "Page Not Found", `<meta name="robots" content="follow, noindex">`** — a soft 404 | No `/join` *page*; a 301 to `/discord` — see below |
| `staging.togetherweown.com` | **Already resolves**, proxied — but **no longer serves the apex page**. WordPress.com now answers `403 Error: Active domain connection for this domain not found` (re-measured 25 August 2026, TOG-427) | Repoint + grey cloud |
| `two.gg`, `www.two.gg` | **302**, path-preserving, to `https://togetherweown.com/<path>` | Make it 301 |
| `two.gg/discord` | **301** to `togetherweown.com/discord/` — already correct | Leave it, retarget at cutover |
| `two.gg/join` | 302 → the apex soft 404. Sends real people to a dead page | Point it at `/discord` |
| `togetherweown.net` | 301 to `https://togetherweown.com/` | Correct, leave it |
| `_dmarc.togetherweown.com` | `v=DMARC1;p=none;` — **no `rua`**, so nobody is collecting anything | Add reporting |
| `togetherweown.com` SPF | `v=spf1 include:_spf.wpcloud.com ~all` | Do not edit blind |
| `two.gg` mail records | **None at all.** No SPF, no DMARC | Free win, see below |
| Apex HSTS | `max-age=31536000`, **no `includeSubDomains`** | TOG-1161 adds `includeSubDomains` at the edge — see Security headers |
| Apex indexing | Depends on the `Accept` header — see below. No `robots.txt` at all | An empty site is indexable — TWO-49, TOG-71 |

### The apex serves two different homepages, and `curl` shows you the wrong one

Corrected 25 August 2026 (TOG-71). The previous version of the row above read
"`<meta name="robots" content="index, follow">`, empty `robots.txt`". Both halves
were artefacts of how they were measured.

The apex answers `vary: accept` and honours it. `Accept: */*` — what `curl` and
`wget` send unless told otherwise — returns the WordPress front page: title
`Together We Own -`, `index, follow`, a self-canonical, full Rank Math schema.
`Accept: text/html,…` — what **every browser and Googlebot** sends — returns the
Bricks coming-soon template: title `Coming Soon – Together We Own`, and **no robots
meta, no canonical, no `og:url`, no schema at all**.

So the tags recorded in the old row are the ones no crawler will ever be served. Add
the browser `Accept` header to the browser UA already at the top of this section, or
you will document a page nobody sees:

```bash
curl -s -A "$UA" -H 'accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' \
  https://togetherweown.com/ | grep -o '<title>[^<]*</title>\|name="robots" content="[^"]*"'
```

And there is no `robots.txt` to be empty: `/robots.txt` **301s to `/robots.txt/`**,
which returns a 129 KB HTML page with `content-type: text/html`. Nothing disallows
anything and the sitemap is never announced.

`ci/live-seo-probe.mjs` measures all of this, sends the right headers, and refuses
to report a result at all when Cloudflare challenges it. Run that instead of
re-deriving it by hand.

Five things fall out of that which change the plan:

- **There is no wildcard DNS record**, contrary to the TWO-41 inventory. A probe for
  `nonexistent-probe-9182.togetherweown.com` returns `NXDOMAIN`. `staging` has its
  own explicit record. That is better news: repointing staging is editing one record
  and cannot leak any other subdomain.
- **`/discord` is the whole funnel.** The apex homepage contains exactly one link and
  it is `https://togetherweown.com/discord`. Everything else here is housekeeping.
- **Do not invent a `/join` path.** The name that already exists, already has a 301 on
  `two.gg`, and is already in people's mouths is `/discord`. One name, not two.
- **`two.gg` is served by a Cloudflare redirect rule with no origin behind it.**
  It has never needed a server and it should stay that way for as long as possible.
- **`two.gg` can have `p=reject` today.** The issue's original instinct — publish
  reject while there is nothing legitimate to break — was right, and with no store
  anywhere in the estate it is very nearly right for the apex too.

### The `/join` soft 404 is measured, not inferred

This one was challenged on the grounds that the apex answers `403 cf-mitigated:
challenge` to automated requests, which is indistinguishable from a 404 from
outside. That is true of a *plain* request and it is why the browser-UA header is at
the top of this section. With the header, the challenge does not fire and the real
page comes back. Reproduce it in ten seconds:

```bash
UA='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'
curl -sS -A "$UA" -L https://togetherweown.com/join -o /tmp/join.html -w '%{http_code} %{url_effective}\n'
grep -o -i '<title>[^<]*</title>\|name="robots" content="[^"]*"' /tmp/join.html
```

Result: `200 https://togetherweown.com/join/`, title `Page Not Found - Together We
Own`, and — the decisive part — `<meta name="robots" content="follow, noindex">`.
**WordPress's own 404 template is telling crawlers not to index it.** A real page
would not carry that. Two independent signals agree, so this needs no founder
round-trip: `two.gg/join` sends real people to a dead page today.

---

## `/discord` is launch-blocking

Today, `togetherweown.com/discord` 302s to:

```
https://discord.com/oauth2/authorize
  ?client_id=456483983870394368
  &scope=identify email guilds.join guilds.members.read
  &redirect_uri=https://togetherweown.com/wp-json/two/v1/callback
  &response_type=code&prompt=consent
```

Three consequences, in order of how much they will hurt if missed:

1. **Whatever answers the apex must answer `/discord` from minute one.** It is the
   only conversion path on the site and the only link on the homepage. A 404 there
   at cutover is a total funnel outage, not a broken redirect.
2. **That `redirect_uri` is a WordPress endpoint and it dies with WordPress.**
   `/wp-json/two/v1/callback` cannot exist on a Laravel box. Whoever owns that
   Discord application has to have our callback allowlisted *before* the swap.
   Also worth confirming: `client_id=456483983870394368` may or may not be the same
   application as `DISCORD_CLIENT_ID` for site login. Someone has to check the
   portal — we cannot, we have no access.
3. **`guilds.join` means today's flow is genuinely one click.** The member authorises
   and is added to the server without ever seeing an invite page. A plain redirect to
   an invite URL is a *worse* funnel than what we have now — an extra page and an
   extra decision. Matching today's behaviour means adding the member via the API,
   which under our integration rules is the bot's job, not Laravel's. **Flagged to
   the CEO as a conversion question, not decided here.**

The Laravel side of this is **TWO-54**, and it is now built — **TOG-77**. What
shipped, so nobody has to read the code to know what the apex will answer:

| Path | Answers | Depends on |
|---|---|---|
| `/discord` | `302` → `services.discord.invite_url`, defaulting to the `WEB-HOMEPAGE` invite from TOG-96, with `Cache-Control: no-store` | nothing — no database, no cache, no session, no bot |
| `/join` | `301` → `/discord` | nothing |

Four properties of that, each pinned by a test in
`tests/Feature/DiscordFunnelTest.php` so they cannot be undone quietly:

- **No database.** Both routes are registered from `routes/funnel.php` with an
  empty middleware stack, *outside* the `web` group. This is not tidiness:
  `SESSION_DRIVER=database` in every environment we ship, so a route in the `web`
  group opens a Postgres connection in `StartSession` before the controller runs.
  The test configures a database-backed session and asserts **zero queries**.
- **No 503 during a deploy.** Both paths are excepted from
  `PreventRequestsDuringMaintenance`, so `php artisan down` does not take the
  funnel dark.
- **Never an open redirect.** The configured invite is refused unless it is an
  `https` URL on `discord.gg` or `discord.com`, and the hardcoded fallback is
  served instead. A mistyped `DISCORD_INVITE_URL` cannot turn the most trusted
  link we own into a way of sending our own members somewhere else.
- **302, not 301, on `/discord`.** The destination changes when TOG-80 lands. A
  301 would already be cached in the browser of every member who had used it —
  the one population we could never reach to correct.

**Point 3 above is still open and this does not close it.** What shipped is the
plain invite redirect, which is the *worse* funnel the point warns about: an
extra page and an extra decision versus today's one click. It is what can be
built without the bot token, and it is strictly better than the 404 that is the
alternative on cutover day. **One-click parity is TOG-80**, owned by the Founding
Engineer, and when it lands it replaces this route's happy path and nothing else
— the invite redirect stays underneath as what TOG-80 falls back to when the bot
is unreachable. So the ordering constraint is: cutover needs TOG-77 (done),
not TOG-80.

---

## Records to create or change

`<PROD_IP>` and `<STAGING_IP>` come out of the hosting decision in **TWO-37**. They
may be the same box to start with. Everything else below is final.

### Staging — one record, blocked only on the IP

| Type | Name | Value | Cloudflare | TTL |
|---|---|---|---|---|
| `A` | `staging` | `<STAGING_IP>` | ⚪ **DNS only (grey cloud)** | Auto |

**This is a repoint of an existing record, not a create.** `staging` resolves today
to the same Cloudflare IPs as the apex. Until the record moves, do not hand the
staging URL to QA or the Designer — and do not read a response from it as evidence
about our app.

**As of 25 August 2026 it serves nothing at all.** It used to return the WordPress
page; it now returns WordPress.com's `403 Error: Active domain connection for this
domain not found`, so the hostname's connection there has lapsed. Reproduce it with
the browser-UA header from the top of this page — a plain `curl` gets Cloudflare's
challenge instead and tells you nothing. Two consequences worth having in writing:

- **Nothing of value is being served there,** so the repoint breaks nothing. That is
  a small piece of good news for whoever finally does it.
- **A `403` from `staging` is not our app failing.** It is the absence of our app.
  There is no two-web deployment behind this name and there never has been —
  `.github/workflows/deploy.yml` still no-ops on an unset `FORGE_STAGING_DEPLOY_HOOK`
  (TOG-13, TOG-104). Anyone asked to "check it on staging" should stop here.

**Grey cloud is deliberate, and we now have evidence.** A plain `curl` to the apex
today comes back `403` with `cf-mitigated: challenge`. QA's Dusk suite is headless
Chrome; it would get the same page. A proxied staging record buys us a red test
suite that has nothing to do with our code.

Walled off three ways, all on the origin:

- **Basic auth** in nginx over the whole site, except requests from `127.0.0.1`, so
  Dusk running on the staging box needs no credentials in the test suite. No
  password in the repository, ever.
- **`X-Robots-Tag: noindex, nofollow`** on every response, set by nginx so it cannot
  be forgotten in application code.
- **`robots.txt` disallowing everything.** Belt and braces.

Its own Let's Encrypt certificate with auto-renewal, same as production.

### The apex — at cutover, not before

| Type | Name | Value | Cloudflare | TTL |
|---|---|---|---|---|
| `A` | `@` | `<PROD_IP>` | 🟠 Proxied | Auto |
| `A` | `www` | `<PROD_IP>` | 🟠 Proxied | Auto |

Proxied is right here: real browsers, real people, free TLS and caching. The origin
still terminates TLS with its own Let's Encrypt certificate — Cloudflare on **Full
(strict)**, never Flexible.

`www` 301s to the apex, in nginx on the origin rather than as a Cloudflare rule, so
the canonical host is decided in the same file as everything else about the site.
The apex is canonical because it is what people say out loud.

**This is one record change and one rollback.** Nobody has to schedule a migration
window for it. TWO-61 picks the day.

### `two.gg`

Leave it as a Cloudflare redirect rule with no origin behind it. Two changes:

| Rule | From | To | Status |
|---|---|---|---|
| 1 | `two.gg/join`, `www.two.gg/join` | `https://<community site>/discord` | **301** |
| 2 | everything else on `two.gg` | `https://<community site>/<path>` | **301** |

> **These two are Rules, not records. A DNS token cannot make either change.**
> Both live in Cloudflare → Rules → Redirect Rules, and `Zone.DNS: Edit` does not
> reach them. They are founder clicks. See *Who can actually change these records*
> at the bottom for the full split of what we can and cannot do ourselves.

- **302 → 301 on the catch-all.** A 302 tells every browser and crawler not to
  remember, so we pay the round trip forever and the link earns us nothing in
  search. `two.gg/discord` is *already* a 301 and needs no change beyond retargeting.
- **`/join` redirects to `/discord`, not the other way round.** `two.gg/join` today
  lands on a soft 404. Rather than build a second name for the same door, keep the
  name that already works. People who have `two.gg/join` in a stream title keep
  working; nobody has to learn a new link.
- **`<community site>` is the apex.** One value in one rule. That is the entire
  `two.gg` cost of the swap.
- **No origin, on purpose.** The most important link we own does not depend on our VM
  being up. If the box is down, the break-glass is one rule edit: point
  `two.gg/discord` straight at the raw Discord invite. Write the invite URL in the
  runbook next to that sentence.

`two.gg/discord` is the link that goes in a stream title or a friend's DM. TWO-38
owns the name and the redirect. **TWO-9** owns what is counted, and **TWO-54** owns
the Laravel route it lands on. Whatever that route does, the rule is absolute: a
member who clicked it must always end up in Discord, even when the invite lookup,
the database or the bot is unavailable.

### `togetherweown.net`

Already a correct 301 to the `.com`. Defensive registration, no separate content.
Documented here so the next person does not find a mystery domain in the registrar.

---

## Mail: SPF and DMARC

Two domains, two different answers, because we know exactly what one of them sends
(nothing) and we have never measured the other.

### `two.gg` — protect it now, it costs nothing

It has no mail records at all — only a stale `google-site-verification` TXT — which
means anyone can spoof `@two.gg` today and no receiver will push back. It sends no
mail and has no origin, so there is nothing to break:

| Type | Name | Value |
|---|---|---|
| `TXT` | `@` | `v=spf1 -all` |
| `TXT` | `_dmarc` | `v=DMARC1; p=reject; rua=mailto:<founder>` |
| `TXT` | `*._domainkey` | `v=DKIM1; p=` |

Hard fail, reject, and an empty DKIM wildcard. If we ever send mail from `two.gg`
we undo this deliberately.

### `togetherweown.com` — staged, because we have never measured it

There is already a `_dmarc` record: `v=DMARC1;p=none;`. It has **no `rua`**, so it
has been collecting nothing this whole time.

The earlier version of this page justified going slowly here by "the apex sends
WooCommerce receipts." It does not — there is no store. The honest reason to still
go slowly is simpler and does not depend on that: **we do not know who sends as this
domain**, because nobody has ever collected a report. WordPress.com sends admin and
password-reset mail, the founder may have mail on the domain, and a forwarder or a
newsletter tool could be in play. `p=reject` with zero visibility bins whatever we
did not know about, silently, until someone complains they never got the mail.

Two weeks of `rua` data is cheap and turns a guess into a list.

| Step | Record | When |
|---|---|---|
| 1 | `_dmarc` TXT → `v=DMARC1; p=none; sp=reject; rua=mailto:<founder>; fo=1` | Now |
| 2 | Read 2–4 weeks of reports, list every legitimate sender, fix SPF and DKIM alignment | After step 1 |
| 3 | `p=quarantine; pct=25`, then `pct=100` | Once step 2 is clean |
| 4 | `p=reject` | Two clean weeks at quarantine |

**The clock starts when `rua` lands, not before.** That is the argument for doing
step 1 this week even though nothing else here can move.

**`sp=reject` from day one is the important bit.** The subdomain policy covers every
name under `togetherweown.com` that sends no mail — `staging`, and anything else
anyone ever adds — so spoofing `billing@staging.togetherweown.com` is dead
immediately while whatever the apex legitimately sends keeps flowing. That is the
protection the issue asked for, available this week, with no measurement needed.

The consequence, written down so it does not ambush us: **before this app sends its
first email**, its sending domain needs SPF and DKIM that align. After cutover this
app *is* the apex, so it is the apex SPF record that has to learn about our sender.
Today `MAIL_MAILER=log` and we send none, so this is a note, not a task.

SPF stays as WordPress has it (`include:_spf.wpcloud.com ~all`) until report data
says otherwise, and it must not be dropped at cutover just because the web server
moved — WordPress.com may still be a legitimate sender. **Do not edit the apex SPF
blind** — one wrong `-all` does the same damage as a bad DMARC policy.

---

## Security headers

Two places set these, and confusing them is how you end up with each header twice:

- **Today, at the Cloudflare edge** (TOG-1161) — because the origin is WordPress.com
  and we cannot configure it. HSTS lives in SSL/TLS → Edge Certificates; the rest in
  one Transform Rule, using *Set* and never *Add*.
- **After cutover, in nginx on the origin** — at which point the edge rule should be
  removed in the same change, not left to double up.

| Header | Value | Note |
|---|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | No `preload` — see below |
| `X-Content-Type-Options` | `nosniff` | |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | |
| `X-Frame-Options` | `SAMEORIGIN` | Was `DENY` here; TOG-1161 specifies `SAMEORIGIN`, so we keep our own pages framable by us |
| `Permissions-Policy` | `geolocation=(), microphone=(), camera=()` | **Exactly these three.** Not `fullscreen=()` or `autoplay=()` — the site embeds YouTube and Vimeo and those two break the players |
| `Content-Security-Policy` | `frame-ancestors 'self'` for now | A full policy is not zero-cost: the homepage has 46 inline `<script>` and 15 `<style>` blocks and pulls from `s0.wp.com`, `stats.wp.com`, `player.vimeo.com`, `www.youtube.com`, so any workable policy needs `'unsafe-inline'`. Own card, with the Frontend Engineer, report-only first |

**The apex already sends `Strict-Transport-Security: max-age=31536000` with no
`includeSubDomains`.** TOG-1161 adds `includeSubDomains` at the Cloudflare edge
*before* cutover. That reverses the instruction this paragraph used to carry, so the
reasoning is worth keeping rather than just deleting.

The old reasoning was "a year-long commitment made from a host we do not control".
That turned out to be wrong about *who sets it*: the header comes from **our own
Cloudflare zone** (`alex`/`betty.ns.cloudflare.com`), not from WordPress.com. The
origin is theirs; the edge is ours, and the edge is where this is set.

What the commitment actually costs was then measured rather than assumed
(2026-09-05, and re-checked before the change):

| host | resolves | TLS | HTTP |
|---|---|---|---|
| `www` | yes | valid (apex + wildcard cert) | 301 → apex |
| `cdn` | yes | valid (own cert) | 404 |
| `staging` | **NXDOMAIN** | — | — |

Every subdomain that resolves already serves valid HTTPS, so `includeSubDomains`
forbids nothing that works today. `staging` no longer resolves at all — it was a 403
when TOG-1161 was written and is now gone (TOG-1156), which removes the one host the
old paragraph was worried about.

**The real cost is the one that survives a rollback.** Removing the directive at the
edge is instant, but browsers that already saw it keep enforcing it for up to
`max-age` — twelve months. So this binds *future* subdomains too: anything stood up
under `togetherweown.com` must be HTTPS from its first request, including short-lived
demo and preview hosts. That is the trade that was accepted, not an oversight. If you
need a plaintext subdomain inside the next year, you cannot have one.

Verify with `node ci/security-headers-check.mjs` rather than by eye.

**No `preload` until after the apex cutover.** Preload is a submission to a list
baked into browsers and it is slow and painful to reverse; it commits every
subdomain of `togetherweown.com`, including whatever WordPress still owns, to
HTTPS-only forever. Revisit it once the apex is ours and stable for a month.

It is also a public commitment made in the company's name, which makes it an owner
decision rather than an operator one — nobody should tick it while they happen to be
in the HSTS panel for `includeSubDomains`. `ci/security-headers-check.mjs` fails if
`preload` appears, so an accidental tick surfaces on the next run instead of in a
year's time.

---

## Cookies and sessions

`SESSION_DOMAIN` stays **null** — host-scoped. It must never be set to
`.togetherweown.com`, which would hand our session cookie to WordPress on every page
view of the store. `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`.

Dropping the intermediate subdomain means **nobody gets logged out at cutover**,
because nobody was ever logged in anywhere else. Members sign in for the first time
on the apex. That is one of the reasons to skip the subdomain, and it is the reason
there is no "announce the logout" line in the checklist below.

---

## The cutover checklist

The apex swap is TWO-61's to schedule. This is what it costs when it comes, and
keeping this list short is a standing obligation on every PR.

**Before the DNS change — founder actions, we have no portal access:**

1. **Add** `https://togetherweown.com/auth/discord/callback` as a redirect URI on the
   site's Discord application. *Add*, do not swap: swapping is a login outage.
2. Confirm what owns `client_id=456483983870394368` (the WordPress `/discord` flow)
   and whether `/discord` on the new site needs to be allowlisted on it too.

**The change itself:**

3. *(us)* `APP_URL` on the production box → `https://togetherweown.com`.
4. *(us)* Issue the apex certificate on the origin **first**, while DNS still points
   at WordPress — DNS-01, or Cloudflare stays proxied and the origin cert is validated
   ahead of the swap. Do not find out about a certificate problem after the cutover.
5. *(us, with the token — this is the one moment the standing rule above is lifted,
   and only because TWO-61 scheduled it)* Apex `A` record → `<PROD_IP>`, `www`
   alongside it. **This is the swap.**
6. **(founder — Rules, the token cannot do this)** Retarget the `two.gg` redirect
   rule to the apex.
7. *(us)* Verify `/discord` in a browser before announcing anything. It is the funnel.
   **"It returned a 302" is not the bar.** Do a real join: a browser that is not
   already signed in to Discord, on an account that is not already in the server.
   A join that works for a signed-in admin proves almost nothing — the admin is
   already a member, so every interesting step is skipped. Recorded on TOG-77 by
   whoever measured the WordPress flow, and it is the one part of `/discord` that
   nothing in this repository can cover: `tests/Feature/DiscordFunnelTest.php`
   pins what we answer with, and no test we own can prove Discord still honours
   the code on the other end.
   *Cheap standing check that needs no account and no browser, good any day of
   the week — a dead code answers `404`:*
   `curl -s "https://discord.com/api/v10/invites/<code>?with_counts=true"`

   Everything on this list that a machine can decide now lives in
   **`ci/cutover-check.mjs`** (TOG-85), so cutover night is the same check every
   time rather than a tired reading of a checklist. Run it on both sides of the
   swap — the expectations invert at the flip:

   ```
   node ci/cutover-check.mjs --phase before --app https://<new app origin>
   node ci/cutover-check.mjs --phase after
   ```

   It exits non-zero on any failure and prints what it *cannot* see: the Discord
   credential rotation and the WordPress.com plan state are console-only. It does
   not replace the real join above — it replaces the parts of the list that were
   being eyeballed.
8. *(us)* Expect new arrivals to land **`pending`** under Rules Screening. A join is
   not yet an active member, and the funnel has to count the two separately or the
   conversion rate reads high and means nothing.

**Schedule the founder into the window, do not just notify them.** Step 6 is the only
step we cannot perform, it sits between the swap and the verification, and until it
happens `two.gg` is still pointing at whatever the old rule said. A cutover where the
founder is asleep is a cutover with a half-moved funnel.

**Rollback** is putting the old apex `A` record back — which, with the token, we can
now do ourselves in under a minute without waking anyone. Keep the WordPress.com
subscription paid for two weeks after.

**What keeps that list this short:** no hostname is written down anywhere in this
codebase. `APP_URL` drives every absolute URL, read only through `env()` in
`config/app.php`, `config/mail.php`, `config/filesystems.php` and the Discord
redirect URI. That is enforced, not hoped for —
`tests/Unit/NoHardcodedHostnamesTest.php` fails the build if `togetherweown.com`,
`togetherweown.net` or `two.gg` appears in `app/`, `config/`, `routes/` or
`resources/views/`. If you need an exception, add it to the allowlist in that file
with a reason.

---

## Who can actually change these records

The zones are in **Cloudflare** under the founder's account.

**Approved:** a Cloudflare API token scoped to `Zone.DNS: Edit` on **both**
`togetherweown.com` and `two.gg` — no account access, no billing, no ability to touch
origin settings. It arrives through the secrets channel as
`cloudflare_dns_token_two_gg`, bound to this agent as `CLOUDFLARE_DNS_TOKEN`. *(The
secret name says `two_gg` for historical reasons — an earlier revision scoped it to
that zone only. It covers both. Not worth a rename; worth knowing when you go
looking.)* It **expires after 90 days** — whoever notices a `403` from the Cloudflare
API first should suspect expiry before suspecting the record.

### What the token can and cannot do

Cloudflare scopes tokens **by zone, not by record type**, and DNS and Rules are
different products. That produces a split worth internalising before you touch
anything:

| Change | Who | Why |
|---|---|---|
| `two.gg` SPF / DMARC / DKIM TXT records | **Us, with the token** | DNS records |
| `_dmarc.togetherweown.com` → add `rua` | **Us, with the token** | DNS record |
| `staging` `A` record repoint | **Us, with the token** | DNS record, once TWO-37 lands an IP |
| `two.gg` catch-all 302 → 301 | **Founder, in Cloudflare → Rules** | Redirect Rule, not DNS |
| `two.gg/join` → `/discord` retarget | **Founder, in Cloudflare → Rules** | Redirect Rule, not DNS |
| Apex `A` record | **Nobody, until TWO-61** | See the standing rule below |

The evidence that the `two.gg` redirects are edge Rules and not records: the response
carries `server: cloudflare` and a `cf-ray` and **no origin headers at all** — no
`x-powered-by`, no WordPress fingerprint. There is nothing behind that hostname to
serve a redirect, so Cloudflare is generating it.

**One nuance that cuts the other way, and it is ours to have caught:** `two.gg` *does*
have DNS records — proxied `A` (`104.21.13.159`, `172.67.156.192`) and `AAAA`. The
Redirect Rule only fires because the hostname resolves to Cloudflare's edge in the
first place. So the token cannot change *where* `two.gg` sends people, but it can
absolutely stop it sending them anywhere, by breaking the record the rule hangs off.
The token is not harmless on `two.gg` either. Which is the whole reason for:

### Standing rule: the funnel does not move casually

**The apex `A` record, the `two.gg` `A`/`AAAA` records, and anything else serving
`/discord` change only as part of the TWO-61 cutover — never as a side effect of
routine DNS work.**

`togetherweown.com/discord` is a live Discord OAuth join flow and currently **the only
web→Discord conversion path TWO has**. `two.gg/discord` is the spoken shortcut into
it. Between them they are the entire funnel this whole project exists to grow. A
staging repoint or a DMARC edit must never be the thing that takes them dark.

This is a team rule, not a permission boundary — the token can reach these records and
we are trusting ourselves not to. Worst case if we get it wrong is a coming-soon page
and a dark join button for the minutes it takes to put the record back, which is
survivable but is not something to discover on a Friday.

**Fallback if the token does not arrive or has expired:** the founder applies the
tables above by hand and we verify each one. That is why every record in this document
is written out in full, with its exact value. Keep it that way.
