#!/bin/sh
# smoke-staging.sh — one-command post-deploy smoke check for TWO Web staging.
#
# Usage:
#   bin/smoke-staging.sh https://<staging-host>
#   bin/smoke-staging.sh https://<staging-host> --cookie 'two_web_session=...'
#
# Staging sits behind Cloudflare Access: export CF_ACCESS_CLIENT_ID and
# CF_ACCESS_CLIENT_SECRET (a service token, never committed) so the edge lets
# the probe through. Without them the script reports the Access challenge
# instead of misreading it as an app response.
#
# What it asserts:
#   1. GET /up           -> 200 (Laravel health check)
#   2. GET /discord      -> 302 to a Discord URL (invite funnel; zero DB by
#                            design, see routes/funnel.php)
#   3. GET /             -> 200 (homepage)
#   4. GET /events.json  -> 302 to the Discord login handoff for guests
#                            (route lives inside the `auth` group in
#                            routes/web.php, so an unauthenticated 200 is
#                            impossible by design). With --cookie, also
#                            asserts an authenticated 200.
#
# Prints PASS/FAIL per check. Exit 0 when every check passes, 1 otherwise.
#
# TOG-6772.
set -u

BASE_URL=""
COOKIE=""
SELFTEST=0

while [ $# -gt 0 ]; do
    case "$1" in
        --cookie=*) COOKIE="${1#--cookie=}"; shift ;;
        --cookie) COOKIE="${2:-}"; shift 2 ;;
        --selftest) SELFTEST=1; shift ;;
        -h|--help) sed -n '2,21p' "$0"; exit 0 ;;
        --*) echo "unknown option: $1" >&2; exit 2 ;;
        *) if [ -z "$BASE_URL" ]; then BASE_URL="$1"; else echo "unexpected argument: $1" >&2; exit 2; fi; shift ;;
    esac
done

pass_count=0
fail_count=0

pass() { printf 'PASS  %s\n' "$1"; pass_count=$((pass_count + 1)); }
fail() { printf 'FAIL  %s (%s)\n' "$1" "$2"; fail_count=$((fail_count + 1)); }

# GET without following redirects. Sets globals PROBE_CODE and PROBE_LOCATION.
# Staging sits behind Cloudflare Access: when CF_ACCESS_CLIENT_ID and
# CF_ACCESS_CLIENT_SECRET are set, their service-token headers are sent so the
# edge lets the probe through to the app. Nothing is hardcoded — without them
# the script reports the Access challenge instead of misreading it.
probe() {
    url="$1"
    set -- "$url"
    if [ -n "$COOKIE" ]; then set -- -H "Cookie: $COOKIE" "$url"; fi
    if [ -n "${CF_ACCESS_CLIENT_ID:-}" ] && [ -n "${CF_ACCESS_CLIENT_SECRET:-}" ]; then
        set -- -H "CF-Access-Client-Id: $CF_ACCESS_CLIENT_ID" \
               -H "CF-Access-Client-Secret: $CF_ACCESS_CLIENT_SECRET" "$@"
    fi
    PROBE_CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$@" </dev/null)
    PROBE_LOCATION=$(curl -s -o /dev/null -D - --max-time 20 "$@" </dev/null \
        | grep -i '^location:' | tr -d '\r' | sed 's/^[Ll]ocation:[ ]*//')
}

gated() {
    # gated <location> — true when the edge (Cloudflare Access) answered
    # instead of the app, so a 302 is not misread as an app redirect.
    case "$1" in
        *cloudflareaccess.com*) return 0 ;;
        *) return 1 ;;
    esac
}

check_up() {
    probe "$BASE_URL/up"
    if [ "$PROBE_CODE" = "200" ]; then
        pass "/up -> 200"
    elif gated "$PROBE_LOCATION"; then
        fail "/up" "got Cloudflare Access challenge; staging is gated, not the app"
    else
        fail "/up" "expected 200, got $PROBE_CODE"
    fi
}

check_discord() {
    probe "$BASE_URL/discord"
    code="$PROBE_CODE"; location="$PROBE_LOCATION"
    if gated "$location"; then
        fail "/discord" "got Cloudflare Access challenge ($code); staging is gated, not the app"
    else
        case "$location" in
            *discord.gg*|*discord.com*)
                if [ "$code" = "302" ] || [ "$code" = "301" ]; then
                    pass "/discord -> $code to Discord ($location)"
                else
                    fail "/discord" "redirect to Discord but status $code, expected 302"
                fi
                ;;
            *)
                fail "/discord" "expected 302 to a Discord URL, got $code -> ${location:-<no location>}"
                ;;
        esac
    fi
}

