#!/usr/bin/env bash
#
# Proves branch protection on `main` enforces what the pipeline claims.
#
# `ci/verify-pipeline.sh` proves the *jobs* go red for the right reasons. It does
# not prove a red job blocks the merge, and those are different facts. Every
# assertion in that script about the gate holding rests on one thing it never
# reads: that the job it watched go red is a required context on `main` in
# GitHub. `REQUIRED_CHECKS` in that file is what this repo *believes* is required.
# GitHub is what *is* required. The whole failure class both scripts exist to
# catch is a gate that reports one thing and enforces another, so believing the
# array over the API is that same bug, one level up again.
#
# This came out of the TWO-22 acceptance run. On the `ci-verify/gate` branch the
# aggregate `tests` was SKIPPED — GitHub counts a skipped required check as a
# passed one — and the argument for why that PR was still blocked was "`static`
# went red and `static` is required". The first half was observed. The second half
# was assumed, from an array in a shell script. That is the half this file reads.
#
# The list lives in four places: this repo's ci/verify-pipeline.sh, docs/ci.md,
# two-bot/scripts/setup-github.sh (TWO-39), and the protection rule itself. The
# first three check each other. Only the fourth one gates.
#
# Usage:
#   ./ci/verify-protection.sh                 # read live protection via gh
#   ./ci/verify-protection.sh path/to.json    # assert against a saved response
#
# The file form takes no network and no credential, which is what the self-test
# uses and what lets anyone re-check a recorded rule after the fact.
#
# Live mode needs `gh`, authenticated, with **Administration: read** on the repo.
# That is a separate permission from the three ci/verify-pipeline.sh needs; a
# token that can open PRs cannot necessarily read the protection rule, and the
# failure is a 404 that reads identically to "the branch is not protected".
#
# Exits:
#   0  the rule was read and it enforces what the pipeline claims
#   1  the rule was read and it does not, or there is no rule — a finding
#   2  the rule could not be read — no verdict either way
#
# 2 is not a softer 1. A caller that collapses them is back to the bug of TWO-96:
# treating "I could not check" as "I checked and it is bad" is how a red report
# gets written about a branch nobody read. Both fail closed; only 1 is evidence.

set -euo pipefail

BASE_BRANCH="main"
PIPELINE="$(dirname "$0")/verify-pipeline.sh"
SOURCE="${1:-}"

fail() { printf '\033[31mFAIL: %s\033[0m\n' "$*" >&2; rc=1; }
pass() { printf '\033[32mPASS: %s\033[0m\n' "$*"; }
warn() { printf '\033[33mwarn: %s\033[0m\n' "$*"; }
log()  { printf '\n\033[1m==> %s\033[0m\n' "$*"; }

rc=0

# Could not check. Never a verdict about the rule — see the exit codes above.
undetermined() { printf '\033[31mFAIL: %s\033[0m\n' "$*" >&2; exit 2; }

command -v jq >/dev/null || undetermined "jq is not installed, so nothing here can read a protection rule."

# --- the list this repo believes in -----------------------------------------
#
# Parsed out of ci/verify-pipeline.sh rather than copied, so there is no fifth
# place for the list to drift in. An empty parse is a failure, not an empty
# comparison that passes: "compared nothing, found no differences" is exactly how
# a check goes quiet.
required_from_source() {
  sed -n 's/^REQUIRED_CHECKS=(\(.*\))$/\1/p' "$PIPELINE" | tr ' ' '\n' | grep -v '^$' || true
}

[ -f "$PIPELINE" ] || undetermined "$(printf '%s not found — run this from the repo' "$PIPELINE")"
expected=$(required_from_source)
[ -n "$expected" ] || undetermined "$(printf 'could not read REQUIRED_CHECKS out of %s. Refusing to check an empty list against the protection rule: that reports green while asserting nothing.' "$PIPELINE")"

