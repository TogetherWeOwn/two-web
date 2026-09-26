# Domains and DNS

This is deployment guidance, not a live inventory. Keep the actual zone names,
origin addresses, OAuth application IDs, provider account details and rollback
record values in the deployment operator's access-controlled runbook. Historical
measurements are not instructions to change today's DNS.

All names below are examples. This document changes no records, credentials,
application configuration or deployment permissions.

## Application configuration

- Set `APP_URL` to the canonical HTTPS URL for the deployment, for example
  `https://community.example.com`. Do not hardcode a deployment hostname in app
  code. `tests/Unit/NoHardcodedHostnamesTest.php` enforces the project's hostname
  boundary.
- Register the deployment's `/auth/discord/callback` URL on its Discord OAuth
  application **before** moving traffic. Add the callback first; do not remove a
  working callback during preparation. Keep application IDs and secrets in the
  environment, not in this document.
- Keep `SESSION_DOMAIN` null (host-scoped), `SESSION_SECURE_COOKIE=true` and
  `SESSION_SAME_SITE=lax`. Sharing a cookie with unrelated subdomains broadens its
  exposure.
- Keep `DISCORD_MODERATOR_ROLE_IDS` in deployment configuration. Public examples
  use synthetic snowflakes; never copy them into a real moderator allowlist.

## Staging

Provision a working origin and access controls before publishing a staging DNS
record. A missing provider target or an upstream error page is **not** an access
control. Verify both IPv4 and IPv6; an overlooked `AAAA` record can keep an origin
reachable after its `A` record is removed.

Use authentication or an equivalent explicit access gate, an
`X-Robots-Tag: noindex, nofollow` response header and a robots policy that disallows
indexing. Browser tests must exercise the application rather than a proxy's bot
challenge. Record the chosen proxy mode and how test clients authenticate in the
private deployment runbook; do not solve testing by exposing staging publicly.

Staging needs a valid TLS certificate and renewal. A proxied DNS answer does not
reveal the underlying record type or origin: read the authoritative provider
configuration before documenting a rollback.

## Canonical domain and redirects

Choose one canonical HTTPS hostname. Redirect alternate names to it while
preserving paths, and verify the `/discord` and `/join` entry points explicitly.
An HTTP 200 can be a soft 404; inspect the page or redirect destination as well as
the status code. DNS records and provider redirect rules are separate controls,
and permission to edit one does not imply permission to edit the other.

Preserve the community's join route throughout a cutover. The Laravel route
contract is covered by `tests/Feature/DiscordFunnelTest.php`; that does not prove
a provider-side redirect, invite or OAuth callback works. Verify the complete
flow in a browser using a test account that is not already a member.

## Mail and HTTPS policy

Moving the web origin does not establish who sends mail for a domain. Inventory
legitimate senders before changing SPF, DKIM or DMARC; do not remove an existing
sender merely because the web server moved.

- Keep SPF within the RFC 7208 DNS-lookup budget.
- Generate DKIM through the mail provider. An empty `p=` revokes a key; a DNS
  record alone does not prove messages are being signed. Verify received-message
  authentication results too.
- Stage DMARC enforcement using actual reports. Consider forwarded mail, which
  may lose SPF alignment and depend on a valid DKIM signature.
- Validate TLS at the origin as well as at any reverse proxy. Do not use a proxy
  mode that sends sensitive application traffic to the origin over HTTP.
- Add HSTS `includeSubDomains` only after every covered subdomain supports HTTPS.
  Preload is a separate, long-lived commitment, not part of routine DNS work.

## Cutover checklist

Publication of this repository does **not** authorize a deployment or DNS change.
Use the approved release process in [CI and deployment](ci.md).

Before an authorized cutover:

1. Re-read the current provider configuration. Privately record the exact old
   record types, values, proxy modes and TTLs, plus the tested rollback procedure.
2. Verify the new origin, TLS certificate, access policy, application environment
   and OAuth callback registrations without moving production traffic.
3. Verify provider redirects and the join flow. Coordinate with the operator
   responsible for each control; an application release cannot change a provider
   redirect rule by itself.
4. Make only the approved record changes. Verify both address families, canonical
   redirects, `/discord`, `/join`, authentication and application health.
5. Keep the prior origin available for the agreed rollback window. Roll back to
   the recorded configuration if the release acceptance checks fail.

The repository includes `ci/cutover-check.mjs`, `ci/staging-exposure-check.mjs`,
`ci/mail-auth-check.mjs` and `ci/live-seo-probe.mjs`. Read each tool's target and
options before use: some are project-specific probes, not generic DNS clients.
Their self-tests can validate probe behavior without changing live records. A
probe result is evidence for the target and time measured, not permission to
change infrastructure.