check_home() {
    probe "$BASE_URL/"
    if [ "$PROBE_CODE" = "200" ]; then
        pass "/ (home) -> 200"
    elif gated "$PROBE_LOCATION"; then
        fail "/" "got Cloudflare Access challenge; staging is gated, not the app"
    else
        fail "/" "expected 200, got $PROBE_CODE"
    fi
}

check_events_json() {
    hold_cookie="$COOKIE"
    COOKIE=""
    probe "$BASE_URL/events.json"
    COOKIE="$hold_cookie"
    code="$PROBE_CODE"; location="$PROBE_LOCATION"
    if gated "$location"; then
        fail "/events.json" "got Cloudflare Access challenge; staging is gated, not the app"
        return
    fi
    case "$location" in
        */auth/discord/redirect*|*/login*)
            if [ "$code" = "302" ]; then
                pass "/events.json -> 302 to login handoff (auth gate enforced)"
            else
                fail "/events.json" "login redirect but status $code, expected 302"
            fi
            ;;
        *)
            fail "/events.json" "expected 302 to login handoff, got $code -> ${location:-<no location>}"
            return
            ;;
    esac
    if [ -n "$hold_cookie" ]; then
        probe "$BASE_URL/events.json"
        if [ "$PROBE_CODE" = "200" ]; then
            pass "/events.json (authenticated) -> 200"
        else
            fail "/events.json (authenticated)" "expected 200, got $PROBE_CODE"
        fi
    else
        printf 'SKIP  /events.json (authenticated) -> 200 (pass --cookie with a staging session to run)\n'
    fi
}

selftest() {
    # Offline self-test: serve stub endpoints, run the real checks against them.
    stub=$(mktemp -d)
    cat > "$stub/stub.py" <<'EOF'
from http.server import BaseHTTPRequestHandler, HTTPServer

class H(BaseHTTPRequestHandler):
    def _send(self, code, headers=(), body=b''):
        self.send_response(code)
        for k, v in headers:
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)
    def do_GET(self):
        cookie = self.headers.get('Cookie', '')
        if self.path == '/up':
            self._send(200, body=b'ok')
        elif self.path == '/discord':
            self._send(302, [('Location', 'https://discord.gg/4GwEDNRTtx')])
        elif self.path == '/':
            self._send(200, body=b'<html>home</html>')
        elif self.path == '/events.json':
            if 'two_web_session=' in cookie:
                self._send(200, [('Content-Type', 'application/json')], b'[]')
            else:
                self._send(302, [('Location', '/auth/discord/redirect')])
        else:
            self._send(404)
    def log_message(self, *a):
        pass

HTTPServer(('127.0.0.1', 18731), H).serve_forever()
EOF
    python3 "$stub/stub.py" &
    server_pid=$!
    sleep 1
    echo "--- unauthenticated run ---"
    BASE_URL="http://127.0.0.1:18731" COOKIE="" sh "$0" "http://127.0.0.1:18731"
    rc1=$?
    echo "--- authenticated run ---"
    BASE_URL="http://127.0.0.1:18731" sh "$0" "http://127.0.0.1:18731" --cookie 'two_web_session=test'
    rc2=$?
    kill "$server_pid" 2>/dev/null
    rm -rf "$stub"
    if [ "$rc1" -eq 0 ] && [ "$rc2" -eq 0 ]; then
        echo "SELFTEST PASS"
        return 0
    else
        echo "SELFTEST FAIL (rc=$rc1/$rc2)"
        return 1
    fi
}

if [ "$SELFTEST" -eq 1 ]; then
    selftest
    exit $?
fi

if [ -z "$BASE_URL" ]; then
    echo "usage: $0 BASE_URL [--cookie 'name=value; ...'] [--selftest]" >&2
    exit 2
fi
if ! command -v curl >/dev/null 2>&1; then
    echo "curl is required" >&2
    exit 2
fi

BASE_URL=$(printf '%s' "$BASE_URL" | sed 's:/*$::')

check_up
check_discord
check_home
check_events_json

printf '\n%d passed, %d failed\n' "$pass_count" "$fail_count"
[ "$fail_count" -eq 0 ]
