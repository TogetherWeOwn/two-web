#!/bin/sh
# uptime-ping.sh — cron-friendly poller for the /up health probe (TOG-8727).
#
# No paid service, no new vendor: it curls the existing /up endpoint
# (routes/health.php, App\Http\Controllers\HealthCheckController) and exits
# nonzero when the app is down, so cron mail is the pager.
#
# Usage:
#   bin/uptime-ping.sh https://togetherweown.com [--timeout 20] [--verbose]
#
# Exit codes:
#   0  UP   — /up answered 200 with {"status":"ok",...}
#   1  DOWN — anything else (connection refused, timeout, non-200, 503 with
#             the db/pending signal, Access challenge without a token)
#   2  usage error (no URL, curl missing)
#
# Output, cron-mail friendly: NOTHING on UP by default, one line on DOWN:
#   DOWN https://host/up http=503 db=ok pending=3
#   DOWN https://host/up http=000 (curl exit 7: connection refused)
# Stock cron mails on ANY job output regardless of exit code, so the UP path
# must stay silent — a printed `UP ...` every run would page the on-call every
# 5 minutes forever and a real DOWN would read no louder than the noise.
# Pass --verbose for the UP one-liner when running by hand:
#   UP https://host/up (db=ok pending=0 time=0.21s)
#
# Staging sits behind Cloudflare Access: export CF_ACCESS_CLIENT_ID and
# CF_ACCESS_CLIENT_SECRET (a service token, never committed) exactly as
# bin/smoke-staging.sh does, or the edge challenge reads as DOWN.
#
# Cron (on the box, owned by DevOps — see docs/runbook.md "Uptime ping"):
#   MAILTO=devops@example.com
#   */5 * * * * /var/www/two-web/bin/uptime-ping.sh https://togetherweown.com
# No mail is UP, and a DOWN mail is a page. That is the whole dead-man switch:
# no mail, no news.
#
# TOG-8727.
set -u

BASE_URL=""
TIMEOUT=20
SELFTEST=0
VERBOSE=0

while [ $# -gt 0 ]; do
    case "$1" in
        --timeout=*) TIMEOUT="${1#--timeout=}"; shift ;;
        --timeout)
            if [ $# -lt 2 ]; then echo "--timeout needs a value in seconds" >&2; exit 2; fi
            TIMEOUT="$2"; shift 2 ;;
        --verbose) VERBOSE=1; shift ;;
        --selftest) SELFTEST=1; shift ;;
        -h|--help) sed -n '2,36p' "$0"; exit 0 ;;
        --*) echo "unknown option: $1" >&2; exit 2 ;;
        *) if [ -z "$BASE_URL" ]; then BASE_URL="$1"; else echo "unexpected argument: $1" >&2; exit 2; fi; shift ;;
    esac
done

ping() {
    # GET /up with the Access service-token headers when set (staging).
    # Sets globals PING_CODE, PING_BODY, PING_TIME, PING_CURL_RC.
    url="$1"
    set -- "$url"
    if [ -n "${CF_ACCESS_CLIENT_ID:-}" ] && [ -n "${CF_ACCESS_CLIENT_SECRET:-}" ]; then
        set -- -H "CF-Access-Client-Id: $CF_ACCESS_CLIENT_ID" \
               -H "CF-Access-Client-Secret: $CF_ACCESS_CLIENT_SECRET" "$@"
    fi
    # Trap-guarded: a killed run removes the temp file instead of leaking
    # /tmp files. The trap is set once at top level (dash has no `trap -p`
    # to save/restore with, so save/restore is not portable here); PING_TMP
    # names the live file and is cleared after each poll.
    PING_TMP=$(mktemp)
    PING_CURL_RC=0
    PING_CODE=$(curl -s -o "$PING_TMP" -w '%{http_code} %{time_total}' \
        --max-time "$TIMEOUT" "$@" </dev/null 2>/dev/null) || PING_CURL_RC=$?
    PING_TIME="${PING_CODE#* }"
    PING_CODE="${PING_CODE%% *}"
    PING_BODY=$(cat "$PING_TMP")
    rm -f "$PING_TMP"
    PING_TMP=""
}

