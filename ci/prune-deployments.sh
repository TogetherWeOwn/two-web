#!/usr/bin/env bash
#
# Keep the newest KEEP GitHub Deployment records per environment, delete the rest.
#
# Every merge to `main` creates a Deployment record (the `environment:` keys in
# .github/workflows/deploy.yml); nothing deletes them — 148 at the TOG-7649
# readback, +1 per deploy after. The Shipping KPI reads these records, so the
# fix is retention, not silence: keep the newest KEEP per environment and
# delete the rest, oldest first.
#
# Retention is per environment, not global, on purpose. Staging deploys on
# every merge and production deploys almost never; a global keep-N would let
# staging churn evict the whole production history the KPI reads. The unit the
# API organises records by — and the unit the KPI reads — is the environment,
# so it is the unit the prune keeps.
#
# KEEP=30 keeps roughly the last 30 deploys per environment, not the last 30
# days. A burst week of merges narrows the window; a quiet month widens it.
# That is the documented bound (docs/ci.md), not a defect: the schedule is
# weekly, not per-deploy, and history depth is measured in releases.
#
# The API rule that shapes the delete path (docs.github.com, REST /
# Deployments / Delete a deployment): with more than one deployment, only
# INACTIVE deployments delete — anything else is a 422. A record becomes
# inactive when a newer `success` status lands on the same environment, so in
# the ordinary case every candidate already is. When one is not (production
# records, concurrent deploys), the script retires it first — one `inactive`
# status POST naming this script and the card — then deletes it. The transient
# `inactive` state is invisible: the record is deleted in the same run. If the
# retire or the second delete fails, the id is reported and the run exits 1
# after attempting the rest — one stuck record must not block the whole prune,
# and a prune that quietly skipped is TOG-913 theater.
#
# No new credential. This runs in GitHub Actions under GITHUB_TOKEN with
# `deployments: write` (a workflow `permissions:` line, minted per run — see
# .github/workflows/deploy-records-prune.yml). A box-side token would be
# credential distribution for a janitor job, which is owner-reserved; that is
# why there is no artisan command and no scheduler entry for this.
#
# Dry run by default: without --apply the script lists what it would delete
# and deletes nothing. The scheduled workflow passes --apply.
#
# Usage:
#   ./ci/prune-deployments.sh [--keep N] [--env staging,production] [--apply] [--repo OWNER/REPO]
#
# Reads from the environment:
#   GH_REPO / GITHUB_REPOSITORY   the repo, unless --repo is given
#   GH_TOKEN                       via gh itself (never echoed, never logged)
#
# Exit codes are the contract ci/prune-deployments-selftest.sh pins:
#   0  done: the plan was printed (dry run), applied, or there was nothing to do
#   1  a deletion failed, or a record could not be retired — the ids are named
#   2  misconfigured or unreadable: no repo, no gh, no auth, bad flags

set -uo pipefail

KEEP=30
ENVS="staging,production"
APPLY=0
REPO=""

say()  { printf '%s\n' "$*"; }
fail() { printf '\033[31mprune-deployments: %s\033[0m\n' "$*" >&2; }

usage() { sed -n '2,40p' "$0" >&2; }

while [ "$#" -gt 0 ]; do
  case "$1" in
    --keep)
      [ "$#" -ge 2 ] || { fail "--keep needs a value"; usage; exit 2; }
      KEEP="$2"; shift 2 ;;
    --env)
      [ "$#" -ge 2 ] || { fail "--env needs a value"; usage; exit 2; }
      ENVS="$2"; shift 2 ;;
    --apply) APPLY=1; shift ;;
    --repo)
      [ "$#" -ge 2 ] || { fail "--repo needs a value"; usage; exit 2; }
      REPO="$2"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) fail "unknown argument: $1"; usage; exit 2 ;;
  esac
done

# A keep of zero would delete the only record the KPI reads; a non-number is
# not a retention policy. Refuse both before touching the API.
if ! [[ "$KEEP" =~ ^[0-9]+$ ]] || [ "$KEEP" -lt 1 ]; then
  fail "--keep must be a positive integer, got '${KEEP}'"
  exit 2
fi

# Environment names go into a query string unencoded: letters, digits, dash,
# underscore. Anything else is refused rather than escaped — an env name with
# shell or URL metacharacters has no business reaching a delete path.
if ! [[ "$ENVS" =~ ^[A-Za-z0-9_-]+(,[A-Za-z0-9_-]+)*$ ]]; then
  fail "--env must be a comma-separated list of environment names, got '${ENVS}'"
  exit 2
fi

# The repo is never guessed. A delete script that defaults to the wrong repo
# is a catastrophe with a usage string, so every resolution path ends here:
# flag, env, or the origin of this checkout — and an empty answer is exit 2.
if [ -z "$REPO" ]; then
  REPO="${GH_REPO:-${GITHUB_REPOSITORY:-}}"
fi
if [ -z "$REPO" ]; then
  REPO="$(git config --get remote.origin.url 2>/dev/null \
    | sed -E 's#^(git@github\.com:|https://([^@/]+@)?github\.com/)##; s#/+$##; s#\.git$##' || true)"
fi
if ! [[ "$REPO" =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$ ]]; then
  fail "no GitHub repository resolved (got '${REPO:-unset}'). Pass --repo OWNER/REPO or set GH_REPO — this script deletes things and will not guess where."
  exit 2
fi

