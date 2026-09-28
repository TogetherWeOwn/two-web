#!/usr/bin/env bash
#
# Local Postgres backup, and the proof that the backup restores.
#
# Sessions, cache and the queue all live on Postgres (see README.md), so losing
# the database loses everything. The production runbook (docs/runbook.md)
# restores from custom-format dumps — and a backup with no restore test is a
# rumour. This script is the local half of that: it takes the dump the same way
# (`pg_dump -Fc`) and then proves the dump restores, into a scratch database
# that is dropped afterwards, by comparing every table's row count before and
# after.
#
# Local docker only. It refuses any database that does not look like the local
# compose one (DB_HOST outside 127.0.0.1/localhost), so it cannot become the
# thing that dumps production onto a laptop. Production restores are the
# runbook, never this script.
#
# Needs: docker (the compose `postgres` service from docker-compose.yml).
# Nothing else — pg_dump/psql run *inside* the container, so the reviewer needs
# no local Postgres client.
#
# Usage:
#   ./bin/pg-backup.sh backup [output.dump]
#     Writes backups/two-web-<UTC>.dump by default. Never leaves a partial file
#     under the final name: the dump lands in a temp file first and is moved
#     into place only on success.
#
#   ./bin/pg-backup.sh restore-proof [dump]
#     Restores into two_web_restore_proof, compares row counts table by table
#     against the live database, prints the verdict, drops the scratch database
#     (also on failure — see the trap). Defaults to the newest backups/*.dump.
#
# Connection values come from the environment first, then .env
# (DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD), falling back to the
# docker-compose.yml values when neither is set. Environment-first is the rule
# dotenv itself uses — a real environment variable is never overridden by the
# file — and it keeps this script testable without touching the repo's .env.
# The password is passed to the container with `docker compose exec -e`
# and never appears in a command line or in this script's output.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SCRATCH_DB="two_web_restore_proof"
BACKUP_DIR="backups"

usage() {
  echo "usage: $0 backup [output.dump] | $0 restore-proof [dump]" >&2
  exit 2
}

# Read one KEY from .env without sourcing it: sourcing executes whatever the
# file contains, and .env on a real box holds secrets that must not be
# reinterpreted by a backup script. Handles `KEY=value`, `KEY="quoted value"`
# and `export KEY=value`; ignores comments and blank lines.
env_get() {
  local key="$1" line val
  [ -f .env ] || return 0
  line="$(grep -E "^[[:space:]]*(export[[:space:]]+)?${key}=" .env | tail -n 1 || true)"
  [ -n "$line" ] || return 0
  val="${line#*=}"
  val="$(printf '%s' "$val" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/")"
  printf '%s' "$val"
}

# Environment first, .env second, compose defaults last. The `:-` form (not
# `:=` on an unconditional assignment) is what keeps a real environment
# variable from being overridden by the file.
DB_HOST="${DB_HOST:-$(env_get DB_HOST)}"
DB_PORT="${DB_PORT:-$(env_get DB_PORT)}"
DB_DATABASE="${DB_DATABASE:-$(env_get DB_DATABASE)}"
DB_USERNAME="${DB_USERNAME:-$(env_get DB_USERNAME)}"
DB_PASSWORD="${DB_PASSWORD:-$(env_get DB_PASSWORD)}"
: "${DB_HOST:=127.0.0.1}"
: "${DB_PORT:=5432}"
: "${DB_DATABASE:=two_web}"
: "${DB_USERNAME:=two_web}"
: "${DB_PASSWORD:=two_web}"

# Local docker only, enforced before anything connects. A DB_HOST that is not
# this machine means someone pointed .env at a shared or production server, and
# the two things this script does — dump a whole database to a laptop, drop and
# recreate databases — are exactly what must never happen there.
case "$DB_HOST" in
  127.0.0.1|localhost) ;;
  *)
    echo "pg-backup: refusing: DB_HOST is '${DB_HOST}', not this machine. This script is local-docker only; production restores are docs/runbook.md." >&2
    exit 1
    ;;
esac

command -v docker >/dev/null 2>&1 || {
  echo "pg-backup: refusing: \`docker\` is not installed. Start the compose database first: docker compose up -d" >&2
  exit 1
}

# Everything runs inside the compose container, so no host Postgres client is
# needed. -T: no TTY (stdout is the dump). The password travels in the
# environment, never on a command line: the `PGPASSWORD=...` prefix sets it
# for the docker CLI's environment only (an env assignment is not argv, so
# `ps` cannot see it) without exporting it into this shell, and `-e PGPASSWORD`
# with no `=value` forwards that value into the container. A
# `-e "PGPASSWORD=$DB_PASSWORD"` spelling would print the secret into every
# process listing for the duration of the call.
compose_exec() {
  PGPASSWORD="$DB_PASSWORD" docker compose exec -T -e PGPASSWORD postgres "$@"
}

