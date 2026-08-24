#!/usr/bin/env bash
#
# Tests for ci/verify-protection.sh.
#
# Same argument as ci/verify-lint-selftest.sh: a checker that always passes and a
# checker that works look identical from the outside, and this one will be run
# rarely — after a protection change, before a release — which is exactly when
# nobody notices it has gone quiet.
#
# Every case is a rule this repo could plausibly end up with. The `stale-context`
# and `unrequired-check` cases have both already happened once (TWO-36: `ci`
# required, no job by that name; leaves reporting but not required).
#
# No network, no gh, no credential: each case is a saved protection response with
# one field changed.
#
# Usage: ./ci/verify-protection-selftest.sh

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SCRIPT="$REPO_ROOT/ci/verify-protection.sh"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/protection-selftest.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

pass() { printf '\033[32mPASS\033[0m  %s\n' "$*"; }
fail() { printf '\033[31mFAIL\033[0m  %s\n' "$*" >&2; }

rc=0
n=0

command -v jq >/dev/null || { printf 'jq is required\n' >&2; exit 1; }

# The rule as TWO-36 applied it, in the shape GitHub returns. The contexts are
# read from ci/verify-pipeline.sh so this fixture cannot drift from the list the
# script under test compares against — a hand-copied list here would make every
# case pass by agreeing with itself.
required=$(sed -n 's/^REQUIRED_CHECKS=(\(.*\))$/\1/p' "$REPO_ROOT/ci/verify-pipeline.sh")
[ -n "$required" ] || { printf 'could not read REQUIRED_CHECKS from ci/verify-pipeline.sh\n' >&2; exit 1; }

good() {
  jq -n --arg checks "$required" '{
    required_status_checks: {
      strict: true,
      checks: ($checks | split(" ") | map({context: ., app_id: null}))
    },
    required_pull_request_reviews: {
      required_approving_review_count: 1,
      dismiss_stale_reviews: true,
      require_last_push_approval: true
    },
    enforce_admins: { enabled: true },
    allow_force_pushes: { enabled: false },
    allow_deletions: { enabled: false }
  }'
}

# expect_fail <slug> <expected substring> <jq mutation>
expect_fail() {
  local slug="$1" expected="$2" mutation="$3"
  local file out status
  n=$((n + 1))
  file="$WORK/$slug.json"
  good | jq "$mutation" > "$file" || { fail "$slug: the mutation itself failed to apply"; rc=1; return; }

  out="$("$SCRIPT" "$file" 2>&1)"
  status=$?

  if [ "$status" -eq 0 ]; then
    fail "$slug: passed. It should have caught this."
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
    return
  fi
  if ! grep -qF -- "$expected" <<< "$out"; then
    fail "$slug: failed, but not for the stated reason. Wanted: ${expected}"
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
    return
  fi
  pass "$slug"
}

printf '\n\033[1m==> The rule does not require the checks\033[0m\n'

expect_fail no-required-checks \
  "Every job in ci.yml is advisory" \
  'del(.required_status_checks)'

# The finding from the TWO-22 acceptance run, inverted. On `ci-verify/gate` the
# aggregate was SKIPPED and the PR was blocked only because `static` is required
# in its own right. Drop that and the same branch merges clean.
expect_fail unrequired-check \
  "are not required on \`main\`" \
  '.required_status_checks.checks |= map(select(.context != "static"))'

# `ci` is the workflow name. No job reports it, so requiring it blocks every pull
# request forever — pending, not red, which reads as a slow runner.
expect_fail stale-context \
  "blocks every pull request forever" \
  '.required_status_checks.checks += [{context: "ci", app_id: null}]'

# The deprecated shape. A rule written through the older API populates `contexts`
# and leaves `checks` empty; reading only `checks` reports a correct rule as an
# empty one, so this case must PASS.
n=$((n + 1))
legacy="$WORK/legacy.json"
good | jq '.required_status_checks |= {strict: .strict, contexts: (.checks | map(.context))}' > "$legacy"
if "$SCRIPT" "$legacy" >/dev/null 2>&1; then
  pass "legacy-contexts-shape: a rule using the deprecated \`contexts\` field is read, not reported as empty"
