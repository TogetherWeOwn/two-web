#!/usr/bin/env bash
#
# Resolve the production deploy target, or refuse.
#
# The staging twin is ci/deploy-target.sh, and this is the same contract for
# production: a deploy with no target is a failure, never a skip-and-pass
# (TOG-913 — on TOG-48 a green that deployed nothing was reported to the owner
# as "live on staging"). Two differences from the twin:
#
#   - Both halves are required: COOLIFY_PRODUCTION_DEPLOY_HOOK (secret) and
#     PRODUCTION_URL (variable, e.g. https://togetherweown.com). A hook with no
#     URL would POST and then have no way to learn whether anything came back
#     up — the same false green in a smaller box.
#   - Reaching this script already means a human chose `production` in
#     `workflow_dispatch` AND the `production` environment's required reviewer
#     approved. This script checks the target, not the approval — GitHub
#     enforces that half on paid Enterprise (verified on TOG-6912 via
#     host-token readback TOG-7649: required reviewer Rick7C2,
#     prevent_self_review=true, protected branches on). The job also
#     stays gated until the Ship target card
#     ([TOG-6902]) says TWO Web may go live.
#
# It is a script, and not lines of YAML, for the reason the twin gives: so it
# can be *executed* — with the environment set every way that matters — by
# ci/deploy-target-selftest.sh. A guard nobody has watched fail is
# indistinguishable from one that cannot fail.
#
# Usage:
#   ./ci/deploy-target-production.sh            # refuse, or print the resolved target
#
# Reads from the environment, all optional as far as bash is concerned and all
# required as far as this script is concerned:
#
#   COOLIFY_PRODUCTION_DEPLOY_HOOK  the deploy webhook URL, token included (secret)
#   PRODUCTION_URL                  e.g. https://togetherweown.com (variable)
#
# Writes `hook=` and `url=` to $GITHUB_OUTPUT when it resolves, so the workflow can
# use the normalised values rather than re-deriving them. Never echoes the hook:
# the URL carries the token.
#
# Exit codes mirror the staging guard, and the self-test pins them:
#   0  a target is fully configured
#   1  a target is missing or partial — nothing was deployed and nothing pretended
#   2  the script was called wrongly

set -uo pipefail

if [ "$#" -gt 0 ]; then
  printf 'deploy-target-production: takes no arguments, got: %s\n' "$*" >&2
  exit 2
fi

say()  { printf '%s\n' "$*"; }
fail() { printf '\033[31mdeploy-target-production: %s\033[0m\n' "$*" >&2; }

# `${VAR:-}` and then a whitespace strip, because an Actions secret that exists but
# is empty, and one that holds a stray newline from a copy-paste, are both "unset"
# for every purpose that matters here. Treating "  " as a configured target is how
# you get a curl to nowhere reported as a deploy.
strip() { printf '%s' "${1:-}" | tr -d '[:space:]'; }

HOOK="$(strip "${COOLIFY_PRODUCTION_DEPLOY_HOOK:-}")"
URL="$(strip "${PRODUCTION_URL:-}")"

# Both halves are required, and a partial configuration is worse than none: a hook
# with no URL would POST and then have no way to find out whether anything came
# back up, which is the same false green in a smaller box.
missing=""
[ -n "$HOOK" ] || missing="COOLIFY_PRODUCTION_DEPLOY_HOOK (secret)"
if [ -z "$URL" ]; then
  [ -z "$missing" ] && missing="PRODUCTION_URL (variable)" || missing="${missing} and PRODUCTION_URL (variable)"
fi

if [ -n "$missing" ]; then
  fail "no production deploy target: ${missing} is not set."
  say "::error title=Production was not deployed::${missing} is not set, so this run deployed nothing. This job fails rather than passing, because a green deploy must mean something was deployed (TOG-913). To clear this: set the repository secret COOLIFY_PRODUCTION_DEPLOY_HOOK and the repository variable PRODUCTION_URL (e.g. https://togetherweown.com). Production stays gated until [TOG-6902] says TWO Web may go live — do not set the hook until then. Do not re-add a skip-and-pass guard."
  {
    echo "### Deploy: production was NOT deployed"
    echo
    echo "\`${missing}\` is not set, so there was no target to deploy to."
    echo
    echo "This run is **red on purpose**. A deploy job that passes without deploying is a"
    echo "false claim generator, so it fails (TOG-913)."
    echo
    echo "Deploying production also needs a human twice over: `workflow_dispatch` with"
    echo "\`production\` chosen, plus the \`production\` environment's required reviewer."
    echo "It stays gated until [TOG-6902] says TWO Web may go live."
    echo
    echo "| To clear it | |"
    echo "|---|---|"
    echo "| 1 | Set secret \`COOLIFY_PRODUCTION_DEPLOY_HOOK\` |"
    echo "| 2 | Set variable \`PRODUCTION_URL\` |"
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
say "production deploy target resolved. Health check will poll ${URL}/up"
exit 0
