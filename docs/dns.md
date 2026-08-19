# Domains and DNS

Everything we own, what each name points at, and what has to change. If you are
looking at DNS at 2am, this is the page.

**Last checked:** 19 August 2026 · **Issue:** TWO-38

---

## Rule 0: `togetherweown.com` is not empty

The apex runs a **live WordPress site with WooCommerce Subscriptions** — it takes
recurring payments and sends order and renewal email today. GamiPress, AutomatorWP,
Bricks and Rank Math are installed on top of it. It sits behind Cloudflare.

**Do not repoint the apex at this Laravel app.** That is not a DNS change, it is a
store migration with revenue attached, and nobody has asked for it. Until the
founder says otherwise, WordPress keeps the apex and we take a new hostname.

---

## What we own

| Name | Today | Phase 1 plan |
|---|---|---|
| `togetherweown.com` | Live WordPress store, Cloudflare proxied | **Untouched** |
| `www.togetherweown.com` | Resolves to the same place | **Untouched** — WordPress owns the apex/www canonical choice |
| `togetherweown.net` | Ours, redirects to the `.com` | Keep the redirect, document it, 301 not 302 |
| `<hostname>.togetherweown.com` | Does not exist | **The new site.** Name pending — see below |
| `staging.togetherweown.com` | Does not exist | Staging for this app. Walled off |
| `two.gg` | Ours | 301 to the community home |
| `two.gg/join` | Ours | Tracked Discord invite |

### Open question: what is the new site called

Recommendation on the table is `hub.togetherweown.com`. The founder has not
answered (asked on TWO-21). **Nothing in this repository hardcodes a hostname** —
`APP_URL` in `.env` drives every absolute URL the app generates (checked: only
`config/app.php`, `config/mail.php`, `config/filesystems.php` and the Discord
redirect URI read it, all through `env()`). When the answer lands, the change is:

1. `APP_URL` on the production box.
2. The Discord OAuth **redirect URI allowlist** in the Discord developer portal —
   that is a founder action, we do not have portal access.
3. One `A` record.

That is the whole cost of the decision. It is not worth blocking other work over.

---

## Records to create

`<PROD_IP>` and `<STAGING_IP>` come out of the hosting decision in **TWO-37**. They
may be the same box to start with. Everything else below is final.

### The new site — after the hostname answer

| Type | Name | Value | Cloudflare | TTL |
|---|---|---|---|---|
| `A` | `<hostname>` | `<PROD_IP>` | 🟠 Proxied | Auto |
| `AAAA` | `<hostname>` | `<PROD_IPV6>` if the host has one | 🟠 Proxied | Auto |

Proxied is fine here: real browsers, real people, and we get Cloudflare's TLS and
caching for free. Origin still terminates TLS with its own Let's Encrypt
certificate and auto-renewal — Cloudflare set to **Full (strict)**, never Flexible.

### Staging — ready to go now

| Type | Name | Value | Cloudflare | TTL |
|---|---|---|---|---|
| `A` | `staging` | `<STAGING_IP>` | ⚪ **DNS only (grey cloud)** | Auto |

**Grey cloud is deliberate.** Cloudflare's bot challenge pages break headless
Chrome, and headless Chrome is how QA's Dusk suite runs. A proxied staging record
would give us a red test suite that has nothing to do with our code.

Staging is walled off three ways, all on the origin:

- **Basic auth** in nginx over the whole site, with one exception: requests from
  `127.0.0.1` skip it, so Dusk running on the staging box itself does not need
  credentials baked into the test suite. No password in the repository, ever.
- **`X-Robots-Tag: noindex, nofollow`** on every response, added by nginx so it
  cannot be forgotten in application code.
- **`robots.txt` disallowing everything.** Belt and braces — basic auth already
  stops a crawler, but a misconfigured proxy one day might not.

Its own Let's Encrypt certificate, same auto-renewal as production.

### `two.gg`

| Type | Name | Value | Cloudflare | TTL |
|---|---|---|---|---|
| `A` | `@` | `<PROD_IP>` | 🟠 Proxied | Auto |
| `A` | `www` | `<PROD_IP>` | 🟠 Proxied | Auto |

