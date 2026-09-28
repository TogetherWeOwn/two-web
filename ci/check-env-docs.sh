#!/usr/bin/env bash
#
# Fails when `.env.example` and `docs/env.md` drift apart.
#
# `.env.example` is the source of truth for the file layout; `docs/env.md` is
# the reference for what each key means. Before TOG-7282 nothing watched the
# gap between them: a var added to one file and not the other stayed that way,
# silently, until somebody's fresh checkout or deploy broke on it.
#
# Two directions, both hard failures:
#
#   1. A key in `.env.example` with no documented entry — an undocumented var.
#   2. A key documented in `docs/env.md` that is neither in `.env.example`
#      nor in DOCUMENTED_ABSENT_BY_DESIGN below — a documented var with
#      nowhere to live.
#
# The allowlist is the deliberate part. Some documented keys must never be in
# the example: framework defaults nobody should set, test-only seams that
# would be a vulnerability anywhere else, values provisioned through secret
# controls, keys owned by the bot side, and `DISCORD_REDIRECT_URI`, which must
# not exist at all. Each group carries its reason. Documenting a new
# must-not-example var means adding it here with a reason — that second edit
# is the point, the same standard the Lighthouse budgets in
# verify-pipeline.sh are held to.
#
# Usage: ./ci/check-env-docs.sh [example-file] [docs-file]
#   Defaults: .env.example and docs/env.md at the repository root. The
#   arguments exist so the self-test can drive this against fixtures.
#
# Exits: 0 clean; 1 drift found; 2 an input is unreadable (never a verdict
#   about files nobody read — same rule as ci/verify-codeowners.sh).
#
# Proven by ci/check-env-docs-selftest.sh, which runs on every pull request.

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
EXAMPLE="${1:-$REPO_ROOT/.env.example}"
DOCS="${2:-$REPO_ROOT/docs/env.md}"

fail() { printf '\033[31mFAIL: %s\033[0m\n' "$*" >&2; }
pass() { printf '\033[32mPASS: %s\033[0m\n' "$*"; }

[ -r "$EXAMPLE" ] || { fail "$EXAMPLE is missing or unreadable — no verdict either way"; exit 2; }
[ -r "$DOCS" ] || { fail "$DOCS is missing or unreadable — no verdict either way"; exit 2; }

# Documented in docs/env.md but deliberately absent from .env.example.
DOCUMENTED_ABSENT_BY_DESIGN=(
  # Section 5, auth scaffolding: framework defaults; documented so nobody adds them.
  AUTH_GUARD AUTH_PASSWORD_BROKER AUTH_MODEL AUTH_PASSWORD_RESET_TOKEN_TABLE AUTH_PASSWORD_TIMEOUT
  # Key rotation + maintenance mode: only meaningful mid-operation, never shipped.
  APP_PREVIOUS_KEYS APP_MAINTENANCE_DRIVER APP_MAINTENANCE_STORE
  # Logging sinks: set only the sink in use; the example pins the default stack.
  LOG_DAILY_DAYS LOG_DEPRECATIONS_CHANNEL LOG_DEPRECATIONS_TRACE LOG_SLACK_WEBHOOK_URL LOG_SLACK_USERNAME LOG_SLACK_EMOJI LOG_PAPERTRAIL_HANDLER PAPERTRAIL_URL PAPERTRAIL_PORT LOG_STDERR_FORMATTER LOG_SYSLOG_FACILITY
  # Database tuning and renames: unset unless sharing a connection or renaming tables.
  DB_SSLMODE DB_CACHE_CONNECTION DB_CACHE_TABLE DB_CACHE_LOCK_CONNECTION DB_CACHE_LOCK_TABLE DB_QUEUE_CONNECTION DB_QUEUE_TABLE DB_QUEUE DB_QUEUE_RETRY_AFTER QUEUE_FAILED_DRIVER
  # Session deviations: only meaningful when leaving database sessions.
  SESSION_HTTP_ONLY SESSION_EXPIRE_ON_CLOSE SESSION_PARTITIONED_COOKIE SESSION_CONNECTION SESSION_STORE SESSION_TABLE
  # Discord client tuning + test mock: defaults live in code; prod must not point at a mock.
  DISCORD_API_BASE DISCORD_TIMEOUT_SECONDS
  # MUST NEVER EXIST: the callback is built from APP_URL; a separate var is how a
  # staging box ships telling Discord "localhost".
  DISCORD_REDIRECT_URI
  # Bot DB tuning: the degrade-fast default lives in code.
  BOT_DB_TIMEOUT
  # Paperclip restart-card integration: unset-by-design; fail-closed without these.
  PAPERCLIP_API_URL PAPERCLIP_API_TOKEN PAPERCLIP_COMPANY_ID PAPERCLIP_API_TIMEOUT_SECONDS PAPERCLIP_OPERATOR_LABEL_ID PAPERCLIP_PARENT_ISSUE_ID PAPERCLIP_RESTART_ASSIGNEE_AGENT_ID PAPERCLIP_RESTART_BOT_ENVIRONMENT
  # Test-only seams: `true` outside tests registers the fake provider and lets anyone sign in as anyone.
  DUSK_TEST_SEAMS DUSK_DISCORD_PROVIDER_URL
  # Set by the CI runner, not by humans.
  CI
  # Set only when switching the mailer off `log`; the SMTP shape is documented at the switch point.
  MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_FROM_ADDRESS MAIL_FROM_NAME MAIL_SCHEME MAIL_URL MAIL_EHLO_DOMAIN MAIL_SENDMAIL_PATH MAIL_LOG_CHANNEL POSTMARK_MESSAGE_STREAM_ID
  # Cache prefix: derived from APP_NAME unless two apps share one cache.
  CACHE_PREFIX
  # Bot side: TWO_INTERNAL_KEYS lives in the bot process; the web side only names the key id.
  TWO_INTERNAL_KEYS
)

