#!/usr/bin/env bash
#
# Tests for the tester: the `--run` preconditions, and the `--cleanup` that is the
# only way out of one of them.
#
# `verify-pipeline.sh --lint` has ci/verify-lint-selftest.sh watching it. The
# `--run` preconditions had nothing, because they need `gh`, a remote and a push —
# and they are the half-second checks standing between an operator and forty
# minutes of a run that will answer the wrong question. A precondition that has
# quietly stopped firing looks exactly like one that has nothing to complain about.
#
# So: build a throwaway repository whose `origin` *reads* as GitHub while its bytes
# go to a bare repo next door, put a stub `gh` on PATH, and assert each guard fires
# for its own reason and stays quiet otherwise. No network and no real repository:
# the `insteadOf` at $FAKE_ORIGIN diverts every transfer and the stub `gh` answers
# every API call, so nothing here reaches github.com whatever the URL says.
#
# The stub answers `gh pr list --json ... --jq ...` by running the real jq over a
# fixture file, so the filter tested here is the filter verify-pipeline.sh ships.
#
# What is pinned, in the order verify-pipeline.sh checks it:
#
#   shared-tree   the repo is a shared workspace checkout, not a scratch clone (TWO-112)
#   stale-scratch the scratch clone belongs to a different run                 (TWO-112)
#   no-run-id     outside Paperclip the scratch-clone guard must NOT fire      (TWO-112)
#   gh-unauth     `gh` is installed but not logged in
#   dirty         uncommitted changes in the working tree
#   local-origin  `origin` is a filesystem clone, not GitHub
#   checks-api    the credential cannot read the Checks API           (TOG-328)
#   live-pr       another run's pull request is open        (TWO-103)
#   live-branch   another run's branch is on the remote     (TWO-103)
#   clear         a negative control: none of the above fires on a normal repo
#
# The live-run guard reads two probes and refuses on either. What happens when one
# of them cannot be read is a guard of its own, and not a visible one (TWO-109):
#
#   pr-probe-unreadable       the API errors: no PASS claimed on a probe never read
#   pr-probe-unreadable-live  ...and the branch half still refuses on its own,
#                             which is the entire reason failing open is allowed
#   no-remote                 neither probe answered: refuse, do not guess
#
# And the recovery that guard names in its own error message, because a refusal
# pointing at a command that cannot clear the refusal is worse than no pointer:
#
#   cleanup-unlisted  a ci-verify/* branch outside CASES is cleared, and --run
#                     then starts — the TWO-109 reproduction, end to end
#   cleanup-listed    the enumerated half still closes the PR and takes the branch
#   cleanup-quiet     a no-op says so, instead of reading like a nine-branch sweep
#
# One precondition is deliberately not pinned: `command -v gh`. Faking a missing
# `gh` means a PATH with no `gh` on it, and the harness needs `git` and `jq` from
# that same PATH. It is also the one guard that cannot drift into silence — if it
# stops firing the very next line dies on a command that does not exist. Every
# other precondition can fail open without anyone noticing, which is why they are
# all here. Add the case with the guard if you ever add another.
#
# Usage: ./ci/verify-run-preconditions-selftest.sh

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/run-precond-selftest.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

# What `git config --get remote.origin.url` reports. Never contacted: the
# `insteadOf` set alongside it sends every actual transfer to the bare repo in the
# fixture, and `gh` is a stub, so no byte leaves the machine.
#
# It says TogetherWeOwn because that is what a real scratch clone's origin says,
# and the assertion strings below quote the slug back. This used to name the old
# TWO-Gaming org, described as unreachable — it was not: GitHub carries
# repos/<oldorg>/<repo> on a transfer redirect, so that URL resolved to this very
# repository. Isolation never came from the URL. Do not re-point this at a name
# picked for being fake; point it at the repo being simulated and keep the
# `insteadOf` on the line below, which is the thing actually holding.
FAKE_ORIGIN="https://github.com/TogetherWeOwn/two-web.git"

# The run id every fixture is stamped with, and the one run_case exports unless a
# case says otherwise. Real ones are UUIDs; the only thing verify-pipeline.sh does
# with it is compare it to `paperclip.runScratch`, so any stable string will do.
# Fixed rather than generated so a failure message is the same on every machine.
FIXTURE_RUN_ID="selftest-run-0000"

pass() { printf '\033[32mPASS\033[0m  %s\n' "$*"; }
fail() { printf '\033[31mFAIL\033[0m  %s\n' "$*" >&2; }

