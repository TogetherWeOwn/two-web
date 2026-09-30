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
#     into place only on success. When BACKUP_COPY_DEST names a directory, the
#     finished dump is also copied there — the offsite copy (see
#     docs/runbook.md "Backup retention + offsite copy").
#
#   ./bin/pg-backup.sh rotate [--dry-run]
#     Prunes backups/ to the newest BACKUP_KEEP_DAILY dailies (default 7) and
#     the newest BACKUP_KEEP_WEEKLY weeklies (default 4). --dry-run prints the
#     keep:/delete: lines and deletes nothing.
#
#   ./bin/pg-backup.sh promote-weekly [dump]
#     Copies the newest daily (or the named dump) to a
#     two-web-weekly-<UTC>.dump name. Run once a week, then rotate.
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

# Retention: keep the newest BACKUP_KEEP_DAILY dailies (default 7) and the
# newest BACKUP_KEEP_WEEKLY weeklies (default 4). Weeklies are the files
# `promote-weekly` names two-web-weekly-*.dump; everything else counts as a
# daily. Environment overrides exist so the selftest can shrink the window
# without touching the production rule.
: "${BACKUP_KEEP_DAILY:=7}"
: "${BACKUP_KEEP_WEEKLY:=4}"
# Offsite copy: when BACKUP_COPY_DEST names a directory, each finished backup
# is also copied there (production: the mounted offsite volume, see
# docs/runbook.md). Empty by default — local dev keeps one copy. The value is
# a local path only, never a URL or credential: the mount itself is the
# DevOps-owned step, and anything that needs a password is not this script.
: "${BACKUP_COPY_DEST:=}"

usage() {
  echo "usage: $0 backup [output.dump] | $0 rotate [--dry-run] | $0 promote-weekly [dump] | $0 restore-proof [dump]" >&2
  exit 2
}

# Never source .env. Support the single-line subset of the pinned
# vlucas/phpdotenv parser: export/quoted names, quoted values and # comments.
# Refuse unsupported syntax rather than compare a name the app reads differently.
# In particular, validate EVERY entry before selecting a key: a DB_DATABASE-
# looking line inside another key's multiline value is not a new assignment.
# Return 1 for an absent key, 2 for an unsafe file; connecting commands preserve
# the refusal diagnostic, while rotate/promote-weekly remain file-only.
dotenv_rhs_for() {
  local key="$1" line name rhs out found=0 has_value
  local LC_ALL=C
  [ -f .env ] || return 1
  # Bash read drops NUL bytes; dotenv does not. Don't silently alter the file.
  if ! tr -d '\000' < .env | cmp -s - .env; then
    echo "pg-backup: refusing: cannot parse .env containing NUL bytes — use single-line assignments." >&2
    return 2
  fi
  while IFS= read -r line || [ -n "$line" ]; do
    line="${line%$'\r'}" # CRLF is supported; embedded CR is another entry in dotenv.
    case "$line" in
      *$'\r'*|*$'\f'*)
        echo "pg-backup: refusing: cannot parse .env containing unsupported control bytes — use single-line assignments." >&2
        return 2
        ;;
    esac
    line="$(printf '%s' "$line" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    case "$line" in ''|'#'*) continue ;; esac
    has_value=0
    case "$line" in
      *'='*) name="${line%%=*}"; rhs="${line#*=}"; has_value=1 ;;
      *) name="$line"; rhs="" ;;
    esac
    name="$(printf '%s' "$name" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    case "$name" in
      export[[:space:]]*)
        name="$(printf '%s' "$name" | sed 's/^export[[:space:]][[:space:]]*//')"
        ;;
    esac
    if [ "${#name}" -ge 3 ]; then
      case "$name" in '"'*'"'|"'"*"'") name="${name:1:${#name}-2}" ;; esac
    fi
    case "$name" in
      ''|*[!a-zA-Z0-9_.]*)
        echo "pg-backup: refusing: the .env file has a name dotenv itself rejects or this script cannot parse — use ASCII letters, digits, dots and underscores." >&2
        return 2
        ;;
    esac
    [ "$has_value" -eq 1 ] || continue # dotenv's valueless entries don't set a value.
    rhs="$(printf '%s' "$rhs" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
    if ! dotenv_unquote "$rhs" >/dev/null; then
      echo "pg-backup: refusing: cannot parse the ${name} line in .env — multiline values, double-quoted escapes and malformed values are unsupported; use a single-line literal." >&2
      return 2
    fi
    if [ "$name" = "$key" ]; then out="$rhs"; found=1; fi
  done < .env
  if [ "$found" -eq 1 ]; then printf '%s' "$out"; return 0; fi
  return 1
}

