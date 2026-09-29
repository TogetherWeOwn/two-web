#!/bin/sh
# error-log-watch.sh — cron-friendly watcher for the log-based error alert (TOG-8730).
#
# No paid service, no new vendor: it greps the Laravel log for the alert lines
# the application itself emits — `Unhandled exception.` (bootstrap/app.php) and
# `Queue job failed.` (AppServiceProvider) — and exits nonzero when one landed
# since the last run, so cron mail is the pager. Same tradition as
# bin/uptime-ping.sh (TOG-8727): silence is UP, output is a page.
#
# Usage:
#   bin/error-log-watch.sh [--log PATH] [--state PATH] [--verbose] [--selftest]
#
# Exit codes:
#   0  QUIET — no new alert lines since the last run (prints nothing)
#   1  ALERT — at least one new alert line (prints the lines, newest first
#              capped at 20, plus the count)
#   2  usage error (missing log, curl-style misuse)
#
# Output, cron-mail friendly: NOTHING on QUIET by default, the alert lines on
# ALERT. Stock cron mails on ANY job output regardless of exit code, so a
# chatty QUIET would page the on-call every 5 minutes forever. Pass --verbose
# for the QUIET one-liner when running by hand.
#
# State: the byte offset of the last read, in a state file (default
# `storage/logs/.error-log-watch.offset` next to the log). Only the delta is
# scanned, so a crashing deploy produces one mail per cron tick, not the whole
# log every tick. A rotated or truncated log (offset past EOF) re-reads from
# the top rather than going blind — the price is one duplicate mail after a
# rotation, which is the right way round for a pager.
#
# Cron (on the box, owned by DevOps — see docs/runbook.md "Error alerting"):
#   MAILTO=devops@example.com
#   */5 * * * * /var/www/two-web/bin/error-log-watch.sh
# No mail is QUIET, and an ALERT mail is a page.
#
# (Use the real on-call address for MAILTO, set in the cron environment on
# the box — never in the repo.)
#
# TOG-8730.
set -u

LOG_PATH="storage/logs/laravel.log"
STATE_PATH=""
SELFTEST=0
VERBOSE=0

# Portable script-dir resolution: the defaults are relative to the repo root,
# wherever the cron box checked it out.
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

while [ $# -gt 0 ]; do
    case "$1" in
        --log=*) LOG_PATH="${1#--log=}"; shift ;;
        --log)
            if [ $# -lt 2 ]; then echo "--log needs a path" >&2; exit 2; fi
            LOG_PATH="$2"; shift 2 ;;
        --state=*) STATE_PATH="${1#--state=}"; shift ;;
        --state)
            if [ $# -lt 2 ]; then echo "--state needs a path" >&2; exit 2; fi
            STATE_PATH="$2"; shift 2 ;;
        --verbose) VERBOSE=1; shift ;;
        --selftest) SELFTEST=1; shift ;;
        -h|--help) sed -n '2,40p' "$0"; exit 0 ;;
        --*) echo "unknown option: $1" >&2; exit 2 ;;
        *) echo "unexpected argument: $1" >&2; exit 2 ;;
    esac
done