# Backtick tokens that name env keys: UPPER_SNAKE with at least one
# underscore, or the literal CI. The underscore requirement is what keeps
# prose values (`UTC`, `Strict`, `BotEnvironment`, `task_bridge`) out of the
# set without a hand-maintained stoplist; `*` tokens (`DB_CACHE_*`) are group
# shorthands, not keys, and are excluded the same way.
KEY_PATTERN='^([A-Z][A-Z0-9]*_[A-Z0-9_]*|CI)$'

example_keys() {
  sed 's/^export //' "$EXAMPLE" \
    | grep -E '^[A-Za-z_][A-Za-z0-9_]*=' \
    | cut -d= -f1 \
    | sort -u
}

documented_keys() {
  grep -o '`[A-Za-z_][A-Za-z0-9_*]*`' "$DOCS" \
    | tr -d '`' \
    | grep -E "$KEY_PATTERN" \
    | grep -v '\*' \
    | sort -u
}

EXAMPLE_SET="$(example_keys || true)"
DOCUMENTED_SET="$(documented_keys || true)"

[ -n "$EXAMPLE_SET" ] || { fail "no keys parsed from $EXAMPLE — the parser is broken, not the file"; exit 2; }
[ -n "$DOCUMENTED_SET" ] || { fail "no keys parsed from $DOCS — the parser is broken, not the file"; exit 2; }

allowlisted() { grep -qxF -- "$1" <<< "$(printf '%s\n' "${DOCUMENTED_ABSENT_BY_DESIGN[@]}")"; }

rc=0

# Direction 1: every example key must be documented.
while IFS= read -r key; do
  [ -n "$key" ] || continue
  if ! grep -qxF -- "$key" <<< "$DOCUMENTED_SET"; then
    fail "undocumented: \`$key\` is in ${EXAMPLE} but has no entry in ${DOCS}"
    rc=1
  fi
done <<< "$EXAMPLE_SET"

# Direction 2: every documented key must live in the example or the allowlist.
while IFS= read -r key; do
  [ -n "$key" ] || continue
  grep -qxF -- "$key" <<< "$EXAMPLE_SET" && continue
  allowlisted "$key" && continue
  fail "missing: \`$key\` is documented in ${DOCS} but is neither in ${EXAMPLE} nor allowlisted — add it to one of them"
  rc=1
done <<< "$DOCUMENTED_SET"

# A stale allowlist entry is a "deliberately absent" claim that is no longer true.
for key in "${DOCUMENTED_ABSENT_BY_DESIGN[@]}"; do
  if grep -qxF -- "$key" <<< "$EXAMPLE_SET"; then
    fail "stale allowlist: \`$key\` is in ${EXAMPLE} now — remove it from DOCUMENTED_ABSENT_BY_DESIGN"
    rc=1
  fi
done

if [ "$rc" -ne 0 ]; then
  exit 1
fi

example_count="$(wc -l <<< "$EXAMPLE_SET" | tr -d ' ')"
documented_count="$(wc -l <<< "$DOCUMENTED_SET" | tr -d ' ')"
pass ".env.example (${example_count} keys) and docs/env.md (${documented_count} keys) agree"
