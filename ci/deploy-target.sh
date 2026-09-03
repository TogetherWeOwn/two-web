#!/usr/bin/env bash
#
# Resolve the staging deploy target, or refuse.
#
# This exists because the thing it replaced reported success for work it did not
# do. `deploy.yml` used to hold this logic inline:
#
#     if [ -z "$HOOK" ]; then
#       echo "ready=false" >> "$GITHUB_OUTPUT"
#       echo "::notice::FORGE_STAGING_DEPLOY_HOOK is not set. Skipping, not failing."
#
# Every later step was gated on `ready == 'true'`, so with no secret the job
# exited success in about three seconds having deployed nothing, and the run
# rendered green. On TOG-48 an agent read that green as "it shipped" and told the
# owner the landing page was live on staging. The agent was wrong; the CI had told
# them so. That is the whole defect: a control that passes for work it did not do
# will keep manufacturing false claims regardless of who is on shift (TOG-913).
#
# The original reasoning was not stupid — "a missing deploy target must never look
# like a broken build; that is how a team learns to ignore a red X" — it was a bet
# that the target would arrive soon. It did not. Forge was superseded by the
# owner's 2026-08-31 decision (Coolify, self-hosted; TOG-780, TOG-407 closed), so
# `FORGE_STAGING_DEPLOY_HOOK` will never be set and that branch would have skipped
# silently forever while reporting success. An unprovisioned deploy is not a
# passing deploy. It is a deploy that did not happen, and the run must say so.
#
# Being red every time `main` moves, until Coolify exists, is intended. It is also
# why the refusal below names the card and the exact next action rather than just
# failing: an expected red that explains itself is a status, while an unexplained
# one is the noise the old header was rightly worried about.
#
# It is a script, and not eight lines of YAML, so that it can be *executed* — with
# the environment set every way that matters — by ci/deploy-target-selftest.sh. A
# guard that has never been observed to fail is indistinguishable from one that
# cannot fail, and this one's whole job is to fail correctly.
#
# Usage:
#   ./ci/deploy-target.sh            # refuse, or print the resolved target
#
# Reads from the environment, all optional as far as bash is concerned and all
# required as far as this script is concerned:
#
#   COOLIFY_STAGING_DEPLOY_HOOK   the deploy webhook URL, token included (secret)
#   STAGING_URL                   e.g. https://staging.togetherweown.com (variable)
#   COOLIFY_PRODUCTION_DEPLOY_HOOK  tripwire only; nothing here reads it (secret)
#
# Writes `hook=` and `url=` to $GITHUB_OUTPUT when it resolves, so the workflow can
# use the normalised values rather than re-deriving them. Never echoes the hook:
# the URL carries the token.
#
# Exit codes are the contract the self-test pins:
#   0  a target is fully configured
#   1  a target is missing or partial — nothing was deployed and nothing pretended
#   2  the script was called wrongly

set -uo pipefail

if [ "$#" -gt 0 ]; then
  printf 'deploy-target: takes no arguments, got: %s\n' "$*" >&2
  exit 2
fi

say()  { printf '%s\n' "$*"; }
fail() { printf '\033[31mdeploy-target: %s\033[0m\n' "$*" >&2; }

# `${VAR:-}` and then a whitespace strip, because an Actions secret that exists but
# is empty, and one that holds a stray newline from a copy-paste, are both "unset"
# for every purpose that matters here. Treating "  " as a configured target is how
# you get a curl to nowhere reported as a deploy.
strip() { printf '%s' "${1:-}" | tr -d '[:space:]'; }

HOOK="$(strip "${COOLIFY_STAGING_DEPLOY_HOOK:-}")"
URL="$(strip "${STAGING_URL:-}")"
PRODUCTION_HOOK="$(strip "${COOLIFY_PRODUCTION_DEPLOY_HOOK:-}")"

# Tripwire. Nothing in this repo consumes a production hook: production ships by
# hand from the hosting dashboard after the checklist in docs/ci.md, because the
# `environment:` approval gate is unenforceable on our plan (TWO-91, TOG-118). If
# one appears, somebody is part-way through rebuilding the path that was removed.
# A warning and not a failure — this is a staging deploy and it should not go red
# over a secret it never reads.
if [ -n "$PRODUCTION_HOOK" ]; then
  say "::warning::COOLIFY_PRODUCTION_DEPLOY_HOOK is set, but this workflow deploys staging only and nothing reads that secret. Production deploys are manual, from the hosting dashboard, after the release checklist in docs/ci.md. See TWO-91 and TOG-118 before adding a production job back."
fi

# Both halves are required, and a partial configuration is worse than none: a hook
# with no URL would POST and then have no way to find out whether anything came
# back up, which is the same false green in a smaller box.
missing=""
[ -n "$HOOK" ] || missing="COOLIFY_STAGING_DEPLOY_HOOK (secret)"
if [ -z "$URL" ]; then
  [ -z "$missing" ] && missing="STAGING_URL (variable)" || missing="${missing} and STAGING_URL (variable)"
fi

if [ -n "$missing" ]; then
  fail "no staging deploy target: ${missing} is not set."
  say "::error title=Staging was not deployed::${missing} is not set, so this run deployed nothing. This job fails rather than passing, because a green deploy must mean something was deployed (TOG-913). Staging has no target until Coolify is stood up — see TOG-780, which is staged for unfreeze; the deploy layer decision was Coolify, self-hosted (owner, 2026-08-31), superseding Forge. To clear this: provision Coolify, then set the repository secret COOLIFY_STAGING_DEPLOY_HOOK and the repository variable STAGING_URL. Do not re-add a skip-and-pass guard."
  {
    echo "### Deploy: staging was NOT deployed"
    echo
    echo "\`${missing}\` is not set, so there was no target to deploy to."
    echo
    echo "This run is **red on purpose**. It used to be green here, and on TOG-48 that"
    echo "green was read as \"the landing page is live on staging\" and reported to the"
    echo "owner. Nothing had shipped. A deploy job that passes without deploying is a"
    echo "false claim generator, so it now fails (TOG-913)."
    echo
    echo "**This is expected until Coolify exists.** It is not a broken build and it is"
    echo "not something to re-run. See TOG-780."
    echo
    echo "| To clear it | |"
    echo "|---|---|"
    echo "| 1 | Stand up Coolify (TOG-780) |"
    echo "| 2 | Set secret \`COOLIFY_STAGING_DEPLOY_HOOK\` |"
    echo "| 3 | Set variable \`STAGING_URL\` |"
  } >> "${GITHUB_STEP_SUMMARY:-/dev/null}"
  exit 1
fi

# Trailing slash off, so the health poll builds `${URL}/up` and never `${URL}//up`.
URL="${URL%/}"

if [ -n "${GITHUB_OUTPUT:-}" ]; then
  {
    echo "hook=${HOOK}"
    echo "url=${URL}"
  } >> "$GITHUB_OUTPUT"
fi

# The hook is never printed: its URL carries the token. The health URL is public.
say "staging deploy target resolved. Health check will poll ${URL}/up"
exit 0
