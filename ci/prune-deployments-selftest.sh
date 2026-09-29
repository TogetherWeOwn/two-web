#!/usr/bin/env bash
#
# Tests for the deployment-records prune (TOG-9273).
#
# ci/prune-deployments.sh keeps the newest KEEP GitHub Deployment records per
# environment and deletes the rest. The dangerous shapes are the quiet ones: a
# prune that reports done while deleting nothing (TOG-913 theater), a prune
# that deletes the wrong end of the list, and a prune that reads an API error
# as an empty environment and then deletes the newest record for being the
# oldest (TWO-96, applied to deletions). A checker that always passes and a
# checker that works look identical from the outside, so every case here pins
# the exit code and the reason.
#
# No network, no gh, no credential: a stub `gh` on PATH answers from canned
# fixtures, exactly like ci/verify-protection-selftest.sh's stub. Each case
# gets a fresh fixture directory holding the deployments list, so state never
# leaks between cases.
#
# What is pinned:
#
#   keeps-newest        5 records, keep 3   -> deletes ids 1,2; keeps 3,4,5
#   deletes-oldest-first --apply order      -> id 1 deleted before id 2
#   keeps-short-list    fewer than KEEP     -> deletes nothing, exit 0
#   dry-run             default, no --apply -> deletes nothing, says so
#   active-retired      422 on delete       -> one `inactive` POST, then delete
#   active-stuck        retire POST fails   -> exit 1, id named
#   error-not-empty     error JSON body     -> exit 2, deletes nothing
#   keep-zero           --keep 0            -> exit 2, no API call
#   no-repo             no repo resolvable  -> exit 2, no API call
#   workflow-scheduled  prune workflow exists, scheduled, runs --apply
#
# Usage: ./ci/prune-deployments-selftest.sh

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPT="${REPO_ROOT}/ci/prune-deployments.sh"
WORKFLOW="${REPO_ROOT}/.github/workflows/deploy-records-prune.yml"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/prune-deployments-selftest.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

rc=0
n=0

fail() { printf '\033[31mFAIL: %s\033[0m\n' "$*" >&2; rc=1; }
pass() { printf '\033[32mPASS: %s\033[0m\n' "$*"; }

# --- the stub `gh` -----------------------------------------------------------
#
# Answers `auth status`, `api GET .../deployments?...` (list), `api -X DELETE
# .../deployments/N`, and `api -X POST .../deployments/N/statuses` from per-case
# fixture files. Append-only delete log; retiring flag per id. Error bodies go
# to stdout with a non-zero exit, exactly as the real gh does (TWO-96).
STUB="$WORK/stub"
mkdir -p "$STUB"
cat > "$STUB/gh" <<'STUB_EOF'
#!/usr/bin/env bash
set -uo pipefail
FIX="${GH_STUB_DIR:?}"
case "${1:-}" in
  auth) exit 0 ;;
  api)
    shift
    method="GET"; endpoint=""
    while [ "$#" -gt 0 ]; do
      case "$1" in
        -X) method="$2"; shift 2 ;;
        -f) shift 2 ;;
        *) endpoint="$1"; shift ;;
      esac
    done
    case "$method $endpoint" in
      "GET repos/"*"/deployments?"*)
        if [ -f "$FIX/error-body.json" ]; then
          cat "$FIX/error-body.json"
          printf 'gh: list failed (HTTP %s)\n' "$(cat "$FIX/error-status" 2>/dev/null || echo 422)" >&2
          exit 1
        fi
        env_name="$(sed -n 's/.*environment=\([^&]*\).*/\1/p' <<< "$endpoint")"
        page="$(sed -n 's/.*page=\([^&]*\).*/\1/p' <<< "$endpoint")"
        jq --arg env "$env_name" --argjson page "${page:-1}" \
          '[.[] | select(.environment == $env)] as $all
           | $all[($page - 1) * 100:$page * 100]' "$FIX/deployments.json"
        ;;
      "DELETE repos/"*"/deployments/"*)
        id="${endpoint##*/}"
        if grep -qxF "$id" "$FIX/refuse-delete.txt" 2>/dev/null \
          && ! grep -qxF "$id" "$FIX/retired.txt" 2>/dev/null; then
          printf '{"message":"Validation Failed","errors":[{"code":"custom","message":"Only inactive deployments can be deleted"}]}\n'
          printf 'gh: HTTP 422\n' >&2
          exit 1
        fi
        # Log only the deletes that actually happened: a refused delete is
        # not a deletion, and logging it would let a case pass that deleted
        # nothing.
        printf '%s\n' "$id" >> "$FIX/deleted.log"
        ;;
      "POST repos/"*"/deployments/"*"/statuses")
        id="$(sed -E 's#.*/deployments/([0-9]+)/statuses#\1#' <<< "$endpoint")"
        if grep -qxF "$id" "$FIX/refuse-retire.txt" 2>/dev/null; then
          printf '{"message":"Validation Failed"}\n'
          printf 'gh: HTTP 422\n' >&2
          exit 1
        fi
        printf '%s\n' "$id" >> "$FIX/retired.txt"
        printf '{"id":9,"state":"inactive"}\n'
        ;;
      *) printf 'stub gh: unexpected call: %s %s\n' "$method" "$endpoint" >&2; exit 3 ;;
    esac
    ;;
  *) printf 'stub gh: unexpected: %s\n' "${1:-}" >&2; exit 3 ;;