# Only return values whose quote/comment boundaries are unambiguous. Reject
# double-quoted escapes instead of partially emulating dotenv's transducer.
dotenv_unquote() {
  local raw="$1" q rest inner="" closed=0 val
  q="${raw:0:1}"
  case "$q" in
    '"'|"'")
      rest="${raw:1}"
      while [ -n "$rest" ]; do
        if [ "$q" = '"' ] && [ "${rest:0:1}" = '\' ]; then return 1; fi
        if [ "${rest:0:1}" = "$q" ]; then
          rest="${rest:1}"; closed=1; break
        fi
        inner="${inner}${rest:0:1}"
        rest="${rest:1}"
      done
      [ "$closed" -eq 1 ] || return 1
      grep -Eq '^[[:space:]]*(#.*)?$' <<< "$rest" || return 1
      printf '%s' "$inner"
      ;;
    *)
      val="$(printf '%s' "$raw" | sed -e 's/#.*$//' -e 's/[[:space:]]*$//')"
      case "$val" in *[[:space:]]*|*'"'*|*"'"*) return 1 ;; esac
      printf '%s' "$val"
      ;;
  esac
}

env_get() {
  local rhs
  rhs="$(dotenv_rhs_for "$1" 2>/dev/null)" || return 0
  dotenv_unquote "$rhs"
}

require_parseable_dotenv_db() {
  local rc
  if dotenv_rhs_for DB_DATABASE >/dev/null; then return 0; else rc=$?; fi
  [ "$rc" -ne 2 ] # An absent key is safe; an invalid file is not.
}

# Environment first, .env second, compose defaults last. The `:-` form (not
# `:=` on an unconditional assignment) is what keeps a real environment
# variable from being overridden by the file.
DB_DATABASE_FROM_ENV=0
if [ -n "${DB_DATABASE:-}" ]; then DB_DATABASE_FROM_ENV=1; fi
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

# Local docker only, enforced by the commands that connect (backup and
# restore-proof). A DB_HOST that is not this machine means someone pointed .env
# at a shared or production server, and the two things those commands do — dump
# a whole database to a laptop, drop and recreate databases — are exactly what
# must never happen there. `rotate` and `promote-weekly` touch only files, so
# they run anywhere: the production cron and the CI dry-run proof have no
# docker, and must not need one.
require_local_docker() {
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
  require_parseable_dotenv_db || return 1
  require_local_docker
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
  # The offsite copy. A directory, copied into by cp, so a missing or
  # unmounted destination fails loudly here — a silent copy that never lands
  # is worse than no copy step at all. The backup itself already succeeded
  # above, so a failed copy keeps the backup and exits nonzero: the
  # operator's next run (or the cron mail) sees it.
  if [ -n "$BACKUP_COPY_DEST" ]; then
    if [ -d "$BACKUP_COPY_DEST" ]; then
      cp "$out" "$BACKUP_COPY_DEST/" \
        && echo "pg-backup: copied $(basename "$out") to ${BACKUP_COPY_DEST}/" \
        || { echo "pg-backup: FAILED: backup kept at ${out} but the copy to ${BACKUP_COPY_DEST}/ failed." >&2; return 1; }
    else
      echo "pg-backup: FAILED: backup kept at ${out} but BACKUP_COPY_DEST '${BACKUP_COPY_DEST}' is not a directory (mount missing?)." >&2
      return 1
    fi
  fi
}

# Newest-first listing of one class of dumps (pattern like two-web-*.dump),
# so rotation keeps the head and deletes the tail. `ls -t` is name-mtime
# order on a live dir; the selftest sets mtimes explicitly, which is why this
# reads mtime rather than parsing timestamps out of filenames — the filenames
# carry wall-clock time for humans, the rotation reads the filesystem.
newest_first() {
  ls -t "$@" 2>/dev/null || true
}

