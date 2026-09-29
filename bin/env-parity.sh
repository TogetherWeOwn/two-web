#!/bin/sh
# env-parity.sh — key-only staging-vs-prod env parity check for TWO Web.
#
# Compares the KEY NAMES present in two dotenv-format snapshots (e.g. exports
# of the Coolify env config for staging and prod) against the required set
# derived from `.env.example` plus the supplement below from `docs/env.md`.
#
# Key-only means key-only: the script extracts names left of the first `=` and
# drops values in the same pipeline. No value is ever assigned to a variable,
# echoed, or written anywhere — a snapshot full of live secrets produces the
# same output as one full of placeholders. `docs/env.md` §14 is the runbook
# for resolving whatever this reports.
#
# Snapshots are files you create outside the repo from the Coolify dashboard
# (or `coolify` env export) and never commit. Real values must not land in git.
#
# Usage:
#   bin/env-parity.sh STAGING_ENV PROD_ENV [--example PATH] [--selftest]
#
# Exit codes (same contract shape as ci/deploy-target.sh):
#   0  no drift — every required key is present where it must be
#   1  drift found — see the MISSING / PROD-HAS-STAGING-ONLY / FORBIDDEN /
#      UNKNOWN lines; fix per docs/env.md §14
#   2  called wrongly (missing/unreadable file, bad flag)
#
# TOG-6949.
set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
EXAMPLE="$ROOT/.env.example"

# Keys documented in docs/env.md but intentionally absent from `.env.example`.
# Keep in sync with docs/env.md §§ 1–13 when that document gains a key.
# Presence is fine, absence is fine — these only stop a key being flagged UNKNOWN.
KNOWN_OPTIONAL="APP_PREVIOUS_KEYS APP_MAINTENANCE_DRIVER APP_MAINTENANCE_STORE \
AUTH_GUARD AUTH_PASSWORD_BROKER AUTH_MODEL AUTH_PASSWORD_RESET_TOKEN_TABLE AUTH_PASSWORD_TIMEOUT \
LOG_DAILY_DAYS LOG_DEPRECATIONS_CHANNEL LOG_DEPRECATIONS_TRACE LOG_SLACK_WEBHOOK_URL LOG_SLACK_USERNAME \
LOG_SLACK_EMOJI LOG_PAPERTRAIL_HANDLER PAPERTRAIL_URL PAPERTRAIL_PORT LOG_STDERR_FORMATTER LOG_SYSLOG_FACILITY \
DB_SSLMODE DB_CACHE_CONNECTION DB_CACHE_TABLE DB_CACHE_LOCK_CONNECTION DB_CACHE_LOCK_TABLE \
DB_QUEUE_CONNECTION DB_QUEUE_TABLE DB_QUEUE DB_QUEUE_RETRY_AFTER QUEUE_FAILED_DRIVER \
SESSION_HTTP_ONLY SESSION_EXPIRE_ON_CLOSE SESSION_PARTITIONED_COOKIE SESSION_CONNECTION SESSION_STORE SESSION_TABLE \
CACHE_PREFIX MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_FROM_ADDRESS MAIL_FROM_NAME \
MAIL_SCHEME MAIL_URL MAIL_EHLO_DOMAIN MAIL_SENDMAIL_PATH MAIL_LOG_CHANNEL POSTMARK_MESSAGE_STREAM_ID \
DISCORD_API_BASE DISCORD_TIMEOUT_SECONDS BOT_DB_TIMEOUT CI \
PAPERCLIP_API_URL PAPERCLIP_API_TOKEN PAPERCLIP_COMPANY_ID PAPERCLIP_API_TIMEOUT_SECONDS \
PAPERCLIP_OPERATOR_LABEL_ID PAPERCLIP_PARENT_ISSUE_ID PAPERCLIP_RESTART_ASSIGNEE_AGENT_ID PAPERCLIP_RESTART_BOT_ENVIRONMENT"

# In .env.example but must NOT be required in both snapshots:
# TWO_WEB_STAGING_QA_AUTH_TOKEN is staging-only (env.md §10); DUSK_DRIVER_URL
# is local/test-only (env.md §12).
STAGING_ONLY="TWO_WEB_STAGING_QA_AUTH_TOKEN"
LOCAL_ONLY="DUSK_DRIVER_URL"

