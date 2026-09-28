# Security Policy

## Reporting a vulnerability

**Do not open a public issue, discussion, or pull request for a suspected
vulnerability.** Details in a public thread disclose the flaw to everyone
before a fix exists.

Report through **GitHub's private vulnerability reporting** on this
repository (`Security` tab → `Report a vulnerability`). That opens a private
advisory visible only to you and the maintainers.

If private reporting is unavailable to you, open a minimal public issue that
contains **no details** — title it `Security report — private channel
requested` — and a maintainer will arrange a private channel.

Include in the private report:

- Affected commit or branch, and how to reproduce (steps, requests, config).
- What an attacker gains (read/modify/impersonate whom, and at what scale).
- Logs or output with **secrets redacted**. Never paste a live credential into
  any report, comment, or attachment — if one slips in, say so so it can be
  rotated.

Please do not probe production or other members' accounts, and do not
mass-message members as part of testing.

## Scope

In scope for this repository (`two-web`, the community website):

- Application code, routes, middleware, auth (Discord OAuth login flow),
  sessions, RSVP/event logic, moderator admin, migrations, and Blade views.
- The credential boundary documented in the README: this app holds the
  Discord **OAuth** client id and secret and must **never** hold the Discord
  **bot token**. A finding that the bot token (or any live secret) is
  reachable from, committed to, or exfiltratable through this repo is in scope
  and severe.

Out of scope:

- The `two-bot` repository and Discord's own platform (report Discord issues
  to Discord; tell us if they are exploitable through this site).
- Third-party dependencies (report upstream; still tell us if the flaw is
  reachable here and we will upgrade or mitigate).
- Social engineering, physical attacks, volumetric DDoS, and spam.

## Response SLA

- **Acknowledge** your private report within **72 hours**.
- **Triage with an initial severity assessment** within **7 days**.
- Fix prioritisation follows severity: remote code execution, auth bypass,
  and credential-boundary crossings first. We will keep you updated in the
  private advisory and agree a disclosure date with you before anything goes
  public.

## Coordinated disclosure

We ask reporters not to disclose the issue publicly before a fix and an
advisory are published. We credit reporters who want credit and keep
anonymous those who do not.

## What already guards this repo

- Required `gitleaks` secret scan on every pull request and every push to
  `main` (`.github/workflows/secret-scan.yml`, rules in `.gitleaks.toml`).
  A secret that reaches any branch is treated as leaked: **rotate it** —
  deleting the line is not enough, it is in the history.
- The CI merge gate on `main` (`docs/ci.md`): formatting, static analysis,
  tests, and the secret scan must all be green.

## Supported versions

Pre-launch: only the latest `main` is supported. We do not backport security
fixes to older commits.