cmd_rotate() {
  local dry_run=0
  if [ "${1:-}" = "--dry-run" ]; then dry_run=1; shift; fi

  mkdir -p "$BACKUP_DIR"
  # Weeklies first (they match the daily glob too, so exclude them from the
  # daily list by filtering the name, not by moving directories around).
  local weeklies dailies keep delete
  weeklies="$(newest_first "${BACKUP_DIR}"/two-web-weekly-*.dump)"
  dailies="$(newest_first "${BACKUP_DIR}"/two-web-*.dump | grep -v 'two-web-weekly-' || true)"

  keep="$(printf '%s\n' "$weeklies" | head -n "$BACKUP_KEEP_WEEKLY")"
  delete="$(printf '%s\n' "$weeklies" | tail -n +"$((BACKUP_KEEP_WEEKLY + 1))")"
  local keep_d delete_d
  keep_d="$(printf '%s\n' "$dailies" | head -n "$BACKUP_KEEP_DAILY")"
  delete_d="$(printf '%s\n' "$dailies" | tail -n +"$((BACKUP_KEEP_DAILY + 1))")"
  keep="$(printf '%s\n%s' "$keep" "$keep_d" | grep -v '^$' || true)"
  delete="$(printf '%s\n%s' "$delete" "$delete_d" | grep -v '^$' || true)"

  local f
  printf '%s\n' "$keep" | while IFS= read -r f; do
    [ -n "$f" ] && echo "keep: $f"
  done
  if [ "$dry_run" -eq 1 ]; then
    printf '%s\n' "$delete" | while IFS= read -r f; do
      [ -n "$f" ] && echo "delete: $f"
    done
    echo "pg-backup: dry-run — keeping $(printf '%s\n' "$keep" | grep -c . || true), would delete $(printf '%s\n' "$delete" | grep -c . || true). Nothing removed."
  else
    printf '%s\n' "$delete" | while IFS= read -r f; do
      if [ -n "$f" ]; then rm -f "$f" && echo "delete: $f"; fi
    done
    echo "pg-backup: rotated — keeping $(printf '%s\n' "$keep" | grep -c . || true), deleted $(printf '%s\n' "$delete" | grep -c . || true)."
  fi
}

cmd_promote_weekly() {
  local src="${1:-}"
  if [ -z "$src" ]; then
    # Newest daily, excluding files already promoted.
    src="$(newest_first "${BACKUP_DIR}"/two-web-*.dump | grep -v 'two-web-weekly-' | head -n 1 || true)"
    [ -n "$src" ] || {
      echo "pg-backup: no daily dump found in ${BACKUP_DIR}/ — run '$0 backup' first." >&2
      exit 1
    }
  fi
  [ -f "$src" ] || { echo "pg-backup: no such dump: ${src}" >&2; exit 1; }
  mkdir -p "$BACKUP_DIR"
  local dest="${BACKUP_DIR}/two-web-weekly-$(date -u +%Y%m%dT%H%M%SZ).dump"
  cp "$src" "$dest"
  echo "pg-backup: promoted $(basename "$src") to $(basename "$dest")"
}

cmd_restore_proof() {
  # Check before arming cleanup or touching docker: both dropdb paths must
  # target a scratch database, never the source whose backup we are proving.
  require_parseable_dotenv_db || return 1
  local rhs value
  if [ "$DB_DATABASE_FROM_ENV" -eq 0 ] && rhs="$(dotenv_rhs_for DB_DATABASE)"; then
    # Dotenv expands $VAR in unquoted/double-quoted values. Never compare the
    # literal text with a name the application could expand into the scratch DB.
    value="$(dotenv_unquote "$rhs")"
    case "$rhs" in
      "'"*) ;;
      *)
        case "$value" in
          *'$'*)
            echo "pg-backup: refusing: .env DB_DATABASE uses variable expansion — set a literal database name." >&2
            return 1
            ;;
        esac
        ;;
    esac
  fi
  # libpq accepts connection strings and URIs as -d arguments; different text
  # can still resolve to the scratch DB. Only a plain name may reach the guard.
  case "$DB_DATABASE" in
    *'='*|*'://'*|*[[:space:]]*)
      echo "pg-backup: refusing: DB_DATABASE must be a plain database name, not a connection string or URI." >&2
      return 1
      ;;
  esac
  if [ "$DB_DATABASE" = "$SCRATCH_DB" ]; then
    echo "pg-backup: refusing: DB_DATABASE must differ from scratch database '${SCRATCH_DB}'." >&2
    return 1
  fi
  require_local_docker
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
  backup)         shift; cmd_backup "$@" ;;
  rotate)         shift; cmd_rotate "$@" ;;
  promote-weekly) shift; cmd_promote_weekly "$@" ;;
  restore-proof)  shift; cmd_restore_proof "$@" ;;
  *)              usage ;;
esac