# Must appear in neither snapshot: test seams that would let anyone sign in
# as anyone outside tests (env.md §12).
FORBIDDEN_EVERYWHERE="DUSK_TEST_SEAMS DUSK_DISCORD_PROVIDER_URL"

# Values are never compared, but these keys' values are EXPECTED to differ per
# environment — listed so a reviewer knows the silence is deliberate.
EXPECTED_DIFFER="APP_ENV APP_URL APP_KEY DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD \
BOT_ENDPOINT_URL BOT_KEY_ID BOT_SHARED_SECRET BOT_DB_HOST BOT_DB_PORT BOT_DB_DATABASE BOT_DB_USERNAME BOT_DB_PASSWORD \
PAPERCLIP_RESTART_BOT_ENVIRONMENT TWO_WEB_STAGING_QA_AUTH_TOKEN"

STAGING_FILE=""
PROD_FILE=""
SELFTEST=0

while [ $# -gt 0 ]; do
    case "$1" in
        --example) EXAMPLE="${2:-}"; shift 2 ;;
        --example=*) EXAMPLE="${1#--example=}"; shift ;;
        --selftest) SELFTEST=1; shift ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        --*) echo "env-parity: unknown option: $1" >&2; exit 2 ;;
        *) if [ -z "$STAGING_FILE" ]; then STAGING_FILE="$1";
           elif [ -z "$PROD_FILE" ]; then PROD_FILE="$1";
           else echo "env-parity: unexpected argument: $1" >&2; exit 2; fi; shift ;;
    esac
done

# keys_of <file> — print sorted unique KEY names only. Values are cut in the
# pipeline and never touch a variable: `cut -d= -f1` keeps the name, drops the
# rest. `export KEY=...` prefixes and leading whitespace are tolerated.
keys_of() {
    sed -e 's/^[[:space:]]*export[[:space:]][[:space:]]*//' -e 's/^[[:space:]]*//' "$1" \
        | grep -E '^[A-Za-z_][A-Za-z0-9_]*=' \
        | cut -d= -f1 \
        | sort -u
}

in_list() {
    # in_list <needle> <haystack...> — true when needle is one of haystack words
    needle="$1"; shift
    for w in $*; do
        if [ "$needle" = "$w" ]; then return 0; fi
    done
    return 1
}

drift=0
report() { printf '%s\n' "$1"; drift=1; }

check_pair() {
    # check_pair <staging-keys-file> <prod-keys-file>
    sk="$1"; pk="$2"

    # Required = example keys minus the staging-only / local-only exceptions.
    required_count=0
    for k in $(keys_of "$EXAMPLE"); do
        if in_list "$k" $STAGING_ONLY $LOCAL_ONLY; then continue; fi
        required_count=$((required_count + 1))
        if ! grep -qxF "$k" "$sk"; then report "MISSING staging: $k"; fi
        if ! grep -qxF "$k" "$pk"; then report "MISSING prod: $k"; fi
    done

    # Staging-only key: must be set on staging, must NOT exist on prod.
    for k in $STAGING_ONLY; do
        if ! grep -qxF "$k" "$sk"; then report "MISSING staging: $k (staging-only; provision via secret controls)"; fi
        if grep -qxF "$k" "$pk"; then report "PROD-HAS-STAGING-ONLY $k (remove from prod, then rotate — it existed where it must not)"; fi
    done

    # Test seams: forbidden in both snapshots.
    for k in $FORBIDDEN_EVERYWHERE; do
        if grep -qxF "$k" "$sk"; then report "FORBIDDEN staging: $k (test seam; must never leave tests)"; fi
        if grep -qxF "$k" "$pk"; then report "FORBIDDEN prod: $k (test seam; must never leave tests)"; fi
    done

    # Unknown keys: in a snapshot but in neither the example nor the supplement.
    for k in $(cat "$sk" "$pk" | sort -u); do
        if in_list "$k" $FORBIDDEN_EVERYWHERE; then continue; fi
        if grep -qxF "$k" "$EXAMPLE_KEYS_TMP"; then continue; fi
        if in_list "$k" $KNOWN_OPTIONAL; then continue; fi
        where="staging"
        if ! grep -qxF "$k" "$sk"; then where="prod"; fi
        if grep -qxF "$k" "$sk" && grep -qxF "$k" "$pk"; then where="staging+prod"; fi
        report "UNKNOWN $where: $k (not in .env.example nor docs/env.md — document it or remove it)"
    done

    printf 'checked %d required keys against %s and %s\n' "$required_count" "$STAGING_FILE" "$PROD_FILE"
}