# --- what a response that is not a rule means --------------------------------
#
# TWO-96. `gh api` writes the API's error body to *stdout* and its own summary to
# stderr, so a refused call leaves output that is non-empty and valid JSON. The
# guard that used to sit here tested the body for emptiness, which those bodies
# pass, and every assertion below then read a field off an error message, found
# it absent, and printed a full red report describing an unprotected branch.
#
# Five answers arrive down that one path and they do not mean the same thing:
#
#   200                                     a rule exists — assert against it
#   404 Branch not protected                genuinely unprotected — red is right
#   404 Not Found                           the token cannot see the repo
#   403 Resource not accessible ...         no `Administration: read`
#   403 Upgrade to GitHub Pro ...           the plan has no such feature at all
#
# Only the second is a finding about this repo's rule. The third and fourth are
# facts about the reader and support no verdict at all. The fifth is a finding
# with a different owner and a different remedy — you cannot fix by configuration
# something the plan does not offer.
#
# Matched on the message before the status, because the two 403s and the two 404s
# differ only in the message, and it is exactly those pairs the old guard could
# not tell apart.
not_a_rule() {
  local status="$1" msg="$2"

  case "$msg" in
    'Branch not protected'*)
      printf '\033[31mFAIL: `%s` is not protected. There is no rule, so every job in ci.yml is advisory: a pull request with every check red has a green merge button, and the six-box gate holds only while people choose to look.\033[0m\n' \
        "$BASE_BRANCH" >&2
      exit 1 ;;
    'Upgrade to GitHub'*)
      printf '\033[31mFAIL: GitHub'"'"'s plan for this repository has no branch protection to configure (HTTP %s: %s). This is not a rule someone forgot to set — on this plan the API refuses to hold one, so `main` cannot be protected at all until the plan changes. Nothing in the pipeline gates until then, and the fix is a purchase, not a setting.\033[0m\n' \
        "${status:-403}" "$msg" >&2
      exit 1 ;;
    'Resource not accessible'*|'Must have admin rights'*)
      undetermined "$(printf 'this token cannot read the protection rule on `%s` (HTTP %s: %s) — it is missing `Administration: read`. That is a fact about the token, not about the branch, so this run cannot conclude the rule is missing *or* present. Grant the permission and run again.' "$BASE_BRANCH" "${status:-403}" "$msg")" ;;
    'Not Found'*)
      undetermined "$(printf 'GitHub returned 404 Not Found, which for this endpoint means the token cannot see the repository — a protected branch and an invisible one answer the same way. `Branch not protected` is the reply that would mean unprotected, and this is not it, so this run cannot conclude either way.')" ;;
  esac

  case "$status" in
    401)
      undetermined "$(printf 'GitHub rejected the credential (HTTP 401: %s). Nothing was read, so nothing is concluded.' "${msg:-Bad credentials}")" ;;
  esac

  undetermined "$(printf 'the response is not a branch protection rule%s%s. It parsed, but it carries none of the keys a rule has, so it is a malformed or unrelated response rather than an empty rule — and an empty rule would be a finding while this is only a reason to stop.' \
    "${status:+ (HTTP $status)}" "${msg:+: $msg}")"
}

# --- the rule GitHub actually applies ---------------------------------------
http_status=""
if [ -n "$SOURCE" ]; then
  [ -f "$SOURCE" ] || undetermined "$(printf '%s not found' "$SOURCE")"
  json=$(cat "$SOURCE")
  log "Protection rule from ${SOURCE}"