esac
STUB_EOF
chmod +x "$STUB/gh"

# fixture <slug> — fresh dir with 5 staging deployments (ids 1..5, created in
# order), empty deleted/retired logs. Prints the dir.
fixture() {
  local dir="$WORK/$1"
  mkdir -p "$dir"
  jq -n '[range(1; 6) | {
    id: .,
    sha: "abc1234",
    ref: "main",
    environment: "staging",
    created_at: "2026-09-2\(.)T10:00:00Z"
  }]' > "$dir/deployments.json"
  : > "$dir/deleted.log"
  : > "$dir/retired.txt"
  rm -f "$dir/error-body.json" "$dir/refuse-delete.txt" "$dir/refuse-retire.txt"
  printf '%s' "$dir"
}

# run_prune <fixture-dir> [args...] — OUT holds output, STATUS the exit.
run_prune() {
  local dir="$1"; shift
  OUT="$(cd "$REPO_ROOT" && PATH="$STUB:$PATH" GH_STUB_DIR="$dir" GH_REPO="TogetherWeOwn/two-web" bash "$SCRIPT" "$@" 2>&1)"
  STATUS=$?
}

printf '\n\033[1m==> The prune keeps the newest and deletes the rest\033[0m\n'

n=$((n + 1))
dir="$(fixture keeps-newest)"
run_prune "$dir" --keep 3 --env staging --apply
if [ "$STATUS" -ne 0 ]; then
  fail "keeps-newest: exit ${STATUS}, wanted 0"
  printf '%s\n' "$OUT" | sed 's/^/        /'
elif [ "$(sort -n "$dir/deleted.log" | tr '\n' ' ')" != "1 2 " ]; then
  fail "keeps-newest: deleted [$(tr '\n' ' ' < "$dir/deleted.log")], wanted [1 2]"
else
  pass keeps-newest
fi

n=$((n + 1))
dir="$(fixture deletes-oldest-first)"
run_prune "$dir" --keep 3 --env staging --apply
if [ "$STATUS" -ne 0 ]; then
  fail "deletes-oldest-first: exit ${STATUS}, wanted 0"
  printf '%s\n' "$OUT" | sed 's/^/        /'
elif [ "$(tr '\n' ' ' < "$dir/deleted.log")" != "1 2 " ]; then
  fail "deletes-oldest-first: delete order [$(tr '\n' ' ' < "$dir/deleted.log")], wanted [1 2] — newest-first deletion leaves the newest exposed to a mid-run failure"