# json_field <body> <key> — prints the scalar value of a top-level JSON string
# or number field, or nothing. grep/sed only: no jq on the cron box.
json_field() {
    printf '%s' "$1" | sed -n "s/.*\"$2\"[ ]*:[ ]*\"\{0,1\}\([-a-zA-Z0-9.]*\)\"\{0,1\}.*/\1/p" | head -n 1
}

selftest() {
    # Offline self-test: stub /up variants, run the real poller against them.
    stub=$(mktemp -d)
    cat > "$stub/stub.py" <<'EOF'
from http.server import BaseHTTPRequestHandler, HTTPServer
import sys

MODE = sys.argv[1]

class H(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path != '/up':
            self.send_response(404); self.end_headers(); return
        if MODE == 'ok':
            body = b'{"status":"ok","db":"ok","pending_migrations":0}'
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.end_headers(); self.wfile.write(body)
        elif MODE == 'db-down':
            body = b'{"status":"error","db":"error","pending_migrations":null}'
            self.send_response(503)
            self.send_header('Content-Type', 'application/json')
            self.end_headers(); self.wfile.write(body)
        elif MODE == 'pending':
            body = b'{"status":"error","db":"ok","pending_migrations":3}'
            self.send_response(503)
            self.send_header('Content-Type', 'application/json')
            self.end_headers(); self.wfile.write(body)
        elif MODE == 'gated':
            self.send_response(302)
            self.send_header('Location', 'https://example.cloudflareaccess.com/')
            self.end_headers()
    def log_message(self, *a):
        pass

HTTPServer(('127.0.0.1', 18732), H).serve_forever()
EOF
    failures=0
    # case <mode|down> <expect_rc> <expect_word> — "down" kills the stub first.
    run_case() {
        mode="$1"; expect_rc="$2"; expect_word="$3"
        if [ "$mode" = "down" ]; then
            out=""; rc=0
            sh "$0" "http://127.0.0.1:18739" --timeout 2 >"$stub/out.txt" 2>&1 || rc=$?
            out=$(cat "$stub/out.txt")
        else
            python3 "$stub/stub.py" "$mode" &
            server_pid=$!
            sleep 1
            out=""; rc=0
            sh "$0" "http://127.0.0.1:18732" >"$stub/out.txt" 2>&1 || rc=$?
            out=$(cat "$stub/out.txt")
            kill "$server_pid" 2>/dev/null
            wait "$server_pid" 2>/dev/null
        fi
        case "$out" in
            *"$expect_word"*)
                if [ "$rc" -eq "$expect_rc" ]; then
                    echo "PASS  $mode -> rc=$rc $out"
                else
                    echo "FAIL  $mode -> rc=$rc (expected $expect_rc) $out"
                    failures=$((failures + 1))
                fi
                ;;
            *)
                echo "FAIL  $mode -> missing '$expect_word' in: $out (rc=$rc)"
                failures=$((failures + 1))
                ;;
        esac
    }
    run_case db-down 1 "DOWN"
    run_case pending 1 "pending=3"
    run_case gated 1 "DOWN"
    run_case down 1 "DOWN"
    # UP is SILENT by default: cron mails any output, so an UP line would page
    # every run. Pin both directions: no output without --verbose, the one-liner
    # with it.
    python3 "$stub/stub.py" ok &
    server_pid=$!
    sleep 1
    out=""; rc=0
    sh "$0" "http://127.0.0.1:18732" >"$stub/out.txt" 2>&1 || rc=$?
    out=$(cat "$stub/out.txt")
    if [ "$rc" -eq 0 ] && [ -z "$out" ]; then
        echo "PASS  ok -> rc=0, silent (cron sends no mail)"
    else
        echo "FAIL  ok -> rc=$rc out='$out' (expected rc=0, empty)"
        failures=$((failures + 1))
    fi
    out=""; rc=0
    sh "$0" "http://127.0.0.1:18732" --verbose >"$stub/out.txt" 2>&1 || rc=$?
    out=$(cat "$stub/out.txt")
    case "$out" in
        *"UP http://127.0.0.1:18732/up"*)
            if [ "$rc" -eq 0 ]; then echo "PASS  ok --verbose -> $out"; else echo "FAIL  ok --verbose -> rc=$rc"; failures=$((failures + 1)); fi ;;
        *) echo "FAIL  ok --verbose -> missing UP line: '$out'"; failures=$((failures + 1)) ;;
    esac
    kill "$server_pid" 2>/dev/null
    wait "$server_pid" 2>/dev/null
    # usage: no URL must be exit 2, not a DOWN page.
    out=""; rc=0
    sh "$0" >"$stub/out.txt" 2>&1 || rc=$?
    if [ "$rc" -eq 2 ]; then echo "PASS  usage -> rc=2"; else echo "FAIL  usage -> rc=$rc"; failures=$((failures + 1)); fi
    # bare --timeout with no value must be a clean usage error, not a shift crash.
    out=""; rc=0
    sh "$0" "http://127.0.0.1:18732" --timeout >"$stub/out.txt" 2>&1 || rc=$?
    out=$(cat "$stub/out.txt")
    case "$out" in
        *"shift"*) echo "FAIL  bare --timeout leaked a shell error: '$out'"; failures=$((failures + 1)) ;;
        *) if [ "$rc" -eq 2 ]; then echo "PASS  bare --timeout -> rc=2 '$out'"; else echo "FAIL  bare --timeout -> rc=$rc"; failures=$((failures + 1)); fi ;;
    esac
    rm -rf "$stub"
    if [ "$failures" -eq 0 ]; then echo "SELFTEST PASS"; return 0; else echo "SELFTEST FAIL ($failures)"; return 1; fi
}