else
  fail "legacy-contexts-shape: a valid rule in the deprecated shape was reported as broken"
  "$SCRIPT" "$legacy" 2>&1 | sed 's/^/        /'
  rc=1
fi

printf '\n\033[1m==> The rule does not stop a self-merge\033[0m\n'

expect_fail no-approval \
  "no self-merge" \
  '.required_pull_request_reviews.required_approving_review_count = 0'

expect_fail no-review-block \
  "no self-merge" \
  'del(.required_pull_request_reviews)'

expect_fail stale-reviews-kept \
  "An approval survives a force-push" \
  '.required_pull_request_reviews.dismiss_stale_reviews = false'

printf '\n\033[1m==> The rule does not apply, or can be gone round\033[0m\n'

expect_fail admins-exempt \
  "Red stops nobody" \
  '.enforce_admins.enabled = false'

expect_fail force-push-allowed \
  "without passing a single check" \
  '.allow_force_pushes.enabled = true'

expect_fail deletions-allowed \
  "Deleting \`main\` is allowed" \
  '.allow_deletions.enabled = true'

printf '\n\033[1m==> Tripwires (warn, do not block)\033[0m\n'

n=$((n + 1))
lax="$WORK/lax.json"
good | jq '.required_status_checks.strict = false' > "$lax"
out="$("$SCRIPT" "$lax" 2>&1)"
if [ $? -eq 0 ] && grep -qF "can still land a red \`main\`" <<< "$out"; then
  pass "strict-off: warns, does not block"
else
  fail "strict-off: expected a warning and a zero exit"
  printf '%s\n' "$out" | sed 's/^/        /'
  rc=1
fi

printf '\n\033[1m==> The response is not a protection rule at all\033[0m\n'

# TWO-96. `gh api` writes the API's error body to stdout, so a refused call
# produces output that is non-empty and valid JSON, and the old guard tested only
# for emptiness. Every field below was then read off an error message, found
# absent, and printed as a complete description of an unprotected branch.
#
# These cases are the file form of that, which is reachable without a network:
# the header of the script under test tells you to save the response with
# `gh api ... > p.json`, and on a failed call that file holds the error body.
#
# The distinction is the whole point. "There is no rule", "this token may not
# read the rule" and "this account cannot have a rule" have three different
# remedies, and only the first is a finding about this repo. So each case asserts
# the reason as well as the exit — and every one of them asserts the per-field
# report is *absent*, because printing it is the bug.

NO_VERDICT_MARKERS=(
  'Every job in ci.yml is advisory'
  'force pushes to `main` are allowed'
  'Deleting `main` is allowed'
  '`enforce_admins` is off'
  'no approving review is required'
)

# A response the script could not read must not be described field by field.
refute_field_report() {
  local slug="$1" out="$2" marker
  for marker in "${NO_VERDICT_MARKERS[@]}"; do
    if grep -qF -- "$marker" <<< "$out"; then
      fail "$slug: read a per-field verdict off a response it could not read (\"${marker}\"). That is TWO-96."
      printf '%s\n' "$out" | sed 's/^/        /'
      return 1
    fi
  done
  return 0
}

# check_case <slug> <wanted exit> <expected substring> <actual exit> <output>
check_case() {
  local slug="$1" want="$2" expected="$3" got="$4" out="$5"
  if [ "$got" -ne "$want" ]; then
    fail "$slug: exited ${got}, wanted ${want} (0 verified / 1 a finding / 2 could not determine)."
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
    return
  fi
  if ! grep -qF -- "$expected" <<< "$out"; then
    fail "$slug: exited ${got} for the wrong stated reason. Wanted: ${expected}"
    printf '%s\n' "$out" | sed 's/^/        /'
    rc=1
    return
  fi
  [ "$want" -eq 0 ] || refute_field_report "$slug" "$out" || { rc=1; return; }
  pass "$slug"
}

# expect_body <slug> <wanted exit> <expected substring> <file contents>
expect_body() {
  local slug="$1" want="$2" expected="$3" body="$4"
  local file out status
  n=$((n + 1))
  file="$WORK/body-$slug.json"
  printf '%s' "$body" > "$file"
  out="$("$SCRIPT" "$file" 2>&1)"
  status=$?
  check_case "$slug" "$want" "$expected" "$status" "$out"
}

