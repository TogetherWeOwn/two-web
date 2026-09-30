#!/usr/bin/env bash
# Exercise the real smoke checks with fake curl: no network or database.
# Header probes stay healthy while the separate join body request fails.
set -uo pipefail
export BASH_ENV=/dev/null

setup_fail() { printf 'FAIL  fixture setup: %s\n' "$1" >&2; exit 2; }
# Nested setup-failure tests stop before running cases; avoid recursive tests if
# a regression accidentally lets their setup succeed.
case "${1:-}" in
    ''|--fixture-only) ;;
    *) setup_fail 'unexpected argument' ;;
esac
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)" || setup_fail 'resolve root'
SMOKE_SCRIPT="${SMOKE_SCRIPT:-$ROOT/bin/smoke-staging.sh}"
WORK="$(mktemp -d "${PAPERCLIP_RUN_SCRATCH_DIR:-${TMPDIR:-/tmp}}/smoke-staging-selftest.XXXXXX")" \
    || setup_fail 'mktemp'
[ -n "$WORK" ] && [ -d "$WORK" ] || setup_fail 'mktemp directory missing'
trap 'rm -rf "$WORK"' EXIT

cat > "$WORK/curl" <<'STUB' || setup_fail 'write fake curl'
#!/bin/sh
set -u
url=""; output=""; headers=""; writeout=""; cookie=""
while [ "$#" -gt 0 ]; do
    case "$1" in
        -s) shift ;;
        -o) output="$2"; shift 2 ;;
        -D) headers="$2"; shift 2 ;;
        -w) writeout="$2"; shift 2 ;;
        --max-time) [ "$2" = 20 ] || exit 99; shift 2 ;;
        -H)
            case "$2" in
                'Cookie: two_web_session=fixture') cookie=fixture ;;
                'CF-Access-Client-Id: fixture-id'|'CF-Access-Client-Secret: fixture-secret') ;;
                *) exit 99 ;;
            esac
            shift 2 ;;
        https://smoke.test/*) url="$1"; shift ;;
        *) exit 99 ;;
    esac
done

code=200; location=""; body='<html>ok</html>'; status=0
case "$url" in
    https://smoke.test/up|https://smoke.test/) ;;
    https://smoke.test/discord) code=302; location='https://discord.gg/fixture' ;;
    https://smoke.test/events.json)
        if [ -z "$cookie" ]; then code=302; location='/auth/discord/redirect'; fi ;;
    https://smoke.test/join\?*)
        # Join must remain the guest shape even with --cookie.
        [ -z "$cookie" ] || exit 99
        case "$url" in
            *evil.test*) target=hostile; body='<html><a href="/join/discord">Join</a></html>' ;;
            *) target=safe; body='<html><a href="/join/discord?next=%2Fevents">Join</a></html>' ;;
        esac
        if [ -z "$output" ] && [ "$target" = "$STUB_TARGET" ]; then
            case "$STUB_MODE" in
                timeout) body=""; code=000; status=28 ;;
                partial-timeout) status=28 ;;
                server-error) code=500 ;;
                redirect) code=302; location='https://fixture.cloudflareaccess.com/login' ;;
                empty) body="" ;;
                leak) body='<html>evil.test</html>' ;;
                missing-link) body='<html>no join link</html>' ;;
                ok) ;;
                *) exit 99 ;;
            esac
        fi ;;
    *) exit 99 ;;
esac
if [ -n "$headers" ]; then
    printf 'HTTP/1.1 %s\r\n' "$code"
    [ -z "$location" ] || printf 'Location: %s\r\n' "$location"
    printf '\r\n'
fi
[ -n "$output" ] || printf '%s' "$body"
if [ -n "$writeout" ]; then
    case "$writeout" in
        '%{http_code}') printf '%s' "$code" ;;
        '\n%{http_code}') printf '\n%s' "$code" ;;
        *) exit 99 ;;
    esac
fi
exit "$status"
STUB
chmod +x "$WORK/curl" || setup_fail 'chmod fake curl'
[ -x "$WORK/curl" ] || setup_fail 'fake curl not executable'
selected_curl="$(PATH="$WORK:$PATH" sh -c 'command -v curl')" || setup_fail 'resolve fake curl'
[ "$selected_curl" = "$WORK/curl" ] || setup_fail 'fake curl not selected'