else
  command -v gh >/dev/null || undetermined "gh is not installed"
  gh auth status >/dev/null 2>&1 || undetermined "gh is not authenticated"

  slug=$(git config --get remote.origin.url \
    | sed -E 's#^(git@github\.com:|https://([^@/]+@)?github\.com/)##; s#/+$##; s#\.git$##' || true)
  [[ "$slug" =~ ^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$ ]] || undetermined "$(printf 'origin is not a GitHub repository (%s). The protection rule only exists on GitHub, so this has to run in a clone whose origin is GitHub — not a workspace clone and not a local mirror. Save the response with `gh api repos/OWNER/REPO/branches/main/protection > p.json` elsewhere and pass the file.' \
      "$(git config --get remote.origin.url 2>/dev/null || echo unset)")"

  log "Protection rule on ${slug}:${BASE_BRANCH}"

  # `-i` for the status line. It is the only part of the response that says
  # whether the body is a rule or an apology, and reading the body without it is
  # TWO-96. `|| true` because gh exits non-zero on a non-2xx *and still prints* —
  # the exit code says a call failed, the status line says how.
  response=$(gh api -i "repos/${slug}/branches/${BASE_BRANCH}/protection" 2>/dev/null) || true
  response=${response//$'\r'/}
  http_status=$(head -1 <<< "$response" | awk '{print $2}')
  json=$(sed -e '1,/^$/d' <<< "$response")

  [[ "$http_status" =~ ^[0-9]{3}$ ]] || undetermined "$(printf 'gh returned no HTTP response for repos/%s/branches/%s/protection — no status line, so the request never reached GitHub or gh failed before sending it. Silence is not an answer about the rule.' \
    "$slug" "$BASE_BRANCH")"

  if [ "$http_status" != "200" ]; then
    not_a_rule "$http_status" "$(jq -r '.message // ""' <<< "$json" 2>/dev/null || true)"
  fi
fi

jq -e . >/dev/null 2>&1 <<< "$json" || undetermined "protection response is not JSON"

# A body can be valid JSON, an object even, and still not be a rule — an error
# body is the common case, and the file form reaches it too: the usage above
# tells you to save the response with `gh api ... > p.json`, and on a failed call
# that file holds the apology. Stop here rather than fall through, because every
# assertion below would read its field off that object, find it absent, and
# report an unprotected branch that nobody looked at.
jq -e 'type == "object" and (
         has("url") or has("required_status_checks") or has("required_pull_request_reviews")
         or has("enforce_admins") or has("allow_force_pushes") or has("allow_deletions")
         or has("required_linear_history") or has("required_conversation_resolution")
         or has("lock_branch") or has("block_creations")
       )' >/dev/null 2>&1 <<< "$json" \
  || not_a_rule "$http_status" "$(jq -r 'if type == "object" then (.message // "") else "" end' <<< "$json" 2>/dev/null || true)"

# 1. Required status checks exist at all. Without this block every job in the
#    pipeline is advisory: they all run, they all report, and a pull request with
#    every red check has a green merge button.
if ! jq -e '.required_status_checks' >/dev/null 2>&1 <<< "$json"; then
  fail "no required status checks on \`${BASE_BRANCH}\`. Every job in ci.yml is advisory — a pull request with every check red still merges."
  contexts=""
else
  # Both shapes: `checks[].context` is current, `contexts[]` is the deprecated
  # field GitHub still returns. Rules written through the older API populate only
  # the second, and reading one shape would report an empty rule as no rule.
  contexts=$(jq -r '
    ((.required_status_checks.checks // []) | map(.context))
    + (.required_status_checks.contexts // [])
    | unique | .[]' <<< "$json")

  # 2. Everything the pipeline claims is required, is. A job that goes red while
  #    not being required is the finding this whole exercise turns on.
  missing=""
  while read -r check; do
    [ -n "$check" ] || continue
    grep -qxF "$check" <<< "$contexts" || missing="${missing} ${check}"
  done <<< "$expected"
  if [ -n "$missing" ]; then
    fail "required check(s)${missing} are not required on \`${BASE_BRANCH}\`. They run, they report, and a pull request merges over them red. Live contexts: $(echo "$contexts" | tr '\n' ' ')"
  else
    pass "every check in REQUIRED_CHECKS is a required context ($(echo "$expected" | tr '\n' ' ' | sed 's/ $//'))"
  fi

  # 3. The reverse: a required context no job produces never arrives, and GitHub
  #    blocks on a missing required context forever. This is how a renamed job
  #    wedges the repo — the symptom is every PR pending, not red, which reads as
  #    a slow runner rather than a broken rule.
  stale=""
  while read -r context; do
    [ -n "$context" ] || continue
    grep -qxF "$context" <<< "$expected" || stale="${stale} ${context}"
  done <<< "$contexts"
  if [ -n "$stale" ]; then
    fail "required context(s)${stale} are not produced by any job the pipeline runs. A required context that never reports blocks every pull request forever, pending rather than red. Either the job was renamed or the rule names the workflow instead of a job — there is no job called \`ci\`."
  else
    pass "no required context that nothing reports"
  fi

  # 4. Merging a branch whose base has moved on can put a combination on `main`
  #    that no pull request ever tested. Not one of TWO-22's bullets, so it warns.
  jq -e '.required_status_checks.strict == true' >/dev/null 2>&1 <<< "$json" \
    || warn "\`strict\` is off: a pull request can merge without being up to date with \`${BASE_BRANCH}\`, so a green PR can still land a red \`main\`."
fi

# 5. No self-merge. GitHub has no switch by that name; requiring an approving
#    review is the switch, because an author cannot approve their own pull
#    request. Zero required approvals is self-merge, spelled differently.
approvals=$(jq -r '.required_pull_request_reviews.required_approving_review_count // 0' <<< "$json")
if [ "$approvals" -lt 1 ]; then
  fail "no approving review is required on \`${BASE_BRANCH}\`. TWO-22 asks for no self-merge; an author who needs zero approvals merges their own pull request. Set required_approving_review_count to at least 1 — an author cannot approve their own PR, which is what makes that rule the no-self-merge rule."
else
  pass "an approving review is required (${approvals}) — the author cannot merge alone"
fi

# 6. docs/ci.md says stale reviews are dismissed. If they are not, an approval
#    given to one diff carries over to whatever is pushed next, and box 6 of the
#    merge gate is signed against code nobody read.
jq -e '.required_pull_request_reviews.dismiss_stale_reviews == true' >/dev/null 2>&1 <<< "$json" \
  && pass "stale reviews are dismissed on a new push" \
  || fail "stale reviews are not dismissed. An approval survives a force-push, so a signed-off pull request can merge code no reviewer saw. docs/ci.md states this rule is on."

jq -e '.required_pull_request_reviews.require_last_push_approval == true' >/dev/null 2>&1 <<< "$json" \
  || warn "\`require_last_push_approval\` is off: with stale-review dismissal on this is mostly covered, but an approver pushing their own commit after approving is still a self-merge path."

# 7. Protection that exempts admins is protection the people most likely to be in
#    a hurry do not have.
jq -e '.enforce_admins.enabled == true' >/dev/null 2>&1 <<< "$json" \
  && pass "protection applies to admins too" \
  || fail "\`enforce_admins\` is off. Administrators bypass every required check and the review requirement, which means the gate does not apply to the people most able to merge in a hurry. Red stops nobody with the box ticked."

# 8. A force-push or a delete rewrites the history the checks ran against.
jq -e '.allow_force_pushes.enabled == false' >/dev/null 2>&1 <<< "$json" \
  && pass "force pushes to \`${BASE_BRANCH}\` are blocked" \
  || fail "force pushes to \`${BASE_BRANCH}\` are allowed. Anything can be rewritten onto the branch without passing a single check."

jq -e '.allow_deletions.enabled == false' >/dev/null 2>&1 <<< "$json" \
  && pass "deleting \`${BASE_BRANCH}\` is blocked" \
  || fail "Deleting \`${BASE_BRANCH}\` is allowed. The branch the whole rule is attached to can be removed, and with it every requirement above."

if [ "$rc" -ne 0 ]; then
  printf '\n\033[31mThe pipeline may be red on the right things and still not gate. Fix the protection rule before signing off TWO-22.\033[0m\n' >&2
  exit 1
fi

log "Branch protection verified: the checks the pipeline runs are the checks GitHub enforces."