else
  pass deletes-oldest-first
fi

printf '\n\033[1m==> Short lists and dry runs delete nothing\033[0m\n'

n=$((n + 1))
dir="$(fixture keeps-short-list)"
run_prune "$dir" --keep 30 --env staging --apply
if [ "$STATUS" -ne 0 ]; then
  fail "keeps-short-list: exit ${STATUS}, wanted 0"
  printf '%s\n' "$OUT" | sed 's/^/        /'
elif [ -s "$dir/deleted.log" ]; then
  fail "keeps-short-list: deleted [$(tr '\n' ' ' < "$dir/deleted.log")] with only 5 records under keep 30"
else
  pass keeps-short-list
fi

n=$((n + 1))
dir="$(fixture dry-run)"
run_prune "$dir" --keep 3 --env staging
if [ "$STATUS" -ne 0 ]; then
  fail "dry-run: exit ${STATUS}, wanted 0"
  printf '%s\n' "$OUT" | sed 's/^/        /'
elif [ -s "$dir/deleted.log" ]; then
  fail "dry-run: deleted without --apply"
  printf '%s\n' "$OUT" | sed 's/^/        /'
elif ! grep -qF 'dry run: nothing was deleted' <<< "$OUT"; then
  fail "dry-run: never said it was a dry run — a no-delete run that reads like an applied one is TOG-913 theater"
else
  pass dry-run
fi

printf '\n\033[1m==> Active records are retired, stuck ones are named\033[0m\n'

n=$((n + 1))
dir="$(fixture active-retired)"
printf '1\n2\n' > "$dir/refuse-delete.txt"
run_prune "$dir" --keep 3 --env staging --apply
if [ "$STATUS" -ne 0 ]; then
  fail "active-retired: exit ${STATUS}, wanted 0"
  printf '%s\n' "$OUT" | sed 's/^/        /'
elif [ "$(sort -n "$dir/deleted.log" | tr '\n' ' ')" != "1 2 " ] \
  || [ "$(sort -n "$dir/retired.txt" | tr '\n' ' ')" != "1 2 " ]; then
  fail "active-retired: expected retire-then-delete of 1,2; deleted [$(tr '\n' ' ' < "$dir/deleted.log")], retired [$(tr '\n' ' ' < "$dir/retired.txt")]"
else
  pass active-retired
fi

n=$((n + 1))
dir="$(fixture active-stuck)"
printf '1\n' > "$dir/refuse-delete.txt"
printf '1\n' > "$dir/refuse-retire.txt"
run_prune "$dir" --keep 3 --env staging --apply
if [ "$STATUS" -ne 1 ]; then
  fail "active-stuck: exit ${STATUS}, wanted 1 — a record that could not be deleted must fail the run, not pass over it"
  printf '%s\n' "$OUT" | sed 's/^/        /'
elif ! grep -qF 'staging:1' <<< "$OUT"; then
  fail "active-stuck: failed, but never named the stuck record — an unnamed failure is unfixable"
  printf '%s\n' "$OUT" | sed 's/^/        /'
elif [ "$(tr '\n' ' ' < "$dir/deleted.log")" != "2 " ]; then
  fail "active-stuck: one stuck record blocked the rest; deleted [$(tr '\n' ' ' < "$dir/deleted.log")], wanted [2]"
  printf '%s\n' "$OUT" | sed 's/^/        /'
else
  pass active-stuck
fi

printf '\n\033[1m==> Error bodies are never read as empty environments\033[0m\n'