# The body TWO-95 actually got back. GitHub Free plus a private repo has no
# branch protection feature to configure, which is a different fact from a repo
# that could be protected and is not — different remedy, different owner.
expect_body plan-403-body 1 \
  "GitHub's plan for this repository has no branch protection" \
  '{"message":"Upgrade to GitHub Pro or make this repository public to enable this feature.","documentation_url":"https://docs.github.com/rest/branches/branch-protection","status":"403"}'

# The genuinely unprotected answer. Red is correct here, and it is the only one
# of these that is a finding about the rule rather than about the reader.
expect_body unprotected-body 1 \
  "is not protected" \
  '{"message":"Branch not protected","documentation_url":"https://docs.github.com/rest/branches/branch-protection#get-branch-protection"}'

# Reads identically to the line above to anything that only looks for failure.
# It means the token cannot see the repo, so nothing at all can be concluded.
expect_body not-found-body 2 \
  "cannot conclude" \
  '{"message":"Not Found","documentation_url":"https://docs.github.com/rest"}'

expect_body no-permission-body 2 \
  "Administration: read" \
  '{"message":"Resource not accessible by personal access token","documentation_url":"https://docs.github.com/rest","status":"403"}'

# Valid JSON, an object, and carries not one key a protection rule has. That is
# a malformed rule, not an empty one, and the difference is that an empty rule
# would be a finding while this is a reason to stop.
expect_body empty-object 2 \
  "not a branch protection rule" \
  '{}'

expect_body not-an-object 2 \
  "not a branch protection rule" \
  '[]'

printf '\n\033[1m==> Live mode, offline: the status line decides, not the body\033[0m\n'

# The bug only appears on the `gh` path, so a suite made entirely of saved files
# cannot see it. A stub `gh` on PATH and a throwaway repo whose origin is a
# GitHub URL exercise that path exactly and still take no network and no
# credential. The stub reproduces the two behaviours that caused TWO-96: the
# error body goes to *stdout*, and the call exits non-zero while doing it.
STUB="$WORK/stub"
mkdir -p "$STUB"
cat > "$STUB/gh" <<'STUB_EOF'
#!/usr/bin/env bash
# Stands in for gh: prints the canned response to stdout whatever the status is,
# and exits non-zero on a non-2xx, exactly as the real one does.
case "${1:-}" in
  auth) exit 0 ;;
  api)
    [ -s "${GH_STUB_RESPONSE:-}" ] || { printf 'gh: connection refused\n' >&2; exit 1; }
    cat "$GH_STUB_RESPONSE"
    head -1 "$GH_STUB_RESPONSE" | grep -q ' 2[0-9][0-9] ' || {
      printf 'gh: HTTP error\n' >&2
      exit 1
    }
    ;;
  *) exit 1 ;;
esac
STUB_EOF
chmod +x "$STUB/gh"

# A repository that exists only to give verify-protection.sh an origin to parse a
# slug out of. It is never pushed to and never fetched from; the stub `gh` above is
# the only thing that answers, so no case here reaches github.com. The URL names
# the real repo because that is what the script reads in production — it used to
# name the old TWO-Gaming org on the belief that the name was unreachable, which
# was never true: a transfer redirect carried repos/<oldorg>/<repo> straight back
# to this repository. The stub is the isolation, not the hostname.
FAKE_REPO="$WORK/repo"
git init -q "$FAKE_REPO" 2>/dev/null
git -C "$FAKE_REPO" remote add origin https://github.com/TogetherWeOwn/two-web.git

# gh writes the status line, then headers, then a CRLF blank line, then the body.
http_response() {
  printf 'HTTP/2.0 %s\r\n' "$1"
  printf 'Content-Type: application/json; charset=utf-8\r\n'
  printf 'X-GitHub-Media-Type: github.v3; format=json\r\n'
  printf '\r\n'
  printf '%s' "$2"
}

