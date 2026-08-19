# Domains and DNS

Everything we own, what each name points at today, and what has to change. If you
are looking at DNS at 2am, this is the page.

**Last verified against live DNS:** 19 August 2026 · **Issue:** TWO-38

---

## Where this is going

The new Laravel site **replaces the WordPress site** — founder's decision. It does
not do that on day one:

1. **Now:** build on `staging.togetherweown.com`.
2. **Launch:** a subdomain of `togetherweown.com`. WordPress keeps the apex.
3. **Later:** the apex becomes this app, as the *last* step of the WooCommerce /
   GamiPress migration. That inventory and its cost are **TWO-41**, not this issue.

There is a live WooCommerce Subscriptions install at the apex taking recurring
payments from real members. It keeps earning until the replacement is genuinely
complete. **Nothing in TWO-38 touches the apex `A` record.**

What TWO-38 owes the future is a *cheap* cutover — see the checklist at the bottom.
Nothing here should be built as if the subdomain split were permanent.

---

## What is actually live today

Measured, not assumed. Re-check with `curl -sSI https://<host>/` and
`curl -s -H 'accept: application/dns-json' 'https://cloudflare-dns.com/dns-query?name=<host>&type=TXT'`.

| Name | What it does right now | Verdict |
|---|---|---|
| `togetherweown.com` | WordPress + WooCommerce, Cloudflare proxied, bot challenge on | Leave alone |
| `www.togetherweown.com` | Same place, same challenge — no redirect to the apex | WordPress' call, not ours |
| `staging.togetherweown.com` | **Already resolves**, proxied, same origin as the apex | Repoint + grey cloud |
| `two.gg`, `www.two.gg` | **302**, path-preserving, to `https://togetherweown.com/<path>` | Make it 301, retarget |
| `two.gg/join` | 302s to `togetherweown.com/join`, which is not a page | Broken today |
| `togetherweown.net` | 301 to `https://togetherweown.com/` | Correct, leave it |
| `_dmarc.togetherweown.com` | `v=DMARC1;p=none;` — **no `rua`**, so nobody is collecting anything | Add reporting |
| `togetherweown.com` SPF | `v=spf1 include:_spf.wpcloud.com ~all` | Do not edit blind |
| `two.gg` mail records | **None at all.** No SPF, no DMARC | Free win, see below |

Three things fall out of that which change the plan:

- **The staging record already exists and is orange-clouded.** This is a repoint,
  not a create, and somebody has to remember to turn the cloud off.
- **`two.gg` is served by a Cloudflare redirect rule with no origin behind it.**
  It has never needed a server and it should stay that way for as long as possible.
- **`two.gg` can have `p=reject` today.** The issue's original instinct — publish
  reject while there is nothing legitimate to break — was right; it is just right
  for `two.gg`, not for the domain that sends WooCommerce receipts.

---

## Records to create or change

`<PROD_IP>` and `<STAGING_IP>` come out of the hosting decision in **TWO-37**. They
may be the same box to start with. Everything else below is final.

### Staging — one record, blocked only on the IP

| Type | Name | Value | Cloudflare | TTL |
|---|---|---|---|---|
| `A` | `staging` | `<STAGING_IP>` | ⚪ **DNS only (grey cloud)** | Auto |

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

### The launch subdomain

| Type | Name | Value | Cloudflare | TTL |
|---|---|---|---|---|
| `A` | `<hostname>` | `<PROD_IP>` | 🟠 Proxied | Auto |

Proxied is right here: real browsers, real people, free TLS and caching. The origin
still terminates TLS with its own Let's Encrypt certificate — Cloudflare on **Full
(strict)**, never Flexible.

The name is still open (asked on TWO-21; `hub` was the standing suggestion). It
matters less than it did, because it is now temporary — but pick it knowing **it
becomes a permanent 301 source** the day the apex takes over. Every link a member
pastes in Discord between launch and cutover has to keep working forever.

### `two.gg`

Leave it as a Cloudflare redirect rule. Two changes:

| Rule | From | To | Status |
|---|---|---|---|
| 1 | `two.gg/join`, `www.two.gg/join` | `https://<community site>/join` | **301** |
| 2 | everything else on `two.gg` | `https://<community site>/<path>` | **301** |

- **302 → 301.** A 302 tells every browser and crawler not to remember, so we pay
  the round trip forever and the link earns us nothing in search.
- **`<community site>` is the launch subdomain now and the apex after cutover.** One
  value, changed once, in one Cloudflare rule. That is the entire two.gg cost of the
  apex swap.
- **No origin, on purpose.** The most important link we own does not depend on our
  VM being up. `/join` itself is a Laravel route (below) — but if the box is down,
  the break-glass is one rule edit: point `two.gg/join` straight at the raw Discord
  invite. Write the invite URL in the runbook next to that sentence.

`two.gg/join` is the link that goes in a stream title or a friend's DM. Its target,
and how the click is attributed, belong to **TWO-9**'s invite contract. TWO-38 owns
the name and the redirect; TWO-9 owns what is counted. Default shape: a Laravel
`/join` route that records the click and redirects to the tracked invite, and if the
invite lookup fails for any reason it still redirects to a permanent fallback invite
rather than showing an error. A member who clicked join must always land in Discord.

