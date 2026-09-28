# Security policy

## Reporting a vulnerability

**Do not open a public issue for a security vulnerability.** Use GitHub's
private vulnerability reporting on this repository
(Security tab → Report a vulnerability). Reports stay visible only to the
reporter and the maintainers until a fix ships.

Include what you can: the affected version or commit, steps to reproduce or a
proof of concept, and the impact as you see it. We will acknowledge receipt,
keep you updated while a fix is prepared, and credit you in the fix unless you
ask us not to.

## Scope notes

- This codebase never holds the Discord bot token — only the website's OAuth
  client id and secret (see README, "Secrets"). A credential that looks like a
  bot token anywhere in this repo is itself a finding.
- `.env.example` holds only variable *names*. If you find a real value committed
  anywhere, treat it as compromised and say so in the report.