# expect_live <slug> <wanted exit> <expected substring> <status> <body>
expect_live() {
  local slug="$1" want="$2" expected="$3" statusline="$4" body="$5"
  local file out status
  n=$((n + 1))
  file="$WORK/live-$slug.http"
  http_response "$statusline" "$body" > "$file"
  out="$(cd "$FAKE_REPO" && PATH="$STUB:$PATH" GH_STUB_RESPONSE="$file" "$SCRIPT" 2>&1)"
  status=$?
  check_case "live-$slug" "$want" "$expected" "$status" "$out"
}

expect_live plan-403 1 \
  "GitHub's plan for this repository has no branch protection" \
  '403 Forbidden' \
  '{"message":"Upgrade to GitHub Pro or make this repository public to enable this feature.","status":"403"}'

# The same answer, but recorded rather than composed. Every other live case here
# is a body we wrote to the shape we expected; this one is the bytes GitHub
# actually returned for this repo, captured on TWO-95 (2026-08-20) by the only
# person on the team holding a credential, and pasted in whole — the real header
# set, the real documentation_url, the trailing newline. It is kept verbatim and
# built by hand instead of through http_response() so that nothing in this file's
# idea of the format can launder the evidence.
#
# It duplicates plan-403's assertion on purpose, and it is worth being exact
# about how little it adds on its own: the composed body was a guess at the
# message, and this one is the message. That is the whole of it. The status line
# and header shape here are no different from the composed case, so this pins the
# wording GitHub actually uses and nothing about the parse.
n=$((n + 1))
observed="$WORK/live-plan-403-observed.http"
{
  printf 'HTTP/2.0 403 Forbidden\r\n'
  printf 'X-Accepted-Github-Permissions: administration=read\r\n'
  printf 'Content-Type: application/json; charset=utf-8\r\n'
  printf '\r\n'
  printf '%s\n' '{"message":"Upgrade to GitHub Pro or make this repository public to enable this feature.","documentation_url":"https://docs.github.com/rest/branches/branch-protection#get-branch-protection","status":"403"}'
} > "$observed"
out="$(cd "$FAKE_REPO" && PATH="$STUB:$PATH" GH_STUB_RESPONSE="$observed" "$SCRIPT" 2>&1)"
status=$?
check_case live-plan-403-observed 1 \
  "GitHub's plan for this repository has no branch protection" "$status" "$out"

# The paste on TWO-95 was elided down to the two headers that carried the
# argument, and the case above therefore has the same three-line header block as
# every composed case here. A real GitHub response carries a dozen or more. That
# difference is invisible while the body is found by scanning for the blank line,
# and fatal the moment someone "simplifies" that into a fixed offset — which
# reads as correct against every other case in this file.
#
# So: the same recorded answer behind a header block of realistic length. This is
# the case that fails if the header skip is ever hardcoded.
n=$((n + 1))
manyhdr="$WORK/live-plan-403-headers.http"
{
  printf 'HTTP/2.0 403 Forbidden\r\n'
  printf 'Server: GitHub.com\r\n'
  printf 'Date: Wed, 20 Aug 2026 02:50:01 GMT\r\n'
  printf 'Content-Type: application/json; charset=utf-8\r\n'
  printf 'X-Accepted-Github-Permissions: administration=read\r\n'
  printf 'X-GitHub-Media-Type: github.v3; format=json\r\n'
  printf 'X-RateLimit-Limit: 5000\r\n'
  printf 'X-RateLimit-Remaining: 4998\r\n'
  printf 'X-GitHub-Request-Id: C4E1:1F2A:8A0B21:1148E0C:68A5\r\n'
  printf 'Strict-Transport-Security: max-age=31536000\r\n'
  printf 'Referrer-Policy: origin-when-cross-origin\r\n'
  printf 'Vary: Accept, Authorization, Cookie, X-GitHub-OTP\r\n'
  printf '\r\n'
  printf '%s\n' '{"message":"Upgrade to GitHub Pro or make this repository public to enable this feature.","documentation_url":"https://docs.github.com/rest/branches/branch-protection#get-branch-protection","status":"403"}'
} > "$manyhdr"
out="$(cd "$FAKE_REPO" && PATH="$STUB:$PATH" GH_STUB_RESPONSE="$manyhdr" "$SCRIPT" 2>&1)"
status=$?
check_case live-plan-403-many-headers 1 \
  "GitHub's plan for this repository has no branch protection" "$status" "$out"