`two.gg` is served by **the same Laravel app**, matched on the request host — not a
second service, not a second deploy, not a link-shortener dependency. Two routes:

- `two.gg/*` → 301 to the community home, except:
- `two.gg/join` → records the click, then redirects to the tracked Discord invite.

The click write lands in our Postgres, which is where TWO-9's invite attribution
already expects to read it. One deploy, one database, one place to look when the
numbers disagree. Build it when TWO-9 has settled the invite-link contract.

### `togetherweown.net`

Stays a **301 to `togetherweown.com`**, apex and `www`. It is a defensive
registration, not a second front door — no separate content, no separate
certificate story beyond the redirect host. Written down here so the next person
does not find a mystery domain in the registrar account.

---

## Mail: SPF and DMARC, carefully

The original plan said publish `p=reject` immediately "while there is nothing
legitimate to break." **That was written before we knew the apex sends mail.** It
does — WooCommerce order confirmations, subscription renewal notices, WordPress
password resets, Jetpack. Publishing `p=reject` without enumerating those senders
would silently bin customer receipts for a store that takes recurring payments.
Silently, because nobody reads their own DMARC failures until a customer complains.

The staged rollout, which gets us the same protection a few weeks later without
that risk:

| Step | Record | When |
|---|---|---|
| 1 | `_dmarc` TXT: `v=DMARC1; p=none; sp=reject; rua=mailto:<founder>; fo=1` | Now |
| 2 | Read two to four weeks of reports, list every legitimate sender, fix SPF and DKIM alignment for each | After step 1 |
| 3 | `p=quarantine; pct=25`, then `pct=100` | Once step 2 is clean |
| 4 | `p=reject` | Two clean weeks at quarantine |

**`sp=reject` from day one is the important bit.** The subdomain policy covers every
name under `togetherweown.com` that sends no mail — including ours — so spoofing
`billing@hub.togetherweown.com` is dead immediately, while the apex's real receipts
keep flowing. That is the protection the issue actually wanted, available now.

SPF stays as WordPress has it until the report data says otherwise. **Do not edit
the apex SPF record blind** — one wrong `-all` has the same effect as a bad DMARC
policy.

When this app starts sending account email (it sends none today; `MAIL_MAILER=log`),
it gets its own subdomain sender with its own SPF and DKIM, and `sp=` is revisited
in the same change. Not before.

---

## Security headers

Unchanged from the issue, all set in nginx on the origin so they apply on both the
new site and staging:

| Header | Value |
|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` — add `preload` only after the new hostname has been stable for a month |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `X-Frame-Options` | `DENY` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), interest-cohort=()` |
| `Content-Security-Policy` | Written with the Frontend Engineer once the asset origins are known. Report-only first |

**`includeSubDomains` is a commitment.** Every subdomain of `togetherweown.com`,
including anything WordPress adds later, must then be HTTPS-only forever. If we set
HSTS on the apex we need the founder's agreement; on our own hostname we do not.
Start on ours.

---

## Cookies: keep them on our hostname

If the app lives at `hub.togetherweown.com` while WordPress holds the apex, the
session cookie must be **host-scoped**. `SESSION_DOMAIN` stays `null` in production
— it must never be set to `.togetherweown.com`, which would hand our session cookie
to WordPress on every page view of the store. `SESSION_SECURE_COOKIE=true` and
`SESSION_SAME_SITE=lax` alongside it.

---

## Who can actually change these records

The zones are in **Cloudflare**, under the founder's account. We do not have
access. Two ways to make this work, and one is much better:

**Recommended:** a Cloudflare API token scoped to `Zone.DNS: Edit` on
`togetherweown.com` and `two.gg` only — no account access, no billing, no ability
to touch the WordPress origin settings. It arrives through the secrets channel,
never an issue comment. That way a certificate or record fix at 2am does not need
to wake the founder.

**Fallback:** the founder applies the table above by hand and we verify.

Either way the apex `A` record is not ours to touch, token or no token.