case "$LOG_PATH" in
    /*) ;;
    *) LOG_PATH="$REPO_ROOT/$LOG_PATH" ;;
esac

if [ -z "$STATE_PATH" ]; then
    STATE_PATH="$LOG_PATH.watch-offset"
fi
case "$STATE_PATH" in
    /*) ;;
    *) STATE_PATH="$REPO_ROOT/$STATE_PATH" ;;
esac

watch() {
    if [ ! -r "$LOG_PATH" ]; then
        echo "ALERT error-log-watch cannot read log: $LOG_PATH" >&2
        return 1
    fi

    # The alert lines, in the application's own words. `Unhandled exception.`
    # is the in-app alert from bootstrap/app.php (TOG-8730); `Queue job
    # failed.` is the failed-job half from AppServiceProvider (TOG-6948).
    # Fixed strings (-F), not regexes: a dot that means "any character"
    # would match log lines that are not alerts.
    log_size=$(wc -c < "$LOG_PATH")
    offset=0
    if [ -r "$STATE_PATH" ]; then
        offset=$(cat "$STATE_PATH" 2>/dev/null)
        case "$offset" in
            ''|*[!0-9]*) offset=0 ;;
        esac
    fi

    # Rotated or truncated since the last run: re-read from the top. One
    # duplicate mail beats a pager that goes blind after every rotation.
    if [ "$offset" -gt "$log_size" ]; then
        offset=0
    fi

    # The delta, plus one overlapping byte: an alert line split across the
    # offset boundary still matches. The overlap can duplicate one line into
    # the next mail at most — again the right way round for a pager.
    start=$offset
    if [ "$start" -gt 0 ]; then
        start=$((start - 1))
    fi

    alerts=$(tail -c +"$((start + 1))" "$LOG_PATH" | grep -a -F -e 'Unhandled exception.' -e 'Queue job failed.' || true)

    # Advance the offset even when the delta held an alert: the mail carries
    # the lines, and re-mailing them every tick until somebody clears the
    # log is noise, not persistence. The next *new* alert still pages.
    printf '%s' "$log_size" > "$STATE_PATH"

    if [ -z "$alerts" ]; then
        if [ "$VERBOSE" -eq 1 ]; then
            echo "QUIET $LOG_PATH (no new alerts, offset=$log_size)"
        fi
        return 0
    fi

    count=$(printf '%s\n' "$alerts" | grep -c .)
    echo "ALERT $LOG_PATH ($count new alert line(s)):"
    printf '%s\n' "$alerts" | tail -n 20
    return 1
}

selftest() {
    # Offline self-test: fixture logs in a temp dir, the real watcher against
    # them. Same pattern as bin/uptime-ping.sh --selftest.
    stub=$(mktemp -d)
    failures=0

    run_case() {
        name="$1"; expect_rc="$2"; expect_word="$3"
        out=""; rc=0
        sh "$0" --log "$stub/$name.log" --state "$stub/$name.offset" >"$stub/out.txt" 2>&1 || rc=$?
        out=$(cat "$stub/out.txt")
        case "$out" in
            *"$expect_word"*)
                if [ "$rc" -eq "$expect_rc" ]; then
                    echo "PASS  $name -> rc=$rc $expect_word"
                else
                    echo "FAIL  $name -> rc=$rc (expected $expect_rc) out='$out'"
                    failures=$((failures + 1))
                fi
                ;;
            *)
                echo "FAIL  $name -> missing '$expect_word' in: '$out' (rc=$rc)"
                failures=$((failures + 1))
                ;;
        esac
    }

    # quiet: no alerts at all — silent, rc 0. Pin both directions: no output
    # without --verbose (cron mails any output), the one-liner with it.
    printf '%s\n' '[2026-09-29 10:00:00] testing.INFO: booted.' > "$stub/quiet.log"
    run_case quiet 0 ""
    out=""; rc=0
    sh "$0" --log "$stub/quiet.log" --state "$stub/quiet.offset" --verbose >"$stub/out.txt" 2>&1 || rc=$?
    out=$(cat "$stub/out.txt")
    case "$out" in
        *"QUIET"*)
            if [ "$rc" -eq 0 ]; then echo "PASS  quiet --verbose -> $out"; else echo "FAIL  quiet --verbose -> rc=$rc"; failures=$((failures + 1)); fi ;;
        *) echo "FAIL  quiet --verbose -> missing QUIET: '$out'"; failures=$((failures + 1)) ;;
    esac

    # fires: one alert line pages with the line attached.
    printf '%s\n' '[2026-09-29 10:01:00] testing.CRITICAL: Unhandled exception. {"exception":"RuntimeException"}' > "$stub/fires.log"
    run_case fires 1 "Unhandled exception."

    # queue-half: the failed-job alert from TOG-6948 pages through the same path.
    printf '%s\n' '[2026-09-29 10:02:00] testing.CRITICAL: Queue job failed. {"job":"App\\Jobs\\SyncEventToDiscord"}' > "$stub/queue-half.log"
    run_case queue-half 1 "Queue job failed."

    # second-run-quiet: the offset advances, so the same lines do not re-page.
    out=""; rc=0
    sh "$0" --log "$stub/fires.log" --state "$stub/fires.offset" >"$stub/out.txt" 2>&1 || rc=$?
    out=$(cat "$stub/out.txt")
    if [ "$rc" -eq 0 ] && [ -z "$out" ]; then
        echo "PASS  second-run-quiet -> rc=0, silent"
    else
        echo "FAIL  second-run-quiet -> rc=$rc out='$out' (expected rc=0, empty)"
        failures=$((failures + 1))
    fi

    # rotation: offset past EOF re-reads from the top rather than going blind.
    # Seed a long log, run once to advance the offset, then truncate and
    # re-run: the stored offset is past EOF, so the watcher re-reads.
    { printf '%s\n' '[2026-09-29 10:00:00] testing.INFO: booted.';
      i=0; while [ "$i" -lt 20 ]; do printf '%s\n' '[2026-09-29 10:01:00] testing.INFO: padding line so the stored offset lands past EOF after rotation.'; i=$((i + 1)); done; } > "$stub/rotation.log"
    sh "$0" --log "$stub/rotation.log" --state "$stub/rotation.offset" >/dev/null 2>&1 || true
    printf '%s\n' '[2026-09-29 10:03:00] testing.CRITICAL: Unhandled exception. {"exception":"RuntimeException"}' > "$stub/rotation.log"
    run_case rotation 1 "Unhandled exception."

    # missing-log: an unreadable log is itself a page, not a quiet pass.
    rm -f "$stub/missing.log"
    out=""; rc=0
    sh "$0" --log "$stub/missing.log" --state "$stub/missing.offset" >"$stub/out.txt" 2>&1 || rc=$?
    out=$(cat "$stub/out.txt")
    case "$out" in
        *"cannot read log"*)
            if [ "$rc" -eq 1 ]; then echo "PASS  missing-log -> rc=1 pages"; else echo "FAIL  missing-log -> rc=$rc"; failures=$((failures + 1)); fi ;;
        *) echo "FAIL  missing-log -> missing warning: '$out'"; failures=$((failures + 1)) ;;
    esac

    # usage: unknown flag must be exit 2, not an ALERT page.
    out=""; rc=0
    sh "$0" --bogus >"$stub/out.txt" 2>&1 || rc=$?
    if [ "$rc" -eq 2 ]; then echo "PASS  usage -> rc=2"; else echo "FAIL  usage -> rc=$rc"; failures=$((failures + 1)); fi

    rm -rf "$stub"
    if [ "$failures" -eq 0 ]; then echo "SELFTEST PASS"; return 0; else echo "SELFTEST FAIL ($failures)"; return 1; fi
}

if [ "$SELFTEST" -eq 1 ]; then
    selftest
    exit $?
fi

watch