# Cleanup for the restore proof. Runs on EXIT (success or failure) and
# removes both the scratch database and the dump copy inside the container, so
# a failed proof never leaves a stale copy of the data for the next run to
# mistake for a fresh restore. Reads globals set by cmd_restore_proof.
cleanup_proof() {
  if [ -n "${PROOF_CONTAINER:-}" ]; then
    compose_exec dropdb --if-exists -U "$DB_USERNAME" -h 127.0.0.1 -p "$DB_PORT" "$SCRATCH_DB" >/dev/null 2>&1 || true
    docker exec "$PROOF_CONTAINER" rm -f "$PROOF_DUMP_PATH" >/dev/null 2>&1 || true
  fi
}
# Print "<table> <count>" for every base table in the public schema of DB,
# ordered by name. Counts are exact (count(*)), not planner estimates: stats
# views reset on restore and would compare unequal on identical data.
table_counts() {
  local db="$1" tables t
  tables="$(compose_exec psql -U "$DB_USERNAME" -d "$db" -tAc \
    "select tablename from pg_tables where schemaname='public' order by 1;")"
  for t in $tables; do
    printf '%s %s\n' "$t" "$(compose_exec psql -U "$DB_USERNAME" -d "$db" -tAc \
      "select count(*) from \"${t}\";" | tr -d '[:space:]')"
  done
}

cmd_backup() {
  local out="${1:-}"
  if [ -z "$out" ]; then
    mkdir -p "$BACKUP_DIR"
    out="${BACKUP_DIR}/two-web-$(date -u +%Y%m%dT%H%M%SZ).dump"
  fi
  # Global, not local, on purpose: the EXIT trap fires after this function has
  # returned, when a local would be out of scope (and a fatal error under
  # `set -u`). The name is namespaced so it cannot collide with a caller.
  BACKUP_TMP="${out}.tmp.$$"
  trap 'rm -f "$BACKUP_TMP"' EXIT
  echo "pg-backup: dumping ${DB_DATABASE} (as ${DB_USERNAME}@${DB_HOST}:${DB_PORT}) ..."
  compose_exec pg_dump -U "$DB_USERNAME" -h 127.0.0.1 -p "$DB_PORT" -Fc "$DB_DATABASE" > "$BACKUP_TMP"
  # An empty file is a failed backup wearing a backup's name. pg_dump can exit
  # 0 with nothing written when the connection dies mid-stream, so check size.
  if [ ! -s "$BACKUP_TMP" ]; then
    echo "pg-backup: FAILED: the dump is empty — no backup was written." >&2
    return 1
  fi
  mv "$BACKUP_TMP" "$out"
  trap - EXIT
  BACKUP_TMP=""
  echo "pg-backup: wrote ${out} ($(wc -c < "$out" | tr -d ' ') bytes)"
}

cmd_restore_proof() {
  local dump="${1:-}"
  if [ -z "$dump" ]; then
    dump="$(ls -t "${BACKUP_DIR}"/two-web-*.dump 2>/dev/null | head -n 1 || true)"
    [ -n "$dump" ] || {
      echo "pg-backup: no dump found in ${BACKUP_DIR}/ — run '$0 backup' first." >&2
      exit 1
    }
  fi
  [ -f "$dump" ] || { echo "pg-backup: no such dump: ${dump}" >&2; exit 1; }

  # The dump file lives on the laptop, but pg_restore runs inside the
  # container, which cannot see it. So the dump is copied in with `docker cp`
  # to a scratch path — deliberately not a compose volume, so the proof needs
  # no change to docker-compose.yml (that file is the local-dev database
  # definition; a proof-only mount does not belong in it).
  local container_id in_container="/tmp/restore-proof.dump"
  container_id="$(docker compose ps -q postgres)"
  [ -n "$container_id" ] || {
    echo "pg-backup: the compose postgres service is not running (docker compose up -d)." >&2
    exit 1
  }

  echo "pg-backup: proving ${dump} restores (scratch database ${SCRATCH_DB}) ..."

  PROOF_CONTAINER="$container_id"
  PROOF_DUMP_PATH="$in_container"
  trap cleanup_proof EXIT
  compose_exec dropdb --if-exists -U "$DB_USERNAME" -h 127.0.0.1 -p "$DB_PORT" "$SCRATCH_DB" >/dev/null 2>&1 || true # from an earlier interrupted run, if any

  local abs before after
  abs="$(cd "$(dirname "$dump")" && pwd)/$(basename "$dump")"
  docker cp "$abs" "${container_id}:${in_container}"

  before="$(table_counts "$DB_DATABASE")"
  [ -n "$before" ] || { echo "pg-backup: FAILED: source database has no tables — nothing to prove against." >&2; return 1; }

  compose_exec createdb -U "$DB_USERNAME" -h 127.0.0.1 -p "$DB_PORT" "$SCRATCH_DB"
  # This exit code is the corruption check: pg_restore fails on a truncated or
  # otherwise unreadable dump, and `set -e` stops the proof right here.
  compose_exec pg_restore -U "$DB_USERNAME" -h 127.0.0.1 -p "$DB_PORT" -d "$SCRATCH_DB" --no-owner "$in_container"
  after="$(table_counts "$SCRATCH_DB")"

  if [ "$before" = "$after" ]; then
    local n
    n="$(printf '%s\n' "$before" | wc -l | tr -d ' ')"
    echo "pg-backup: PROOF OK — ${n} tables, every row count matches:"
    printf '%s\n' "$before" | sed 's/^/    /'
  else
    echo "pg-backup: PROOF FAILED — row counts differ after restore:" >&2
    diff <(printf '%s\n' "$before") <(printf '%s\n' "$after") | sed 's/^/    /' >&2 || true
    return 1
  fi
}

[ "$#" -ge 1 ] || usage
case "$1" in
  backup)        shift; cmd_backup "$@" ;;
  restore-proof) shift; cmd_restore_proof "$@" ;;
  *)             usage ;;
esac