# Top-level guard for the poll's temp file (dash-portable: no `trap -p`).
# PING_TMP is empty except inside ping(); a kill then removes nothing or the
# live file, never a stale one.
PING_TMP=""
trap 'if [ -n "${PING_TMP:-}" ]; then rm -f "$PING_TMP"; fi' EXIT INT TERM

if [ "$SELFTEST" -eq 1 ]; then
    selftest
    exit $?
fi

if [ -z "$BASE_URL" ]; then
    echo "usage: $0 BASE_URL [--timeout SECONDS] [--verbose] [--selftest]" >&2
    exit 2
fi
case "$TIMEOUT" in
    ''|*[!0-9]*) echo "timeout must be seconds, got '$TIMEOUT'" >&2; exit 2 ;;
esac
if ! command -v curl >/dev/null 2>&1; then
    echo "curl is required" >&2
    exit 2
fi

BASE_URL=$(printf '%s' "$BASE_URL" | sed 's:/*$::')
TARGET="$BASE_URL/up"

ping "$TARGET"

if [ "$PING_CURL_RC" -ne 0 ]; then
    printf 'DOWN %s http=000 (curl exit %s: connection refused or timeout after %ss)\n' \
        "$TARGET" "$PING_CURL_RC" "$TIMEOUT"
    exit 1
fi

status=$(json_field "$PING_BODY" status)
db=$(json_field "$PING_BODY" db)
pending=$(json_field "$PING_BODY" pending_migrations)

if [ "$PING_CODE" = "200" ] && [ "$status" = "ok" ]; then
    # Silent on UP: cron mails ANY output, so only --verbose prints.
    if [ "$VERBOSE" -eq 1 ]; then
        printf 'UP %s (db=%s pending=%s time=%ss)\n' "$TARGET" "${db:-?}" "${pending:-?}" "$PING_TIME"
    fi
    exit 0
fi

# Anything else is DOWN, with the probe's own signal when it answered.
case "$PING_BODY" in
    *cloudflareaccess.com*)
        printf 'DOWN %s http=%s (Cloudflare Access challenge; staging is gated, not the app — export the service token)\n' \
            "$TARGET" "$PING_CODE"
        ;;
    *)
        printf 'DOWN %s http=%s db=%s pending=%s\n' \
            "$TARGET" "$PING_CODE" "${db:-?}" "${pending:-?}"
        ;;
esac
exit 1