rc=0; n=0
run_case() {
    local target="$1" mode="$2" expected_status="$3" reason="$4"
    local out status label summary
    if [ "$target" = hostile ]; then label='/join?next=hostile'; else label='/join?next=/events'; fi
    out="$(PATH="$WORK:$PATH" STUB_TARGET="$target" STUB_MODE="$mode" \
        CF_ACCESS_CLIENT_ID=fixture-id CF_ACCESS_CLIENT_SECRET=fixture-secret \
        sh "$SMOKE_SCRIPT" https://smoke.test --cookie two_web_session=fixture 2>&1)"
    status=$?
    n=$((n + 1))
    if [ "$expected_status" -eq 0 ]; then summary='7 passed, 0 failed'; else summary='6 passed, 1 failed'; fi
    if [ "$status" -eq "$expected_status" ] && grep -qFx "$summary" <<< "$out" \
        && grep -qF "$reason" <<< "$out" \
        && { [ "$expected_status" -eq 0 ] || ! grep -qF "PASS  $label ->" <<< "$out"; }; then
        printf 'PASS  %s/%s\n' "$target" "$mode"
    else
        printf 'FAIL  %s/%s: expected exit %s, "%s", and "%s"; got exit %s\n%s\n' \
            "$target" "$mode" "$expected_status" "$summary" "$reason" "$status" "$out" >&2
        rc=1
    fi
}

run_case hostile ok 0 'PASS  /join?next=hostile -> 200, hostile host absent (guard enforced)'
for target in hostile safe; do
    run_case "$target" timeout 1 'body fetch failed (curl exit 28)'
    run_case "$target" partial-timeout 1 'body fetch failed (curl exit 28)'
    run_case "$target" server-error 1 'body fetch expected 200, got 500'
    run_case "$target" redirect 1 'body fetch expected 200, got 302'
    run_case "$target" empty 1 'body fetch returned an empty body'
done
run_case hostile leak 1 'hostile host leaks into the HTML'
run_case safe missing-link 1 'one-click href does not forward next=/events'

setup_failure_cases() {
    local bin="$WORK/setup-bin" mode reason out status
    local real_mktemp real_cat real_chmod
    real_mktemp="$(command -v mktemp)" || setup_fail 'resolve mktemp'
    real_cat="$(command -v cat)" || setup_fail 'resolve cat'
    real_chmod="$(command -v chmod)" || setup_fail 'resolve chmod'
    mkdir "$bin" || setup_fail 'create setup-test fixtures'
    cat > "$bin/dispatch" <<'STUB' || setup_fail 'write setup-test fixtures'
#!/bin/sh
case "${0##*/}" in
    curl)
        printf 'fallback\n' >> "$FALLBACK_LOG"
        exit 99 ;;
    mktemp)
        [ "$SETUP_FAILURE" != mktemp ] || exit 1
        exec "$REAL_MKTEMP" "$@" ;;
    cat)
        [ "$SETUP_FAILURE" != write ] || exit 1
        exec "$REAL_CAT" "$@" ;;
    chmod)
        case "$SETUP_FAILURE" in
            chmod) exit 1 ;;
            nonexecutable) exit 0 ;;
        esac
        exec "$REAL_CHMOD" "$@" ;;
    *) exit 99 ;;
esac
STUB
    chmod +x "$bin/dispatch" || setup_fail 'chmod setup-test fixtures'
    for mode in curl mktemp cat chmod; do
        ln -s dispatch "$bin/$mode" || setup_fail 'link setup-test fixtures'
    done
    # Inherited curl is always a non-network sentinel, even if setup regresses.
    for mode in mktemp write chmod nonexecutable; do
        case "$mode" in
            mktemp) reason='mktemp' ;;
            write) reason='write fake curl' ;;
            chmod) reason='chmod fake curl' ;;
            nonexecutable) reason='fake curl not executable' ;;
        esac
        : > "$bin/fallback.log" || setup_fail 'reset fallback log'
        out="$(PATH="$bin:$PATH" SETUP_FAILURE="$mode" FALLBACK_LOG="$bin/fallback.log" \
            REAL_MKTEMP="$real_mktemp" REAL_CAT="$real_cat" REAL_CHMOD="$real_chmod" \
            "$BASH" "${BASH_SOURCE[0]}" --fixture-only 2>&1)"
        status=$?
        n=$((n + 1))
        if [ "$status" -eq 2 ] && [ ! -s "$bin/fallback.log" ] \
            && grep -qFx "FAIL  fixture setup: $reason" <<< "$out" \
            && ! grep -q '^PASS  ' <<< "$out"; then
            printf 'PASS  setup/%s (zero fallback curl calls)\n' "$mode"
        else
            printf 'FAIL  setup/%s: expected exit 2, setup error, no PASS and zero fallback calls; got exit %s\n%s\n' \
                "$mode" "$status" "$out" >&2
            rc=1
        fi
    done
}
if [ "${1:-}" != --fixture-only ]; then setup_failure_cases; fi
printf '\n%d cases, result %d\n' "$n" "$rc"
exit "$rc"