### `togetherweown.net`

Already a correct 301 to the `.com`. Defensive registration, no separate content.
Documented here so the next person does not find a mystery domain in the registrar.

---

## Mail: SPF and DMARC

Two domains, two different answers, because one of them sends money email and the
other sends nothing.

### `two.gg` — protect it now, it costs nothing

It has no mail records at all, which means anyone can spoof `@two.gg` today and no
receiver will push back. It sends no mail and has no store, so there is nothing to
break:

| Type | Name | Value |
|---|---|---|
| `TXT` | `@` | `v=spf1 -all` |
| `TXT` | `_dmarc` | `v=DMARC1; p=reject; rua=mailto:<founder>` |
| `TXT` | `*._domainkey` | `v=DKIM1; p=` |

Hard fail, reject, and an empty DKIM wildcard. If we ever send mail from `two.gg`
we undo this deliberately.

### `togetherweown.com` — staged, because the apex sends real receipts

There is already a `_dmarc` record: `v=DMARC1;p=none;`. It has **no `rua`**, so it
has been collecting nothing this whole time. Jumping that to `p=reject` would
silently bin WooCommerce order confirmations, renewal notices and password resets —
silently, because nobody reads their own DMARC failures until a customer complains.

| Step | Record | When |
|---|---|---|
| 1 | `_dmarc` TXT → `v=DMARC1; p=none; sp=reject; rua=mailto:<founder>; fo=1` | Now |
| 2 | Read 2–4 weeks of reports, list every legitimate sender, fix SPF and DKIM alignment | After step 1 |
| 3 | `p=quarantine; pct=25`, then `pct=100` | Once step 2 is clean |
| 4 | `p=reject` | Two clean weeks at quarantine |

**The clock starts when `rua` lands, not before.** That is the argument for doing
step 1 this week even though nothing else here can move.

**`sp=reject` from day one is the important bit.** The subdomain policy covers every
name under `togetherweown.com` that sends no mail — including ours — so spoofing
`billing@<our subdomain>` is dead immediately while the apex's real receipts keep
flowing. That is the protection the issue wanted, available now.

The consequence, written down so it does not ambush us: **before this app sends its
first email**, its sending subdomain needs SPF and DKIM that align, or `sp=reject`
will bin our own account mail. Today `MAIL_MAILER=log` and we send none.

SPF stays as WordPress has it (`include:_spf.wpcloud.com ~all`) until report data
says otherwise. **Do not edit the apex SPF blind** — one wrong `-all` does the same
damage as a bad DMARC policy.

---

## Security headers

Set in nginx on the origin so they apply to the launch subdomain and staging alike:

| Header | Value |
|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `X-Frame-Options` | `DENY` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=()` |
| `Content-Security-Policy` | Written with the Frontend Engineer once asset origins are known. Report-only first |

**No `preload` until after the apex cutover.** Preload is a submission to a list
baked into browsers and it is slow and painful to reverse; it commits every
subdomain of `togetherweown.com`, including whatever WordPress still owns, to
HTTPS-only forever. Revisit it once the apex is ours and stable for a month.

---

## Cookies and sessions

`SESSION_DOMAIN` stays **null** — host-scoped. It must never be set to
`.togetherweown.com`, which would hand our session cookie to WordPress on every page
view of the store. `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`.

That stays correct after cutover, and it means **everyone logged in on the launch
subdomain gets logged out once when the apex takes over.** We accept that rather
than building cookie sharing: re-login is one Discord OAuth click. It goes in the
cutover announcement, not in the code.

---

## The cutover checklist

The apex swap is TWO-41's to schedule. This is what it costs when it comes, and
keeping this list short is a standing obligation on every PR.

1. `APP_URL` on the production box.
2. **Add** the apex Discord OAuth redirect URI in the developer portal, keeping the
   subdomain one, *before* the DNS change. Both live through the transition, remove
   the old one after. Swapping instead of adding is a login outage. Founder action —
   we do not have portal access.
3. Apex `A` record → `<PROD_IP>`, and `www` alongside it.
4. Launch subdomain becomes a 301 to the apex, permanently.
5. The one `two.gg` Cloudflare rule retargets to the apex.
6. Certificate for the apex, plus HSTS; `preload` becomes reconsiderable.
7. Announce the one-time logout.

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

The zones are in **Cloudflare** under the founder's account. We do not have access,
and that is currently the binding constraint on the three items above that need no
hosting decision at all: the `two.gg` 301, the `two.gg` mail records, and the
`_dmarc` `rua`.

**Recommended:** a Cloudflare API token scoped to `Zone.DNS: Edit` on
`togetherweown.com` and `two.gg` only — no account access, no billing, no ability to
touch the WordPress origin settings. Delivered through the secrets channel, never an
issue comment. A certificate or record fix at 2am then does not need to wake the
founder.

**Fallback:** the founder applies the tables above by hand and we verify.

Either way the apex `A` record is not ours to touch, token or no token, until
TWO-41 says so.
