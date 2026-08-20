#!/usr/bin/env bash
#
# Tests for the tester: the `--run` preconditions.
#
# `verify-pipeline.sh --lint` has ci/verify-lint-selftest.sh watching it. The
# `--run` preconditions had nothing, because they need `gh`, a remote and a push —
# and they are the half-second checks standing between an operator and forty
# minutes of a run that will answer the wrong question. A precondition that has
# quietly stopped firing looks exactly like one that has nothing to complain about.
#
# So: build a throwaway repository whose `origin` *reads* as GitHub while its bytes
# go to a bare repo next door, put a stub `gh` on PATH, and assert each guard fires
# for its own reason and stays quiet otherwise. No network, no real repository, and
# nothing that could reach TWO-Gaming/two-web even if it tried.
#
# The stub answers `gh pr list --json ... --jq ...` by running the real jq over a
# fixture file, so the filter tested here is the filter verify-pipeline.sh ships.
#
# What is pinned, in the order verify-pipeline.sh checks it:
#
#   gh-unauth     `gh` is installed but not logged in
#   dirty         uncommitted changes in the working tree
#   local-origin  `origin` is a filesystem clone, not GitHub
#   actions-api   the credential cannot read the Actions API
#   live-pr       another run's pull request is open        (TWO-103)
#   live-branch   another run's branch is on the remote     (TWO-103)
#   clear         a negative control: none of the above fires on a normal repo
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

# What `git config --get remote.origin.url` reports. Never contacted: an
# `insteadOf` rewrite sends every actual transfer to the bare repo in the fixture.
FAKE_ORIGIN="https://github.com/TWO-Gaming/two-web.git"

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
  cp "$REPO_ROOT/ci/verify-pipeline.sh" "$dir/repo/ci/"
  # The lint asserts the budgets job pins its thresholds, so the fixture needs the
  # thresholds file too — without it every case fails on the fixture, not the mutation.
  cp "$REPO_ROOT/ci/lighthouserc.cjs" "$dir/repo/ci/"

  (
    cd "$dir/repo" || exit 1
    git init -q -b main .
    git config user.email 'ci@example.invalid'
    git config user.name 'ci selftest'
    git config commit.gpgsign false
    git remote add origin "$FAKE_ORIGIN"
    git config "url.${dir}/remote.git.insteadOf" "$FAKE_ORIGIN"
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
    [ "${2:-}" = "list" ] || exit 0
    filter='.'
    while [ "$#" -gt 0 ]; do
      [ "$1" = "--jq" ] && filter="${2:-.}"
      shift
    done
    # The real gh applies this with its built-in jq. Applying it with the real one
    # means the filter in verify-pipeline.sh is what this test exercises.
    jq -r "$filter" < "${GH_STUB_PR_JSON:?}"
    ;;
esac
SH
  chmod +x "$dir/bin/gh"
  printf '[]\n' > "$dir/prs.json"
  # Healthy by default. A case that wants a broken `gh` overwrites its own copy.
  printf '0\n' > "$dir/auth-status"
  printf '0\n' > "$dir/api-status"

  echo "$dir"
}

# run_case <dir> -> combined output in $RUN_OUT, exit status in $RUN_STATUS.
# Not a command substitution: that would run in a subshell and lose the status.
run_case() {
  local dir="$1"
  RUN_OUT="$(cd "$dir/repo" && PATH="$dir/bin:$PATH" GH_STUB_PR_JSON="$dir/prs.json" \
    GH_STUB_AUTH_STATUS="$dir/auth-status" GH_STUB_API_STATUS="$dir/api-status" \
    ./ci/verify-pipeline.sh --run 2>&1)"
  RUN_STATUS=$?
}

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
  {"number": 12, "headRefName": "ci/verify-actions-api"}
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

# A workspace clone or a local mirror. Results come from the GitHub Actions API,
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

printf '\n\033[1m==> gh is not authenticated\033[0m\n'

# The first precondition, and the one most likely to be true on a fresh machine:
# a `gh` that is installed but has never been logged in. Everything after it —
# opening the pull requests, reading the results back — is a `gh` call, so an
# unauthenticated credential fails eight times over, forty minutes late.
dir="$(fixture gh-unauth)" || { fail 'gh-unauth: fixture failed'; rc=1; }
printf '1\n' > "$dir/auth-status"
PRE_EXISTING='no-such-ref'
expect_refused gh-unauth 'gh is not authenticated' "$dir"

printf '\n\033[1m==> The Actions API cannot be read\033[0m\n'

# A token with push access but without `Actions: read`. This one is the reason the
# check exists rather than a nicety: the run pushes fine, opens all eight pull
# requests, and then reads nothing back — which is byte-for-byte what a pipeline
# that never ran looks like, and the two diagnoses point in opposite directions
# (TWO-87). Authenticated `gh`, clean tree, GitHub origin: only the API read is
# broken, so nothing but this guard can catch it.
dir="$(fixture actions-api)" || { fail 'actions-api: fixture failed'; rc=1; }
printf '1\n' > "$dir/api-status"
PRE_EXISTING='no-such-ref'
expect_refused actions-api 'cannot read the Actions API on TWO-Gaming/two-web' "$dir"

printf '\n'
if [ "$rc" -ne 0 ]; then
  fail 'the --run preconditions do not catch everything they claim to.'
else
  printf '\033[1m%d/%d — the --run preconditions refuse every start they should.\033[0m\n' "$n" "$n"
fi
exit "$rc"