expect_live no-permission 2 \
  "Administration: read" \
  '403 Forbidden' \
  '{"message":"Resource not accessible by personal access token"}'

expect_live unprotected 1 \
  "is not protected" \
  '404 Not Found' \
  '{"message":"Branch not protected","documentation_url":"https://docs.github.com/rest"}'

# The case the header of the script under test warns about, and the one the old
# guard could not tell from the line above it.
expect_live not-found 2 \
  "cannot conclude" \
  '404 Not Found' \
  '{"message":"Not Found","documentation_url":"https://docs.github.com/rest"}'

expect_live unauthenticated 2 \
  "GitHub rejected the credential" \
  '401 Unauthorized' \
  '{"message":"Bad credentials"}'

# gh never reached GitHub: no status line, no body, nothing to read. Silence is
# not an answer either, and it used to be the only case the guard did catch.
n=$((n + 1))
out="$(cd "$FAKE_REPO" && PATH="$STUB:$PATH" GH_STUB_RESPONSE="$WORK/nothing" "$SCRIPT" 2>&1)"
status=$?
check_case live-no-response 2 "no HTTP response" "$status" "$out"

# The header parsing was modelled on what `gh api -i` documents itself as
# printing — status line, headers, blank line, body — and for a while nobody here
# held a credential to check that against the real thing. TWO-95 checked it: the
# live-plan-403-observed case above is the recorded response, and the model was
# right. So this is now one confirmed format, not an assumption.
#
# One observation is not every response, so the cases below stay exactly as they
# were. They are the ones where the model is *wrong*: a misread response must come
# out as "could not determine", never as a verdict. A wrong guess about the format
# is then a nuisance, not a false red about a branch nobody read, which is TWO-96
# again. Deleting them because the format has been seen once would be reading the
# evidence backwards.
n=$((n + 1))
malformed="$WORK/live-malformed.http"
printf 'HTTP/2.0 200 OK\r\nContent-Type: application/json\r\n{"enforce_admins":{"enabled":true}}' > "$malformed"
out="$(cd "$FAKE_REPO" && PATH="$STUB:$PATH" GH_STUB_RESPONSE="$malformed" "$SCRIPT" 2>&1)"
status=$?
check_case live-no-header-break 2 "not JSON" "$status" "$out"

n=$((n + 1))
nostatus="$WORK/live-nostatus.http"
printf '{"message":"Branch not protected"}' > "$nostatus"
out="$(cd "$FAKE_REPO" && PATH="$STUB:$PATH" GH_STUB_RESPONSE="$nostatus" "$SCRIPT" 2>&1)"
status=$?
check_case live-no-status-line 2 "no HTTP response" "$status" "$out"

# And the answer everything above exists to let through untouched.
n=$((n + 1))
live_clean="$WORK/live-clean.http"
http_response '200 OK' "$(good)" > "$live_clean"
out="$(cd "$FAKE_REPO" && PATH="$STUB:$PATH" GH_STUB_RESPONSE="$live_clean" "$SCRIPT" 2>&1)"
status=$?
check_case live-clean 0 "Branch protection verified" "$status" "$out"

printf '\n\033[1m==> The rule as applied still passes\033[0m\n'

n=$((n + 1))
clean="$WORK/clean.json"
good > "$clean"
if "$SCRIPT" "$clean" >/dev/null 2>&1; then
  pass "clean: the rule TWO-36 applied verifies green"
else
  fail "clean: the intended rule was reported as broken"
  "$SCRIPT" "$clean" 2>&1 | sed 's/^/        /'
  rc=1
fi

if [ "$rc" -eq 0 ]; then
  printf '\n\033[1m%d/%d — the protection check catches every hole it claims to.\033[0m\n' "$n" "$n"
else
  printf '\n\033[31mThe protection check does not catch what it claims to. Fix it before trusting it.\033[0m\n' >&2
fi
exit "$rc"