rc=0
n=0

# A repository the preconditions will get all the way through, minus whatever the
# case under test breaks. The bare remote starts empty on purpose: with no
# `origin/main` to fetch, a run that gets past the guards dies immediately after
# them instead of opening eight pull requests against the fixture.
fixture() {
  local slug="$1" dir="$WORK/$1"
  rm -rf "$dir"
  mkdir -p "$dir/bin" "$dir/repo/.github/workflows" "$dir/repo/docs" "$dir/repo/ci"

  git init -q --bare "$dir/remote.git"

  cp "$REPO_ROOT"/.github/workflows/*.yml "$dir/repo/.github/workflows/"
  cp "$REPO_ROOT/docs/ci.md" "$dir/repo/docs/"
  # The whole of ci/, not just verify-pipeline.sh. Every case here reaches its
  # precondition through the same static checks a real `--run` runs first, and
  # those read whatever else the repo ships in ci/ — ci/lighthouserc.cjs since
  # 4a564d3, the next one whenever a check learns about a new file. Naming the
  # files one at a time means that check turns every case red at once, each of
  # them reporting "refused, but not for the stated reason", which is a lie about
  # the guard rather than a complaint about this line.
  cp -R "$REPO_ROOT/ci/." "$dir/repo/ci/"

  (
    cd "$dir/repo" || exit 1
    git init -q -b main .
    git config user.email 'ci@example.invalid'
    git config user.name 'ci selftest'
    git config commit.gpgsign false
    git remote add origin "$FAKE_ORIGIN"
    git config "url.${dir}/remote.git.insteadOf" "$FAKE_ORIGIN"
    # Every fixture passes the scratch-clone guard by default (TWO-112), the same
    # way a real run does: it is stamped with the run id that owns it. The cases
    # that test that guard unstamp or re-stamp their own copy.
    git config paperclip.runScratch "$FIXTURE_RUN_ID"
    git add -A
    git commit -q -m 'fixture'
  ) || return 1

  cat > "$dir/bin/gh" <<'SH'
#!/usr/bin/env bash
# Stub `gh`. Answers only what the --run preconditions ask.
set -uo pipefail
case "${1:-}" in
  # Each fixture owns a file holding the status these should exit with, so a case
  # that breaks one of them cannot leak into the next case.
  auth) exit "$(cat "${GH_STUB_AUTH_STATUS:?}")" ;;
  api)  exit "$(cat "${GH_STUB_API_STATUS:?}")" ;;
  pr)
    case "${2:-}" in
      list)
        # An API that will not answer exits non-zero with no output, which is what
        # an empty list also looks like. The caller has to tell those apart.
        status="$(cat "${GH_STUB_PR_STATUS:?}")"
        [ "$status" = 0 ] || exit "$status"
        filter='.'
        while [ "$#" -gt 0 ]; do
          [ "$1" = "--jq" ] && filter="${2:-.}"
          shift
        done
        # The real gh applies this with its built-in jq. Applying it with the real
        # one means the filter in verify-pipeline.sh is what this test exercises.
        jq -r "$filter" < "${GH_STUB_PR_JSON:?}"
        ;;
      close)
        # `--delete-branch` deletes the head branch on the server, so this has to as
        # well: otherwise cleanup()'s sweep would be measured against a remote that
        # never changes, and closing a pull request would look like it left a branch
        # behind. Drop it from the fixture list too — a closed PR is not in
        # `--state open` any more, and the sweep runs after this.
        head=$(jq -r --arg n "${3:-}" \
          '.[] | select((.number|tostring) == $n) | .headRefName' < "${GH_STUB_PR_JSON:?}")
        [ -z "$head" ] || git --git-dir="${GH_STUB_REMOTE:?}" update-ref -d "refs/heads/$head" 2>/dev/null
        jq --arg n "${3:-}" 'map(select((.number|tostring) != $n))' \
          < "$GH_STUB_PR_JSON" > "$GH_STUB_PR_JSON.tmp" && mv "$GH_STUB_PR_JSON.tmp" "$GH_STUB_PR_JSON"
        ;;
    esac
    ;;
esac
SH
  chmod +x "$dir/bin/gh"
  printf '[]\n' > "$dir/prs.json"
  # Healthy by default. A case that wants a broken `gh` overwrites its own copy.
  printf '0\n' > "$dir/auth-status"
  printf '0\n' > "$dir/api-status"
  printf '0\n' > "$dir/pr-status"

  echo "$dir"
}

# The run id run_case exports. A case sets this to something else to play a
# different run, or to the empty string to play "not inside Paperclip at all",
# which has to be a real unset and not an empty variable — verify-pipeline.sh
# branches on whether PAPERCLIP_RUN_ID is set to a non-empty value.
RUN_CASE_RUN_ID="$FIXTURE_RUN_ID"

# in_fixture <dir> <mode> -> combined output in $RUN_OUT, status in $RUN_STATUS.
# Not a command substitution at the call site: that would run in a subshell and
# lose the status.
#
# `env -u` matters here: this harness itself usually runs inside a Paperclip run,
# so PAPERCLIP_RUN_ID is already in the environment and would leak a real run id
# into every fixture. Each case states its own.
in_fixture() {
  local dir="$1" mode="$2"
  local -a runenv=()
  [ -n "$RUN_CASE_RUN_ID" ] && runenv=("PAPERCLIP_RUN_ID=$RUN_CASE_RUN_ID")
  RUN_OUT="$(cd "$dir/repo" && env -u PAPERCLIP_RUN_ID "${runenv[@]}" \
    PATH="$dir/bin:$PATH" GH_STUB_PR_JSON="$dir/prs.json" \
    GH_STUB_AUTH_STATUS="$dir/auth-status" GH_STUB_API_STATUS="$dir/api-status" \
    GH_STUB_PR_STATUS="$dir/pr-status" GH_STUB_REMOTE="$dir/remote.git" \
    ./ci/verify-pipeline.sh "$mode" 2>&1)"
  RUN_STATUS=$?
}

run_case()     { in_fixture "$1" --run; }
cleanup_case() { in_fixture "$1" --cleanup; }

# The refs the `--run` guard blocks on, which is exactly the set `--cleanup` has to
# be able to clear. Read straight off the bare remote, not through the stub.
leftover() { git ls-remote --heads "$1/remote.git" 'refs/heads/ci-verify/*' 2>/dev/null | awk '{print $2}'; }

expect_refused() {
  local slug="$1" expected="$2" dir="$3" out
  n=$((n + 1))
  run_case "$dir"
  out="$RUN_OUT"
  if [ "$RUN_STATUS" -eq 0 ]; then
    fail "$slug: the run was allowed to start. It should have been refused."
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
    return
  fi
  if ! grep -qF -- "$expected" <<< "$out"; then
    fail "$slug: refused, but not for the stated reason. Wanted: ${expected}"
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
    return
  fi
  # "Nothing has been pushed" has to be true, not just written down. The whole
  # point of a precondition is that it fires before the damage.
  if [ -n "$(git ls-remote --heads "$dir/remote.git" 'refs/heads/ci-verify/*' 2>/dev/null | grep -vF "$PRE_EXISTING")" ]; then
    fail "$slug: refused, but pushed a ci-verify branch on the way out"
    rc=1
    return
  fi
  pass "$slug"
}

# Preflight, not a case. `--run` runs the static checks on the gate before it
# reaches any precondition, so a fixture the static checks reject fails every
# case below with "refused, but not for the stated reason" — an accusation
# against each working guard in turn, and nothing pointing at the fixture. Ask
# once, up front, and say what is actually wrong.
dir="$(fixture preflight)" || { fail 'preflight: fixture failed'; exit 1; }
run_case "$dir"
if grep -qF 'the gate is misconfigured' <<< "$RUN_OUT"; then
  fail 'the fixture does not satisfy the static checks, so no guard below is
        reachable and none of the results would mean anything. Either the gate
        really is broken — run ./ci/verify-pipeline.sh --lint to find out — or a
        static check has started reading a file fixture() does not copy into the
        throwaway repo. What it could not find:'
  printf '%s\n' "$RUN_OUT" | grep -F 'FAIL:' | grep -vF 'the gate is misconfigured' | sed 's/^/        /'
  exit 1
fi

printf '\n\033[1m==> The run does not have a checkout of its own (TWO-112)\033[0m\n'

# The shared workspace checkout. `--run` checks out and commits on eight branches
# in the repository it is standing in; in an agent workspace that repository is
# shared with every other run of that agent, and one heartbeat can still be
# finishing while the next has started. Committed work it lands on is at least in
# a reflog. Uncommitted work is not anywhere.
#
# This guard is checked before the dirty-tree one on purpose, and the ordering is
# worth pinning: in a shared checkout that is mid-edit, "working tree is dirty —
# commit or stash first" would have the operator commit another run's unfinished
# work onto a ci-verify branch and push it.
dir="$(fixture shared-tree)" || { fail 'shared-tree: fixture failed'; rc=1; }
( cd "$dir/repo" && git config --unset paperclip.runScratch )
printf 'uncommitted\n' > "$dir/repo/scratch.txt"
PRE_EXISTING='no-such-ref'
expect_refused shared-tree 'this is a shared workspace checkout' "$dir"

# ... and it must be refused for *that* reason, not for the dirty tree it also has.
n=$((n + 1))
if grep -qF 'working tree is dirty' <<< "$RUN_OUT"; then
  fail 'shared-tree: refused for the dirty tree, so the scratch-clone guard is checked too late'
  rc=1
else
  pass 'shared-tree-first'
fi

# A scratch clone left behind by a run that has ended, or still owned by a run that
# has not. Either way it is not this run's tree, and its borrowed object store can
# be pruned out from under it — `scratch-clone.sh` uses `git clone --shared`.
dir="$(fixture stale-scratch)" || { fail 'stale-scratch: fixture failed'; rc=1; }
( cd "$dir/repo" && git config paperclip.runScratch 'selftest-run-9999' )
PRE_EXISTING='no-such-ref'
expect_refused stale-scratch 'belongs to run selftest-run-9999' "$dir"

printf '\n\033[1m==> Outside Paperclip, the scratch-clone guard must not fire\033[0m\n'

# On a laptop or a CI runner there is no run to own anything and the checkout is
# private by definition. If this guard fires there, the acceptance suite becomes
# unrunnable by a human — and it would fire for a reason that names an environment
# variable they have never heard of. Inverting the condition is a one-character
# mistake, so pin it.
n=$((n + 1))
dir="$(fixture no-run-id)" || { fail 'no-run-id: fixture failed'; rc=1; }
( cd "$dir/repo" && git config --unset paperclip.runScratch )
RUN_CASE_RUN_ID=''
run_case "$dir"
out="$RUN_OUT"
RUN_CASE_RUN_ID="$FIXTURE_RUN_ID"
if grep -qF 'shared workspace checkout' <<< "$out"; then
  fail 'no-run-id: the guard fired outside a Paperclip run'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
elif ! grep -qF 'no other verification run is live' <<< "$out"; then
  fail 'no-run-id: the run stopped before the guards it should have passed'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
else
  pass 'no-run-id'
fi

printf '\n\033[1m==> Another verification run is already live (TWO-103)\033[0m\n'

# An open pull request on a ci-verify branch. This is the live case: someone else
# is mid-run, and starting now would rewrite their branches and delete their PRs.
dir="$(fixture live-pr)" || { fail 'live-pr: fixture failed'; rc=1; }
cat > "$dir/prs.json" <<'JSON'
[
  {"number": 17, "headRefName": "ci-verify/pint"},
  {"number": 9,  "headRefName": "fix/dusk-signout-flake"}
]
JSON
PRE_EXISTING='no-such-ref'
expect_refused live-pr 'pull request #17 on ci-verify/pint' "$dir"

# No pull request, but a ci-verify branch is still on the remote — what a run that
# was killed part-way leaves behind. The next run's force-with-lease push collides
# with it exactly as hard, so a branch counts as much as a PR.
dir="$(fixture live-branch)" || { fail 'live-branch: fixture failed'; rc=1; }
(
  cd "$dir/repo" || exit 1
  git branch -q ci-verify/dusk
  git push -q origin ci-verify/dusk
) || { fail 'live-branch: could not seed the leftover branch'; rc=1; }
PRE_EXISTING='refs/heads/ci-verify/dusk'
expect_refused live-branch 'branch ci-verify/dusk on origin' "$dir"

printf '\n\033[1m==> An unrelated pull request is not another run\033[0m\n'

# The guard must not fire on ordinary open pull requests, or the acceptance suite
# becomes unrunnable on a repo with any work in flight — which is every repo.
# Getting past it is the assertion; the run then dies at the first fetch, because
# the fixture remote deliberately has no `main`.
n=$((n + 1))
dir="$(fixture clear)" || { fail 'clear: fixture failed'; rc=1; }
cat > "$dir/prs.json" <<'JSON'
[
  {"number": 15, "headRefName": "fix/dusk-signout-flake"},
  {"number": 12, "headRefName": "ci/verify-checks-api"}
]
JSON
run_case "$dir"
out="$RUN_OUT"
if grep -qF 'another verification run is already live' <<< "$out"; then
  fail 'clear: the guard fired on unrelated pull requests'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
elif ! grep -qF 'no other verification run is live' <<< "$out"; then
  fail 'clear: the guard neither passed nor failed — it did not run'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
else
  pass 'clear'
fi

printf '\n\033[1m==> origin is not GitHub\033[0m\n'

# A workspace clone or a local mirror. Results come from the GitHub Checks API,
# so a run here reads nothing back and calls it a dead pipeline. This is the guard
# that stopped one such run on 2026-08-20 before it pushed anything.
n=$((n + 1))
dir="$(fixture local-origin)" || { fail 'local-origin: fixture failed'; rc=1; }
( cd "$dir/repo" && git remote set-url origin "$dir/remote.git" )
run_case "$dir"
out="$RUN_OUT"
if [ "$RUN_STATUS" -eq 0 ] || ! grep -qF 'origin is not a GitHub repository' <<< "$out"; then
  fail 'local-origin: a filesystem origin was allowed to start a run'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
else
  pass 'local-origin'
fi

printf '\n\033[1m==> The working tree is dirty\033[0m\n'

# open_pr() commits whatever is in the tree onto the case branch, so an uncommitted
# edit rides along into all eight pull requests and every result is about the wrong
# code.
n=$((n + 1))
dir="$(fixture dirty)" || { fail 'dirty: fixture failed'; rc=1; }
printf 'uncommitted\n' > "$dir/repo/scratch.txt"
run_case "$dir"
out="$RUN_OUT"
if [ "$RUN_STATUS" -eq 0 ] || ! grep -qF 'working tree is dirty' <<< "$out"; then
  fail 'dirty: an uncommitted change was allowed to start a run'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
else
  pass 'dirty'
fi

printf '\n\033[1m==> git identity is not set (TOG-466)\033[0m\n'

# open_pr() is always called as `branch=$(open_pr ...)`, and bash starts a
# command-substitution subshell with errexit OFF regardless of the outer shell's
# `-e` — only the subshell's last command decides whether the assignment fails. A
# `git commit` with no identity used to fall through to `git push` and open a pull
# request on an unchanged branch, which is exactly the empty ci-verify/* branches
# this issue is about. This is the case for the guard that stops it before any of
# that runs.
#
# fixture() sets a local identity so every other case here can commit; this one
# removes it. The harness's *own* git identity is real (it is what let TOG-466 be
# found and reproduced), so unsetting only the fixture's local config would still
# resolve through this machine's global config and prove nothing — GIT_CONFIG_GLOBAL
# and GIT_CONFIG_SYSTEM are pointed at /dev/null too, so the fixture sees exactly
# what an operator with no identity configured anywhere would.
n=$((n + 1))
dir="$(fixture no-identity)" || { fail 'no-identity: fixture failed'; rc=1; }
( cd "$dir/repo" && git config --unset user.email && git config --unset user.name )
RUN_OUT="$(cd "$dir/repo" && env -u PAPERCLIP_RUN_ID "PAPERCLIP_RUN_ID=$RUN_CASE_RUN_ID" \
  GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null \
  PATH="$dir/bin:$PATH" GH_STUB_PR_JSON="$dir/prs.json" \
  GH_STUB_AUTH_STATUS="$dir/auth-status" GH_STUB_API_STATUS="$dir/api-status" \
  GH_STUB_PR_STATUS="$dir/pr-status" GH_STUB_REMOTE="$dir/remote.git" \
  ./ci/verify-pipeline.sh --run 2>&1)"
RUN_STATUS=$?
out="$RUN_OUT"
if [ "$RUN_STATUS" -eq 0 ] || ! grep -qF 'git identity is not set' <<< "$out"; then
  fail 'no-identity: a run with no git identity anywhere was allowed to start'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
elif [ -n "$(git ls-remote --heads "$dir/remote.git" 'refs/heads/ci-verify/*' 2>/dev/null)" ]; then
  fail 'no-identity: refused, but pushed a ci-verify branch on the way out'
  rc=1
else
  pass 'no-identity'
fi

printf '\n\033[1m==> gh is not authenticated\033[0m\n'

# The first precondition, and the one most likely to be true on a fresh machine:
# a `gh` that is installed but has never been logged in. Everything after it —
# opening the pull requests, reading the results back — is a `gh` call, so an
# unauthenticated credential fails eight times over, forty minutes late.
dir="$(fixture gh-unauth)" || { fail 'gh-unauth: fixture failed'; rc=1; }
printf '1\n' > "$dir/auth-status"
PRE_EXISTING='no-such-ref'
expect_refused gh-unauth 'gh is not authenticated' "$dir"

printf '\n\033[1m==> The Checks API cannot be read\033[0m\n'

# A token with push access but without `Checks: read`. This one is the reason the
# check exists rather than a nicety: the run pushes fine, opens all ten pull
# requests, and then reads nothing back — which is byte-for-byte what a pipeline
# that never ran looks like, and the two diagnoses point in opposite directions
# (TWO-87). Authenticated `gh`, clean tree, GitHub origin: only the API read is
# broken, so nothing but this guard can catch it.
#
# This was `actions-api` until TOG-328 moved every result read off the Actions API
# — `Actions: read` also grants workflow log download, which TOG-247 refused
# permanently. The guard is the same guard; only the endpoint and the permission it
# names have changed. The case is renamed with it so a failure here still points at
# the thing that broke.
dir="$(fixture checks-api)" || { fail 'checks-api: fixture failed'; rc=1; }
printf '1\n' > "$dir/api-status"
PRE_EXISTING='no-such-ref'
expect_refused checks-api 'cannot read the Checks API on TogetherWeOwn/two-web' "$dir"

printf '\n\033[1m==> One probe of the two could not be read\033[0m\n'

# `gh pr list --json` prints nothing and exits non-zero when the API will not
# answer — identical, from here, to an empty list. The guard fails open on that,
# which is the right call, but only because the `ls-remote` half looks for the same
# state over a different transport. Two things have to hold for that to be true and
# neither is visible from a green run: the branch half must still refuse on its
# own, and the summary must stop claiming a clean read of a probe that errored
# (TWO-109).
n=$((n + 1))
dir="$(fixture pr-probe-unreadable)" || { fail 'pr-probe-unreadable: fixture failed'; rc=1; }
printf '1\n' > "$dir/pr-status"
run_case "$dir"
out="$RUN_OUT"
if grep -qF 'no other verification run is live' <<< "$out"; then
  fail 'pr-probe-unreadable: claimed no run is live, on a probe that errored'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
elif ! grep -qF 'could not read the open pull requests' <<< "$out"; then
  fail 'pr-probe-unreadable: the unreadable probe was not named'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
else
  pass 'pr-probe-unreadable'
fi

# ...and the half that did answer still refuses on its own. This is the entire
# justification for failing open above: open_pr() pushes the branch before it opens
# the pull request, so a live run is visible on the remote whether or not the API is.
dir="$(fixture pr-probe-unreadable-live)" || { fail 'pr-probe-unreadable-live: fixture failed'; rc=1; }
printf '1\n' > "$dir/pr-status"
(
  cd "$dir/repo" || exit 1
  git branch -q ci-verify/lcp
  git push -q origin ci-verify/lcp
) || { fail 'pr-probe-unreadable-live: could not seed the leftover branch'; rc=1; }
PRE_EXISTING='refs/heads/ci-verify/lcp'
expect_refused pr-probe-unreadable-live 'branch ci-verify/lcp on origin' "$dir"

printf '\n\033[1m==> The remote cannot be listed at all\033[0m\n'

# Neither probe answered. There is nothing left to fail open onto, and `ls-remote`
# is the transport every push in this script uses, so refuse rather than start a run
# that cannot push and cannot know what it would be colliding with.
n=$((n + 1))
dir="$(fixture no-remote)" || { fail 'no-remote: fixture failed'; rc=1; }
rm -rf "$dir/remote.git"
run_case "$dir"
out="$RUN_OUT"
if [ "$RUN_STATUS" -eq 0 ] || ! grep -qF 'cannot read the branches on origin' <<< "$out"; then
  fail 'no-remote: an unreadable remote was not refused for its own reason'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
elif grep -qF 'no other verification run is live' <<< "$out"; then
  fail 'no-remote: claimed no run is live with both probes dark'
  rc=1
else
  pass 'no-remote'
fi

printf '\n\033[1m==> --cleanup clears what the guard blocks on (TWO-109)\033[0m\n'

# The guard refuses on *any* ci-verify/* ref and names `--cleanup` as the way out.
# cleanup() used to delete nine fixed names, so a ci-verify/* branch outside CASES —
# a renamed case, an older checkout, one made by hand — was blocked on forever and
# cleaned never, with `--cleanup` exiting 0 and printing nothing to say so. This is
# the reported reproduction end to end: leftover branch, cleanup, then a --run that
# has to get past the guard it was stuck on.
n=$((n + 1))
dir="$(fixture cleanup-unlisted)" || { fail 'cleanup-unlisted: fixture failed'; rc=1; }
(
  cd "$dir/repo" || exit 1
  git branch -q ci-verify/renamed-case
  git push -q origin ci-verify/renamed-case
) || { fail 'cleanup-unlisted: could not seed the leftover branch'; rc=1; }
cleanup_case "$dir"
out="$RUN_OUT"
if [ "$RUN_STATUS" -ne 0 ]; then
  fail 'cleanup-unlisted: --cleanup exited non-zero'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
elif [ -n "$(leftover "$dir")" ]; then
  fail "cleanup-unlisted: --cleanup left $(leftover "$dir") on the remote — the guard will refuse forever"
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
elif ! grep -qF 'deleted branch ci-verify/renamed-case on origin' <<< "$out"; then
  fail 'cleanup-unlisted: the branch went, but --cleanup did not say so'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
else
  run_case "$dir"
  if ! grep -qF 'no other verification run is live' <<< "$RUN_OUT"; then
    fail 'cleanup-unlisted: --run is still blocked after the recovery it names'
    printf '%s\n' "$RUN_OUT" | sed 's/^/        /'
    rc=1
  else
    pass 'cleanup-unlisted'
  fi
fi

# The enumerated half still works. A branch in CASES with an open pull request on it
# is the ordinary case: closing the PR takes the branch with it, and the sweep must
# not then report a failure to delete a ref that is already gone.
n=$((n + 1))
dir="$(fixture cleanup-listed)" || { fail 'cleanup-listed: fixture failed'; rc=1; }
(
  cd "$dir/repo" || exit 1
  git branch -q ci-verify/pint
  git push -q origin ci-verify/pint
) || { fail 'cleanup-listed: could not seed the branch'; rc=1; }
cat > "$dir/prs.json" <<'JSON'
[
  {"number": 21, "headRefName": "ci-verify/pint"},
  {"number": 9,  "headRefName": "fix/dusk-signout-flake"}
]
JSON
cleanup_case "$dir"
out="$RUN_OUT"
if [ "$RUN_STATUS" -ne 0 ]; then
  fail 'cleanup-listed: --cleanup exited non-zero'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
elif [ -n "$(leftover "$dir")" ]; then
  fail "cleanup-listed: --cleanup left $(leftover "$dir") on the remote"
  rc=1
elif ! grep -qF 'closed pull request #21 (ci-verify/pint)' <<< "$out"; then
  fail 'cleanup-listed: the pull request was not closed, or not reported'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
elif grep -qF 'could not delete' <<< "$out"; then
  fail 'cleanup-listed: reported failing to delete a branch its own PR close removed'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
elif grep -qF '#9' <<< "$out"; then
  fail 'cleanup-listed: closed an unrelated pull request'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
else
  pass 'cleanup-listed'
fi

# And a no-op says it is one. One header and exit 0 read the same whether nine
# branches went or none did, which is how the bug above stayed invisible.
n=$((n + 1))
dir="$(fixture cleanup-quiet)" || { fail 'cleanup-quiet: fixture failed'; rc=1; }
cleanup_case "$dir"
out="$RUN_OUT"
if [ "$RUN_STATUS" -ne 0 ]; then
  fail 'cleanup-quiet: --cleanup exited non-zero on a clean repository'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
elif ! grep -qF 'nothing to clean' <<< "$out"; then
  fail 'cleanup-quiet: a no-op cannot be told apart from a cleanup that removed things'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
elif grep -qE 'deleted branch|closed pull request' <<< "$out"; then
  fail 'cleanup-quiet: reported removing something on a clean repository'
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
else
  pass 'cleanup-quiet'
fi

printf '\n'
if [ "$rc" -ne 0 ]; then
  fail 'the --run preconditions do not catch everything they claim to.'
else
  printf '\033[1m%d/%d — --run refuses every start it should, and --cleanup clears every refusal it names.\033[0m\n' "$n" "$n"
fi
exit "$rc"