command -v gh >/dev/null || { fail "gh is not installed"; exit 2; }
command -v jq >/dev/null || { fail "jq is not installed"; exit 2; }
gh auth status >/dev/null 2>&1 || { fail "gh is not authenticated — nothing was read, so nothing is concluded and nothing was deleted."; exit 2; }

SUMMARY=""
summarise() { SUMMARY="${SUMMARY}$1"$'\n'; }

failures=0
failed_ids=""

# delete_one <env> <id> — DELETE, retiring through `inactive` once on refusal.
delete_one() {
  local env="$1" id="$2" out
  if out="$(gh api -X DELETE "repos/${REPO}/deployments/${id}" 2>&1)"; then
    say "  deleted ${id}"
    return 0
  fi
  # The common refusal is 422 on an active record (see the header). Retire it
  # with an `inactive` status naming this script and the card, then delete
  # once more. No message parsing: any first-delete failure takes this path,
  # and a second failure is reported, not reinterpreted.
  say "  deployment ${id} refused a direct delete; retiring it first"
  if ! out="$(gh api -X POST "repos/${REPO}/deployments/${id}/statuses" \
      -f state=inactive \
      -f description="Retired by ci/prune-deployments.sh (TOG-9273): beyond newest ${KEEP} for ${env}; deleting." 2>&1)"; then
    fail "could not retire deployment ${id} (env ${env}): $(tail -n 2 <<< "$out" | tr -d '\n' | cut -c1-300)"
    return 1
  fi
  if ! out="$(gh api -X DELETE "repos/${REPO}/deployments/${id}" 2>&1)"; then
    fail "could not delete deployment ${id} (env ${env}) after retiring it: $(tail -n 2 <<< "$out" | tr -d '\n' | cut -c1-300)"
    return 1
  fi
  say "  retired and deleted ${id}"
  return 0
}

# prune_env <env> — list every deployment for the env, keep newest KEEP.
prune_env() {
  local env="$1" page=1 all='[]' body count total kept=0 deleted=0
  while :; do
    if ! body="$(gh api "repos/${REPO}/deployments?environment=${env}&per_page=100&page=${page}" 2>&1)"; then
      fail "could not list deployments for env ${env} (page ${page}): $(tail -n 2 <<< "$body" | tr -d '\n' | cut -c1-300)"
      return 1
    fi
    # A body that is valid JSON but not an array is an error document, not an
    # empty environment — reading it as "nothing to prune" is the TWO-96 shape
    # (an apology parsed as an answer). Refuse instead.
    if ! jq -e 'type == "array"' >/dev/null 2>&1 <<< "$body"; then
      fail "the deployments response for env ${env} is not an array — refusing to read it as an empty environment."
      return 1
    fi
    count="$(jq 'length' <<< "$body")"
    all="$(jq -n --argjson a "$all" --argjson b "$body" '$a + $b')"
    [ "$count" -lt 100 ] && break
    page=$((page + 1))
    # Unbounded growth is the defect being fixed; an unbounded page loop is
    # how the fix becomes a 6-hour cron. 100 pages is 10,000 records — past
    # that, stop loudly rather than prune a prefix and report done.
    if [ "$page" -gt 100 ]; then
      fail "env ${env} has more than 10,000 deployment records; refusing to prune a prefix and call it done."
      return 1
    fi
  done

  total="$(jq 'length' <<< "$all")"
  if [ "$total" -eq 0 ]; then
    say "env ${env}: no deployments; nothing to do"
    summarise "env ${env}: no deployments"
    return 0
  fi

  # Newest first by id (monotonic), then split at KEEP. A short array slices
  # to empty, which is the whole keep path — never a negative index.
  # Ids only on this list: the dry-run line below prints them, and iterating
  # ids keeps word splitting out of the delete path entirely.
  mapfile -t victims < <(jq -r --argjson keep "$KEEP" \
    '[sort_by(.id) | reverse | .[$keep:] | reverse | .[].id] | .[]' <<< "$all")
  kept=$((total - ${#victims[@]}))
  say "env ${env}: ${total} deployments, keeping newest ${kept}"

  local id
  if [ "${#victims[@]}" -gt 0 ]; then
    for id in "${victims[@]}"; do
      if [ "$APPLY" -eq 1 ]; then
        if delete_one "$env" "$id"; then
          deleted=$((deleted + 1))
        else
          failures=$((failures + 1))
          failed_ids="${failed_ids} ${env}:${id}"
        fi
      else
        say "  would delete ${id}"
      fi
    done
  fi

  if [ "$APPLY" -eq 1 ]; then
    summarise "env ${env}: ${total} total, kept ${kept}, deleted ${deleted}, failed ${failures}"
  else
    summarise "env ${env}: ${total} total, would keep ${kept}, would delete ${#victims[@]}"
  fi
  return 0
}

rc=0
OLD_IFS="$IFS"; IFS=','
for env_item in $ENVS; do
  prune_env "$env_item" || rc=1
done
IFS="$OLD_IFS"

if [ "$APPLY" -eq 0 ]; then
  say "dry run: nothing was deleted. Pass --apply to delete."
fi

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  {
    echo "### Deployment-records prune (TOG-9273)"
    echo
    # shellcheck disable=SC2059
    printf '%s\n' "$SUMMARY" | sed 's/^/- /'
    if [ "$APPLY" -eq 0 ]; then
      echo
      echo "_Dry run — nothing was deleted._"
    fi
  } >> "$GITHUB_STEP_SUMMARY"
fi

if [ "$failures" -gt 0 ]; then
  fail "${failures} deployment(s) could not be deleted:${failed_ids} — they are named, not skipped."
  exit 1
fi

exit "$rc"