selftest() {
    # Offline self-test with fake secrets throughout: proves the exit-code
    # contract AND that no value ever reaches the output (TOG-6949 acceptance).
    work=$(mktemp -d "${TMPDIR:-/tmp}/env-parity-selftest.XXXXXX")
    # shellcheck disable=SC2064
    trap "rm -rf '$work'" EXIT
    fails=0
    tpass() { printf 'PASS  %s\n' "$1"; }
    tfail() { printf 'FAIL  %s (%s)\n' "$1" "$2" >&2; fails=$((fails + 1)); }

    mkex() { printf '%s\n' "APP_KEY=" "APP_ENV=local" "TWO_WEB_STAGING_QA_AUTH_TOKEN=" "DUSK_DRIVER_URL=http://localhost:9515" > "$work/example"; }
    leakcheck() {
        # leakcheck <name> <output-file> — none of the fixture values may appear
        if grep -qF 'sekrit-VALUE-9z9' "$1" || grep -qF 'prod-only-VALUE-8y8' "$1"; then
            tfail "$2 leaks a value" "secret string found in output"; return 1
        fi
        tpass "$2 keeps values out of output"
    }

    mkex
    printf '%s\n' "APP_KEY=sekrit-VALUE-9z9" "APP_ENV=staging" "TWO_WEB_STAGING_QA_AUTH_TOKEN=sekrit-VALUE-9z9" > "$work/staging.env"
    printf '%s\n' "APP_KEY=prod-only-VALUE-8y8" "APP_ENV=production" > "$work/prod.env"
    out=$("$0" --example "$work/example" "$work/staging.env" "$work/prod.env" 2>&1); rc=$?
    if [ "$rc" -eq 0 ]; then tpass "clean pair exits 0"; else tfail "clean pair exits 0" "rc=$rc out: $out"; fi
    printf '%s\n' "$out" > "$work/selftest-clean.out"; leakcheck "$work/selftest-clean.out" "clean pair"

    printf '%s\n' "APP_KEY=sekrit-VALUE-9z9" "APP_ENV=staging" "TWO_WEB_STAGING_QA_AUTH_TOKEN=sekrit-VALUE-9z9" > "$work/staging.env"
    printf '%s\n' "APP_ENV=production" > "$work/prod.env"
    out=$("$0" --example "$work/example" "$work/staging.env" "$work/prod.env" 2>&1); rc=$?
    if [ "$rc" -eq 1 ] && printf '%s\n' "$out" | grep -qF 'MISSING prod: APP_KEY'; then
        tpass "missing key exits 1 and names the key"
    else tfail "missing key exits 1 and names the key" "rc=$rc out: $out"; fi
    printf '%s\n' "$out" > "$work/o2.out"; leakcheck "$work/o2.out" "missing-key case"

    printf '%s\n' "APP_KEY=sekrit-VALUE-9z9" "APP_ENV=staging" "TWO_WEB_STAGING_QA_AUTH_TOKEN=sekrit-VALUE-9z9" > "$work/staging.env"
    printf '%s\n' "APP_KEY=prod-only-VALUE-8y8" "APP_ENV=production" "TWO_WEB_STAGING_QA_AUTH_TOKEN=prod-only-VALUE-8y8" > "$work/prod.env"
    out=$("$0" --example "$work/example" "$work/staging.env" "$work/prod.env" 2>&1); rc=$?
    if [ "$rc" -eq 1 ] && printf '%s\n' "$out" | grep -qF 'PROD-HAS-STAGING-ONLY TWO_WEB_STAGING_QA_AUTH_TOKEN'; then
        tpass "staging-only key in prod exits 1 and names the key"
    else tfail "staging-only key in prod exits 1 and names the key" "rc=$rc out: $out"; fi
    printf '%s\n' "$out" > "$work/o3.out"; leakcheck "$work/o3.out" "staging-only-in-prod case"

    printf '%s\n' "APP_KEY=sekrit-VALUE-9z9" "APP_ENV=staging" "TWO_WEB_STAGING_QA_AUTH_TOKEN=sekrit-VALUE-9z9" "DUSK_TEST_SEAMS=sekrit-VALUE-9z9" > "$work/staging.env"
    printf '%s\n' "APP_KEY=prod-only-VALUE-8y8" "APP_ENV=production" > "$work/prod.env"
    out=$("$0" --example "$work/example" "$work/staging.env" "$work/prod.env" 2>&1); rc=$?
    if [ "$rc" -eq 1 ] && printf '%s\n' "$out" | grep -qF 'FORBIDDEN staging: DUSK_TEST_SEAMS'; then
        tpass "test seam in staging exits 1 and names the key"
    else tfail "test seam in staging exits 1 and names the key" "rc=$rc out: $out"; fi
    printf '%s\n' "$out" > "$work/o4.out"; leakcheck "$work/o4.out" "test-seam case"

    printf '%s\n' "APP_KEY=sekrit-VALUE-9z9" "APP_ENV=staging" "TWO_WEB_STAGING_QA_AUTH_TOKEN=sekrit-VALUE-9z9" "MYSTERY_FLAG=sekrit-VALUE-9z9" > "$work/staging.env"
    printf '%s\n' "APP_KEY=prod-only-VALUE-8y8" "APP_ENV=production" > "$work/prod.env"
    out=$("$0" --example "$work/example" "$work/staging.env" "$work/prod.env" 2>&1); rc=$?
    if [ "$rc" -eq 1 ] && printf '%s\n' "$out" | grep -qF 'UNKNOWN staging: MYSTERY_FLAG'; then
        tpass "unknown key exits 1 and names the key"
    else tfail "unknown key exits 1 and names the key" "rc=$rc out: $out"; fi
    printf '%s\n' "$out" > "$work/o5.out"; leakcheck "$work/o5.out" "unknown-key case"

    if [ "$fails" -eq 0 ]; then echo "SELFTEST PASS"; return 0;
    else echo "SELFTEST FAIL ($fails)" >&2; return 1; fi
}