# TWO-96, applied to deletions: the list call fails with a JSON error body.
# Reading that body as "no deployments" passes while asserting nothing; worse,
# a non-array body reaching the split would land outside KEEP. Stop at exit 2
# with nothing deleted.
n=$((n + 1))
dir="$(fixture error-not-empty)"
printf '{"message":"Resource not accessible by personal access token"}' > "$dir/error-body.json"
printf '403' > "$dir/error-status"
run_prune "$dir" --keep 3 --env staging --apply
if [ "$STATUS" -ne 2 ] && [ "$STATUS" -ne 1 ]; then
  fail "error-not-empty: exit ${STATUS}, wanted non-zero — an unreadable list must not read as prunable"
  printf '%s\n' "$OUT" | sed 's/^/        /'
elif [ -s "$dir/deleted.log" ]; then
  fail "error-not-empty: deleted [$(tr '\n' ' ' < "$dir/deleted.log")] off a failed list"
else
  pass error-not-empty
fi

printf '\n\033[1m==> Misconfiguration refuses before any API call\033[0m\n'

n=$((n + 1))
dir="$(fixture keep-zero)"
run_prune "$dir" --keep 0 --env staging --apply
if [ "$STATUS" -ne 2 ]; then
  fail "keep-zero: exit ${STATUS}, wanted 2 — keep 0 deletes the only record the KPI reads"
  printf '%s\n' "$OUT" | sed 's/^/        /'
elif grep -q "unexpected call" <<< "$OUT"; then
  fail "keep-zero: called the API before refusing"
else
  pass keep-zero
fi

n=$((n + 1))
dir="$(fixture no-repo)"
OUT="$(cd "$REPO_ROOT" && env -i PATH="$STUB:$PATH" GH_STUB_DIR="$dir" bash -c 'unset GH_REPO GITHUB_REPOSITORY; git() { return 1; }; export -f git; bash "'"$SCRIPT"'" --keep 3 --env staging --apply' 2>&1)"
STATUS=$?
if [ "$STATUS" -ne 2 ]; then
  fail "no-repo: exit ${STATUS}, wanted 2 — a delete script that guesses its repo is a catastrophe with a usage string"
  printf '%s\n' "$OUT" | sed 's/^/        /'
else
  pass no-repo
fi

printf '\n\033[1m==> The schedule is real, not theater\033[0m\n'

# The prune only matters while something runs it. A script with no schedule is
# a promise, and a schedule without --apply is TOG-913 theater: green without
# doing anything. So this asserts the workflow's shape — scheduled, applying,
# attesting its runner — the same way deploy-target-selftest pins that
# deploy.yml calls its guard.
n=$((n + 1))
if [ ! -r "$WORKFLOW" ]; then
  fail "workflow-scheduled: ${WORKFLOW} is missing or unreadable"
  rc=1
else
  problems=""
  grep -qE '^\s+schedule:' "$WORKFLOW" || problems="${problems} no schedule trigger;"
  if grep -qE '^[[:space:]]*run:[[:space:]]*\./ci/prune-deployments\.sh[[:space:]]' "$WORKFLOW" \
    && grep -qF -- '--apply' "$WORKFLOW"; then
    : # runs the prune, and applies it — a scheduled dry run would be theater
  else
    problems="${problems} never runs ci/prune-deployments.sh with --apply (a scheduled dry run is green without doing anything);"
  fi
  grep -qE '^[[:space:]]*run:[[:space:]]*\./ci/attest-runner\.sh[[:space:]]*$' "$WORKFLOW" \
    || problems="${problems} job does not attest its runner (TOG-2847);"
  if grep -qE '^\s+pull_request:?' "$WORKFLOW"; then
    problems="${problems} triggers on pull_request (a non-PR job id would hang the merge gate as an absent required check);"
  fi
  if [ -n "$problems" ]; then
    fail "workflow-scheduled:${problems}"
  else
    pass workflow-scheduled
  fi
fi

printf '\n'
if [ "$rc" -eq 0 ]; then
  printf '\033[32m%s cases passed.\033[0m The prune keeps the newest, deletes the oldest, and refuses to guess.\n' "$n"
else
  printf '\033[31mSome cases failed (of %s).\033[0m\n' "$n"
fi
exit "$rc"