if [ "$SELFTEST" -eq 1 ]; then
    selftest
    exit $?
fi

if [ -z "$STAGING_FILE" ] || [ -z "$PROD_FILE" ]; then
    echo "usage: $0 STAGING_ENV PROD_ENV [--example PATH] [--selftest]" >&2
    exit 2
fi
for f in "$EXAMPLE" "$STAGING_FILE" "$PROD_FILE"; do
    if [ ! -r "$f" ]; then echo "env-parity: cannot read: $f" >&2; exit 2; fi
done

EXAMPLE_KEYS_TMP=$(mktemp "${TMPDIR:-/tmp}/env-parity-example.XXXXXX")
STAGING_KEYS_TMP=$(mktemp "${TMPDIR:-/tmp}/env-parity-staging.XXXXXX")
PROD_KEYS_TMP=$(mktemp "${TMPDIR:-/tmp}/env-parity-prod.XXXXXX")
# shellcheck disable=SC2064
trap "rm -f '$EXAMPLE_KEYS_TMP' '$STAGING_KEYS_TMP' '$PROD_KEYS_TMP'" EXIT

keys_of "$EXAMPLE" > "$EXAMPLE_KEYS_TMP"
keys_of "$STAGING_FILE" > "$STAGING_KEYS_TMP"
keys_of "$PROD_FILE" > "$PROD_KEYS_TMP"

printf 'env-parity staging=%s prod=%s example=%s\n' "$STAGING_FILE" "$PROD_FILE" "$EXAMPLE"
check_pair "$STAGING_KEYS_TMP" "$PROD_KEYS_TMP"

if [ "$drift" -eq 0 ]; then
    echo "PARITY OK — values never compared (expected to differ: $EXPECTED_DIFFER)"
    exit 0
else
    echo "PARITY DRIFT — resolve per docs/env.md §14 (no values shown above, by design)"
    exit 1
fi
