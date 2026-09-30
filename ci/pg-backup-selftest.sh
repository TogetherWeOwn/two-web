#!/usr/bin/env bash
#
# Tests for the backup script that only runs for real with docker + Postgres.
#
# `bin/pg-backup.sh` executes exactly twice per real use — once to take the
# dump, once to prove it restores — and both runs need the compose database up.
# That makes its guards the part nobody exercises: the local-only refusal, the
# empty-dump check, the EXIT traps that clean up the scratch database, and the
# password passing. A guard that quietly stopped guarding would look identical
# to one that works, right up until someone dumps production onto a laptop or
# trusts a backup that never restored.
#
# So: a fake `docker` on PATH, scripted per case, and the real script run
# against it from a throwaway fixture dir. Every case asserts an exit code *and*
# the reason — a red for the wrong reason is a different defect wearing this
# one's name.
#
# What is pinned:
#
#   parses                the script is valid bash (the cheapest case, and the
#                         one that catches a heredoc typo before anything else)
#   no-args               no subcommand                -> exit 2 + usage
#   bad-command           unknown subcommand           -> exit 2 + usage
#   remote-host-refused   DB_HOST=db.example.com       -> exit 1, local-only
#   dotenv-quoted-export  export DB_HOST="db.example…" -> still refused (the
#                         .env reader strips `export` and quotes)
#   env-beats-dotenv      .env says remote, environment says local -> the
#                         backup proceeds (dotenv's own precedence, and what
#                         keeps this suite from touching the repo's .env)
#   backup-writes         dump file lands with content, temp file is gone
#   backup-empty          pg_dump prints nothing        -> exit 1, no file kept
#   backup-failed         pg_dump exits nonzero          -> no dump/temp or success
#   password-not-in-argv  the secret never appears in any docker argv
#   proof-source-alias    source equals scratch (env/.env) -> exit 1, no docker calls
#   proof-alias-comment   dotenv quoted value + trailing `#` comment -> same
#   proof-alias-hash-nospace unquoted value + `#` with no preceding space ->
#                         same (dotenv starts a comment at any `#`)
#   proof-alias-spaced-key  `DB_DATABASE = …` (spaces around `=`) -> same
#   proof-alias-quoted-key  `"DB_DATABASE"=…` (quoted name) -> same
#   proof-alias-invalid-name a line dotenv itself rejects -> exit 1 naming
#                         the rejection, no docker calls
#   proof-backup-unparse  malformed DB_DATABASE line + `backup` -> exit 1
#                         naming the parse refusal, no dump, no docker calls
#   proof-alias-conninfo  `dbname=…` connection string (env/.env) -> exit 1,
#                         names the connection-string rule, no docker calls
#   proof-alias-uri       `postgresql://…` URI -> same
#   proof-alias-unparse   unterminated quote in .env -> exit 1, parse refusal,
#                         no docker calls
#   proof-alias-expansion dotenv `$VAR` expansion -> exit 1, expansion refusal,
#                         no docker calls
#   proof-ok              distinct source, equal counts -> PROOF OK, scratch dropped
#   proof-mismatch        one count differs             -> PROOF FAILED, exit 1
#   proof-corrupt         pg_restore exits 1            -> nonzero, scratch dropped
#   proof-missing-dump    explicit path that is absent  -> exit 1, names it
#   proof-no-dump         empty backups/, no argument   -> exit 1, says run backup
#   proof-no-container    compose service down          -> exit 1, says compose up
#   rotate-dry-run        8 dailies, --dry-run          -> exit 0, keeps newest 7,
#                         names the 8th for delete, removes nothing
#   rotate-real           8 dailies                     -> exit 0, oldest gone, 7 kept
#   rotate-weekly         5 weeklies                    -> keeps newest 4, deletes 1
#   rotate-no-docker      rotate runs with no docker on PATH (pure files)
#   promote-weekly        promotes newest daily to a two-web-weekly-*.dump
#   copy-dest             BACKUP_COPY_DEST set          -> finished dump copied there
#   copy-dest-missing     BACKUP_COPY_DEST absent       -> exit 1 naming it, backup kept
#
# No network, no docker, no Postgres. Runs anywhere bash lives.
#
# Usage: ./ci/pg-backup-selftest.sh

set -uo pipefail

# The stub `docker` below works by prepending to PATH — but a non-interactive
# bash sources $BASH_ENV on startup, and some sandboxes use that file to pin
# PATH to a fixed value, which silently discards the stub. This suite, and
# every subshell it spawns for the script under test, needs the PATH it sets.
# (/dev/null sources to nothing, so the caller's PATH survives intact.)
export BASH_ENV=/dev/null

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/pg-backup-selftest.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

rc=0
n=0
pass() { n=$((n + 1)); printf '\033[32mPASS\033[0m  %s\n' "$*"; }
fail() { n=$((n + 1)); printf '\033[31mFAIL\033[0m  %s\n' "$*" >&2; rc=1; }

# --- The fake docker --------------------------------------------------------
#
# Behaviour is read from the environment at call time, so one stub serves every
# case. Every invocation is appended to $STUB_LOG as `docker <argv>`, which is
# what the password case audits and what the cleanup cases grep for dropdb/rm.

STUB_DIR="$WORK/stub"
mkdir -p "$STUB_DIR"

cat > "$STUB_DIR/docker" <<'STUB'
#!/usr/bin/env bash
# Fake docker for pg-backup-selftest.sh. See the header there for the contract.
log() { printf 'docker %s\n' "$*" >> "${STUB_LOG:-/dev/null}"; }
log "$@"

# `docker compose ...` takes a second word; `docker cp` and `docker exec`
# take paths straight after the subcommand, so dispatch on $1 alone for those.
case "${1:-}" in
  compose)
    case "${2:-}" in
      ps)
        # `docker compose ps -q postgres`
        [ "${STUB_NO_CONTAINER:-0}" = 1 ] || printf 'fakecid123\n'
        exit 0
        ;;
      exec)
    # `docker compose exec -T -e PGPASSWORD postgres <cmd> ...`
    shift 2
    while [ "$#" -gt 0 ] && [ "$1" != "postgres" ]; do shift; done
    [ "$#" -gt 0 ] && shift # past "postgres"
    cmd="${1:-}"; shift || true
    db=""; sql=""
    prev=""
    for a in "$@"; do
      if [ "$prev" = "-d" ]; then db="$a"; fi
      prev="$a"
    done
    sql="${prev:-}"
    case "$cmd" in
      pg_dump)
        [ "${STUB_DUMP_FAIL:-0}" = 1 ] && { echo "pg_dump: connection failed" >&2; exit 1; }
        [ "${STUB_EMPTY_DUMP:-0}" = 1 ] && exit 0
        printf 'FAKEPGDUMP-contents\n'
        exit 0
        ;;
      psql)
        if grep -q "pg_tables" <<< "$sql"; then
          [ "${STUB_NO_TABLES:-0}" = 1 ] && exit 0
          printf '%b\n' "${STUB_TABLES:-cache\\njobs\\nmigrations}"
          exit 0
        fi
        if grep -q "count(\*)" <<< "$sql"; then
          t="$(grep -oE 'from "[^"]+"' <<< "$sql" | cut -d'"' -f2)"
          # Unset STUB_COUNTS_SCRATCH means "restore changed nothing", so the
          # scratch reads the source counts — the happy path needs no setup.
          if [ "$db" = "two_web_restore_proof" ]; then
            map="${STUB_COUNTS_SCRATCH:-${STUB_COUNTS_SRC:-cache:3 jobs:5 migrations:2}}"
          else
            map="${STUB_COUNTS_SRC:-cache:3 jobs:5 migrations:2}"
          fi
          printf '%s\n' "$map" | tr ' ' '\n' | awk -F: -v t="$t" '$1==t{print $2}'
          exit 0
        fi
        echo "stub docker: unexpected psql: $sql" >&2; exit 99
        ;;
      createdb|dropdb) exit 0 ;;
      pg_restore)
        [ "${STUB_RESTORE_FAIL:-0}" = 1 ] && { echo "pg_restore: error: did not find magic string in file header" >&2; exit 1; }
        exit 0
        ;;
      *) echo "stub docker: unexpected command: $cmd" >&2; exit 99 ;;
    esac
        ;;
      *) echo "stub docker: unexpected compose invocation: $*" >&2; exit 99 ;;
    esac
    ;;
  cp)
    # `docker cp <host path> <cid>:/tmp/restore-proof.dump`
    [ "${STUB_COPY_FAIL:-0}" = 1 ] && { echo "docker cp: no such file" >&2; exit 1; }
    [ -f "$2" ] || { echo "docker cp: host file missing: $2" >&2; exit 1; }
    exit 0
    ;;
  exec)
    # `docker exec <cid> rm -f /tmp/restore-proof.dump`
    exit 0
    ;;
  *) echo "stub docker: unexpected invocation: $*" >&2; exit 99 ;;
esac
STUB
chmod +x "$STUB_DIR/docker"

# A pristine fixture per case: a copy of the real script (so ROOT resolves
# inside the fixture and the repo's own .env is never read) plus an optional
# .env the case provides. Prints the fixture dir.
fixture() {
  local dir="$WORK/$1"
  rm -rf "$dir"
  mkdir -p "$dir/bin"
  cp "$REPO_ROOT/bin/pg-backup.sh" "$dir/bin/"
  echo "$dir"
}

# Run the fixture script with the stub first on PATH (stdout+stderr merged so
# the reason is assertable; caller captures the exit itself).
run_script() {
  local dir="$1"; shift
  ( cd "$dir" && PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" ./bin/pg-backup.sh "$@" 2>&1 )
}

# A seed dump the proof can restore: content is irrelevant (the stub reads
# behaviour from the environment), the file just has to exist.
seed_dump() {
  mkdir -p "$1/backups"
  printf 'x' > "$1/backups/seed.dump"
}

printf '\n\033[1m==> The script parses\033[0m\n'

if bash -n "$REPO_ROOT/bin/pg-backup.sh"; then
  pass "parses"
else
  fail "parses: bin/pg-backup.sh is not valid bash"
fi

printf '\n\033[1m==> Bad usage is refused before anything connects\033[0m\n'

dir="$(fixture usage)"
out="$(run_script "$dir")"; status=$?
if [ "$status" -eq 2 ] && grep -qF "usage:" <<< "$out"; then pass "no-args"; else
  fail "no-args: expected exit 2 and a usage line, got ${status}"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

out="$(run_script "$dir" frobnicate)"; status=$?
if [ "$status" -eq 2 ] && grep -qF "usage:" <<< "$out"; then pass "bad-command"; else
  fail "bad-command: expected exit 2 and a usage line, got ${status}"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

printf '\n\033[1m==> Non-local databases are refused\033[0m\n'

# A .env pointed at a shared server, with no environment override. The refusal
# must name the local-only rule — a bare exit 1 could be any failure, and the
# next person to hit it is mid-incident.
dir="$(fixture remote)"
printf 'DB_HOST=db.example.com\nDB_PASSWORD=hunter2\n' > "$dir/.env"
out="$(run_script "$dir" backup)"; status=$?
if [ "$status" -eq 1 ] && grep -qF "local-docker only" <<< "$out"; then pass "remote-host-refused"; else
  fail "remote-host-refused: expected exit 1 naming the local-only rule, got ${status}"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

# Same, through `export` and double quotes — the two spellings a real .env
# actually uses. If env_get cannot read them, this case goes green for the
# wrong reason (a default 127.0.0.1 that never came from the file), so the
# assertion is on the refusal naming the *file's* host… which it cannot print.
# Instead: the refusal must still fire. A parse failure that silently falls
# back to defaults would proceed to a backup here and fail this case.
dir="$(fixture quoted)"
printf 'export DB_HOST="db.example.com"\nexport DB_PASSWORD="hunter2"\n' > "$dir/.env"
out="$(run_script "$dir" backup)"; status=$?
if [ "$status" -eq 1 ] && grep -qF "local-docker only" <<< "$out"; then
  pass "dotenv-quoted-export"
else
  fail "dotenv-quoted-export: the quoted/exported remote host was not refused (got ${status}) — the .env reader may have missed it and fallen back to defaults"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

# Environment wins over the file (dotenv's own rule). .env says remote; the
# environment says local with a known password. If the file won, this refuses;
# if the environment wins, the backup proceeds against the stub.
dir="$(fixture envwins)"
printf 'DB_HOST=db.example.com\nDB_PASSWORD=file-password\n' > "$dir/.env"
out="$(cd "$dir" && PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
  DB_HOST=127.0.0.1 DB_PASSWORD=env-password ./bin/pg-backup.sh backup 2>&1)"; status=$?
if [ "$status" -eq 0 ] && grep -qF "wrote backups/two-web-" <<< "$out"; then
  pass "env-beats-dotenv"
else
  fail "env-beats-dotenv: expected the environment to override .env and the backup to proceed (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

printf '\n\033[1m==> Backup writes a real file, or refuses to\033[0m\n'

dir="$(fixture backupok)"
out="$(run_script "$dir" backup)"; status=$?
dump="$(ls -t "$dir"/backups/two-web-*.dump 2>/dev/null | head -n 1 || true)"
if [ "$status" -eq 0 ] && [ -n "$dump" ] && grep -qF "FAKEPGDUMP" "$dump" \
    && [ -z "$(ls "$dir"/backups/*.tmp.* 2>/dev/null || true)" ]; then
  pass "backup-writes"
else
  fail "backup-writes: expected exit 0, a dump with content, and no leftover temp file"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

# pg_dump exiting 0 with nothing on stdout is the failure this check exists
# for: without the size assertion the script would `mv` an empty file into
# place and report success.
dir="$(fixture backupempty)"
out="$(cd "$dir" && PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
  STUB_EMPTY_DUMP=1 ./bin/pg-backup.sh backup 2>&1)"; status=$?
leftover="$(ls "$dir"/backups/*.dump "$dir"/backups/*.tmp.* 2>/dev/null || true)"
if [ "$status" -eq 1 ] && grep -qF "dump is empty" <<< "$out" && [ -z "$leftover" ]; then
  pass "backup-empty"
else
  fail "backup-empty: expected exit 1 naming the empty dump and no file kept (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
  [ -n "$leftover" ] && printf '        leftover: %s\n' "$leftover"
fi

# A failed pg_dump must stop before publishing the dump or reporting success,
# and the EXIT trap must remove the temporary output opened by the redirect.
dir="$(fixture backupfailed)"
out="$(cd "$dir" && PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
  STUB_DUMP_FAIL=1 ./bin/pg-backup.sh backup 2>&1)"; status=$?
leftover="$(ls "$dir"/backups/*.dump "$dir"/backups/*.tmp.* 2>/dev/null || true)"
if [ "$status" -ne 0 ] && grep -qFx "pg_dump: connection failed" <<< "$out" \
    && [ -z "$leftover" ] && ! grep -qF "pg-backup: wrote " <<< "$out"; then
  pass "backup-failed"
else
  fail "backup-failed: expected nonzero exit naming the connection failure, no dump/temp file, and no wrote-success message (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
  [ -n "$leftover" ] && printf '        leftover: %s\n' "$leftover"
fi

printf '\n\033[1m==> The password never reaches a command line\033[0m\n'

# The stub logs every docker argv. The backup and the proof both ran above
# against the real secret only if one was provided — so run one of each with a
# distinctive password and audit the log. `-e PGPASSWORD` (name only) must be
# there; `=distinctive` must not.
dir="$(fixture passwd)"
printf 'DB_PASSWORD=s3cr3t-pw-xyzzy\n' > "$dir/.env"
run_script "$dir" backup >/dev/null 2>&1
seed_dump "$dir"
out="$(run_script "$dir" restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
if grep -qF "s3cr3t-pw-xyzzy" "$dir/docker.log"; then
  fail "password-not-in-argv: the secret appears in a docker argv"
  grep -n "s3cr3t" "$dir/docker.log" | sed 's/^/        /'
elif ! grep -qF -- "-e PGPASSWORD" "$dir/docker.log"; then
  fail "password-not-in-argv: no \`-e PGPASSWORD\` forwarding found — the container may be authenticating another way"
else
  pass "password-not-in-argv"
fi

printf '\n\033[1m==> The restore proof proves, and cleans up\033[0m\n'

# Aliasing would let both the initial dropdb and EXIT cleanup delete the source.
# Refuse environment and quoted/exported .env values before any docker call,
# including cleanup. Seed a valid dump so missing input cannot mask the guard.
for source in env dotenv; do
  dir="$(fixture "proofalias-${source}")"
  seed_dump "$dir"
  : > "$dir/docker.log"
  if [ "$source" = env ]; then
    out="$(cd "$dir" && PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
      DB_HOST=127.0.0.1 DB_DATABASE=two_web_restore_proof \
      ./bin/pg-backup.sh restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
  else
    printf 'export DB_DATABASE="two_web_restore_proof"\n' > "$dir/.env"
    out="$(cd "$dir" && env -u DB_DATABASE PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
      DB_HOST=127.0.0.1 ./bin/pg-backup.sh restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
  fi
  if [ "$status" -eq 1 ] \
      && grep -qF "DB_DATABASE must differ from scratch database 'two_web_restore_proof'" <<< "$out" \
      && [ ! -s "$dir/docker.log" ]; then
    pass "proof-source-alias-${source}"
  else
    fail "proof-source-alias-${source}: expected exit 1 naming the alias and no docker calls (got ${status})"
    printf '%s\n' "$out" | sed 's/^/        /'
    printf '%s\n' "$(< "$dir/docker.log")" | sed 's/^/        /'
  fi
done

# The application (dotenv) strips a trailing `#` comment after a quoted value,
# so a commented alias must refuse exactly like a literal one — before any
# docker call, including cleanup.
dir="$(fixture proofalias-comment)"
seed_dump "$dir"
printf 'DB_DATABASE="two_web_restore_proof" # local database\n' > "$dir/.env"
out="$(cd "$dir" && env -u DB_DATABASE PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
  DB_HOST=127.0.0.1 ./bin/pg-backup.sh restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
if [ "$status" -eq 1 ] \
    && grep -qF "DB_DATABASE must differ from scratch database 'two_web_restore_proof'" <<< "$out" \
    && [ ! -s "$dir/docker.log" ]; then
  pass "proof-alias-comment"
else
  fail "proof-alias-comment: expected exit 1 naming the alias and no docker calls (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
  printf '%s\n' "$(< "$dir/docker.log")" | sed 's/^/        /'
fi

# libpq accepts keyword/value and URI connection strings where this script
# passes `-d`, so values that merely differ textually from the scratch name
# can still address it. Refuse them by rule — from the environment and from
# .env — before any docker call.
for source in env dotenv; do
  dir="$(fixture "proofalias-conninfo-${source}")"
  seed_dump "$dir"
  : > "$dir/docker.log"
  if [ "$source" = env ]; then
    out="$(cd "$dir" && PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
      DB_HOST=127.0.0.1 DB_DATABASE='dbname=two_web_restore_proof' \
      ./bin/pg-backup.sh restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
  else
    printf "DB_DATABASE='dbname=two_web_restore_proof'\n" > "$dir/.env"
    out="$(cd "$dir" && env -u DB_DATABASE PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
      DB_HOST=127.0.0.1 ./bin/pg-backup.sh restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
  fi
  if [ "$status" -eq 1 ] \
      && grep -qF "must be a plain database name, not a connection string or URI" <<< "$out" \
      && [ ! -s "$dir/docker.log" ]; then
    pass "proof-alias-conninfo-${source}"
  else
    fail "proof-alias-conninfo-${source}: expected exit 1 naming the connection-string rule and no docker calls (got ${status})"
    printf '%s\n' "$out" | sed 's/^/        /'
    printf '%s\n' "$(< "$dir/docker.log")" | sed 's/^/        /'
  fi
done

dir="$(fixture proofalias-uri)"
seed_dump "$dir"
out="$(cd "$dir" && PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
  DB_HOST=127.0.0.1 DB_DATABASE='postgresql://127.0.0.1:5432/two_web' \
  ./bin/pg-backup.sh restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
if [ "$status" -eq 1 ] \
    && grep -qF "must be a plain database name, not a connection string or URI" <<< "$out" \
    && [ ! -s "$dir/docker.log" ]; then
  pass "proof-alias-uri"
else
  fail "proof-alias-uri: expected exit 1 naming the connection-string rule and no docker calls (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
  printf '%s\n' "$(< "$dir/docker.log")" | sed 's/^/        /'
fi

# An .env database line this script cannot parse the same way as the
# application must fail closed: comparing a different name than the
# application connects to is how the alias slips through.
dir="$(fixture proofalias-unparse)"
seed_dump "$dir"
printf 'DB_DATABASE="two_web_restore_proof\n' > "$dir/.env"
out="$(cd "$dir" && env -u DB_DATABASE PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
  DB_HOST=127.0.0.1 ./bin/pg-backup.sh restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
if [ "$status" -eq 1 ] \
    && grep -qF "cannot parse the DB_DATABASE line in .env" <<< "$out" \
    && [ ! -s "$dir/docker.log" ]; then
  pass "proof-alias-unparse"
else
  fail "proof-alias-unparse: expected exit 1 naming the parse refusal and no docker calls (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
  printf '%s\n' "$(< "$dir/docker.log")" | sed 's/^/        /'
fi

# dotenv expands $VAR inside unquoted and double-quoted values while this
# script deliberately does not — so such a line compares a different name
# than the application connects to. Single-quoted values stay literal and
# take the plain-name path instead.
dir="$(fixture proofalias-expansion)"
seed_dump "$dir"
printf 'DB_DATABASE="two_web_${SUFFIX:-restore_proof}"\n' > "$dir/.env"
out="$(cd "$dir" && env -u DB_DATABASE PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
  DB_HOST=127.0.0.1 ./bin/pg-backup.sh restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
if [ "$status" -eq 1 ] \
    && grep -qF "uses variable expansion" <<< "$out" \
    && [ ! -s "$dir/docker.log" ]; then
  pass "proof-alias-expansion"
else
  fail "proof-alias-expansion: expected exit 1 naming the expansion refusal and no docker calls (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
  printf '%s\n' "$(< "$dir/docker.log")" | sed 's/^/        /'
fi

# dotenv starts a comment at any `#` in an unquoted value — no preceding
# whitespace required (EntryParser enters COMMENT_STATE on `#` in both the
# initial and unquoted states) — so an attached comment must refuse exactly
# like a spaced one: the application connects to the scratch database either
# way.
dir="$(fixture proofalias-hash-nospace)"
seed_dump "$dir"
printf 'DB_DATABASE=two_web_restore_proof#local\n' > "$dir/.env"
out="$(cd "$dir" && env -u DB_DATABASE PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
  DB_HOST=127.0.0.1 ./bin/pg-backup.sh restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
if [ "$status" -eq 1 ] \
    && grep -qF "DB_DATABASE must differ from scratch database 'two_web_restore_proof'" <<< "$out" \
    && [ ! -s "$dir/docker.log" ]; then
  pass "proof-alias-hash-nospace"
else
  fail "proof-alias-hash-nospace: expected exit 1 naming the alias and no docker calls (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
  printf '%s\n' "$(< "$dir/docker.log")" | sed 's/^/        /'
fi

# dotenv splits each line on the first `=`, trims the name, and strips one
# leading `export` and one matching quote pair — so spaced and quoted names
# set DB_DATABASE exactly like the bare spelling, and an alias through them
# must refuse before any docker call. One case per spelling, all seeded so a
# missing dump cannot mask the guard.
i=0
for spelling in 'DB_DATABASE = two_web_restore_proof' '"DB_DATABASE"=two_web_restore_proof' "'DB_DATABASE'=two_web_restore_proof"; do
  i=$((i + 1))
  dir="$(fixture "proofalias-keyspell-${i}")"
  seed_dump "$dir"
  printf '%s\n' "$spelling" > "$dir/.env"
  out="$(cd "$dir" && env -u DB_DATABASE PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
    DB_HOST=127.0.0.1 ./bin/pg-backup.sh restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
  if [ "$status" -eq 1 ] \
      && grep -qF "DB_DATABASE must differ from scratch database 'two_web_restore_proof'" <<< "$out" \
      && [ ! -s "$dir/docker.log" ]; then
    pass "proof-alias-keyspell-${i} ($(printf '%s' "$spelling" | head -c 24))"
  else
    fail "proof-alias-keyspell-${i}: spelling $(printf '%s' "$spelling" | head -c 40) expected exit 1 naming the alias and no docker calls (got ${status})"
    printf '%s\n' "$out" | sed 's/^/        /'
    printf '%s\n' "$(< "$dir/docker.log")" | sed 's/^/        /'
  fi
done

# A line dotenv itself rejects (here: a name with interior blanks) makes the
# application refuse to boot at all — Laravel dies in writeErrorAndDie — so
# there is no live application value to diverge from. The proof must still
# fail closed on it rather than compare the fallback default the application
# would never use.
dir="$(fixture proofalias-invalid-name)"
seed_dump "$dir"
printf 'DB_DATABASE=two_web\nDB DATA=oops\n' > "$dir/.env"
out="$(cd "$dir" && env -u DB_DATABASE PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
  DB_HOST=127.0.0.1 ./bin/pg-backup.sh restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
if [ "$status" -eq 1 ] \
    && grep -qF "dotenv itself rejects" <<< "$out" \
    && [ ! -s "$dir/docker.log" ]; then
  pass "proof-alias-invalid-name"
else
  fail "proof-alias-invalid-name: expected exit 1 naming the dotenv rejection and no docker calls (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
  printf '%s\n' "$(< "$dir/docker.log")" | sed 's/^/        /'
fi

# `backup` dumps whichever database the local client resolves, so a .env
# database line this script cannot parse the same way as the application must
# refuse there too — before any docker call, with no dump written and no temp
# file kept. `rotate` and `promote-weekly` stay tolerant on purpose (they
# address no database); only the connecting commands refuse.
dir="$(fixture proofbackup-unparse)"
printf 'DB_DATABASE="important_db\n' > "$dir/.env"
out="$(cd "$dir" && env -u DB_DATABASE PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
  DB_HOST=127.0.0.1 ./bin/pg-backup.sh backup 2>&1)"; status=$?
dump="$(ls -t "$dir"/backups/two-web-*.dump 2>/dev/null | head -n 1 || true)"
if [ "$status" -eq 1 ] \
    && grep -qF "cannot parse the DB_DATABASE line in .env" <<< "$out" \
    && [ ! -s "$dir/docker.log" ] \
    && [ -z "$dump" ] \
    && [ -z "$(ls "$dir"/backups/*.tmp.* 2>/dev/null || true)" ]; then
  pass "proof-backup-unparse"
else
  fail "proof-backup-unparse: expected exit 1 naming the parse refusal with no dump and no docker calls (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
  printf '%s\n' "$(< "$dir/docker.log")" | sed 's/^/        /'
fi

# Happy path: three tables, equal counts. Asserts the verdict, the per-table
# listing (a PROOF OK with no table detail proves nothing to a reader), and
# that both the scratch database and the container-side dump copy were removed.
dir="$(fixture proofok)"
seed_dump "$dir"
# A distinct environment value still wins over an aliased .env value.
printf 'DB_DATABASE=two_web_restore_proof\n' > "$dir/.env"
out="$(cd "$dir" && PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
  DB_HOST=127.0.0.1 DB_DATABASE=custom_source \
  ./bin/pg-backup.sh restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
if [ "$status" -eq 0 ] && grep -qF "PROOF OK" <<< "$out" \
    && grep -qF "cache 3" <<< "$out" && grep -qF "migrations 2" <<< "$out" \
    && grep -qF -- "-d custom_source" "$dir/docker.log" \
    && grep -qF "createdb" "$dir/docker.log" && grep -qF "pg_restore" "$dir/docker.log" \
    && grep -qF "dropdb" "$dir/docker.log" && grep -qF "restore-proof.dump" "$dir/docker.log"; then
  pass "proof-ok"
else
  fail "proof-ok: expected exit 0, PROOF OK with table counts, and scratch cleanup (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

# One count differs after restore: the proof must go red *for the mismatch*,
# by name, with the diff — not with a generic failure.
dir="$(fixture proofmismatch)"
seed_dump "$dir"
out="$(cd "$dir" && PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
  STUB_COUNTS_SRC="cache:3 jobs:5 migrations:2" \
  STUB_COUNTS_SCRATCH="cache:3 jobs:4 migrations:2" \
  ./bin/pg-backup.sh restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
if [ "$status" -eq 1 ] && grep -qF "PROOF FAILED" <<< "$out" && grep -q "jobs" <<< "$out"; then
  pass "proof-mismatch"
else
  fail "proof-mismatch: expected exit 1 with PROOF FAILED naming the table (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

# A truncated dump: pg_restore exits 1, `set -e` stops the proof there, and the
# EXIT trap still drops the scratch database — assert both halves, because a
# proof that reds but leaves the scratch copy behind poisons the next run.
dir="$(fixture proofcorrupt)"
seed_dump "$dir"
printf 'truncated' > "$dir/backups/seed.dump"
out="$(cd "$dir" && PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
  STUB_RESTORE_FAIL=1 ./bin/pg-backup.sh restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
if [ "$status" -ne 0 ] && grep -qF "dropdb" "$dir/docker.log"; then
  pass "proof-corrupt"
else
  fail "proof-corrupt: expected a nonzero exit with the scratch database dropped (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

printf '\n\033[1m==> Missing inputs fail by name\033[0m\n'

dir="$(fixture missing)"
out="$(run_script "$dir" restore-proof "$dir/backups/does-not-exist.dump" 2>&1)"; status=$?
if [ "$status" -eq 1 ] && grep -qF "no such dump" <<< "$out"; then
  pass "proof-missing-dump"
else
  fail "proof-missing-dump: expected exit 1 naming the missing dump (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

# No argument and no backups/: the message must say what to do next, not just
# what went wrong.
out="$(run_script "$dir" restore-proof 2>&1)"; status=$?
if [ "$status" -eq 1 ] && grep -qF "backup' first" <<< "$out"; then
  pass "proof-no-dump"
else
  fail "proof-no-dump: expected exit 1 pointing at backup first (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

# Compose service down: the message must say `compose up`, because the person
# reading it just ran a script that told them docker was fine.
seed_dump "$dir"
out="$(cd "$dir" && PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
  STUB_NO_CONTAINER=1 ./bin/pg-backup.sh restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
if [ "$status" -eq 1 ] && grep -qF "compose up -d" <<< "$out"; then
  pass "proof-no-container"
else
  fail "proof-no-container: expected exit 1 naming compose up (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

printf '\n\033[1m==> Rotation keeps newest 7 dailies + 4 weeklies, and copies offsite\033[0m\n'

# Eight dailies, staggered mtimes so newest-first order is unambiguous.
# Rotation reads mtime, not filename timestamps — the selftest sets mtimes
# explicitly, so a change to name-parsing instead of mtime order fails here.
seed_dailies() {
  local dir="$1" i=1
  mkdir -p "$dir/backups"
  for d in 01 02 03 04 05 06 07 08; do
    printf "dump-%s" "$d" > "$dir/backups/two-web-202609${d}T030000Z.dump"
    touch -d "2026-09-${d} 03:00 UTC" "$dir/backups/two-web-202609${d}T030000Z.dump"
    i=$((i + 1))
  done
}

# Rotation without docker anywhere on PATH: rotate and promote-weekly are
# pure file operations. If they ever grow a docker dependency (e.g. by
# calling the guard at top level again), this case goes red — the production
# cron and the CI proof run with no docker, and must keep working.
run_bare() {
  local dir="$1"; shift
  ( cd "$dir" && env -i PATH="/usr/bin:/bin" HOME="$HOME" TMPDIR="${TMPDIR:-/tmp}" ./bin/pg-backup.sh "$@" 2>&1 )
}

# The acceptance case: dry-run over 8 dailies keeps the newest 7, names the
# 8th (oldest) for delete, and removes nothing.
dir="$(fixture rotatedry)"
seed_dailies "$dir"
out="$(run_bare "$dir" rotate --dry-run)"; status=$?
if [ "$status" -eq 0 ] \
    && [ "$(ls "$dir"/backups/*.dump | wc -l | tr -d ' ')" -eq 8 ] \
    && grep -qF "delete: backups/two-web-20260901T030000Z.dump" <<< "$out" \
    && grep -qF "keep: backups/two-web-20260908T030000Z.dump" <<< "$out" \
    && grep -qF "Nothing removed" <<< "$out"; then
  pass "rotate-dry-run"
else
  fail "rotate-dry-run: expected exit 0, 8 files intact, keep newest 7 + delete oldest (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

# Same fixture, for real: the oldest is gone, 7 remain.
dir="$(fixture rotatereal)"
seed_dailies "$dir"
out="$(run_bare "$dir" rotate 2>&1)"; status=$?
if [ "$status" -eq 0 ] \
    && [ ! -f "$dir/backups/two-web-20260901T030000Z.dump" ] \
    && [ "$(ls "$dir"/backups/*.dump | wc -l | tr -d ' ')" -eq 7 ] \
    && grep -qF "delete: backups/two-web-20260901T030000Z.dump" <<< "$out"; then
  pass "rotate-real"
else
  fail "rotate-real: expected exit 0 with the oldest dump deleted and 7 kept (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

# Five weeklies keep the newest 4. Weeklies never count against the daily
# quota: with 8 dailies + 5 weeklies present, the run must keep the newest 7
# dailies and the newest 4 weeklies, deleting exactly the oldest of each.
# The daily count uses the structural glob two-web-2*.dump (dailies start
# with a year digit by the script's own naming; weeklies have `weekly-`
# there and can never match) — filtering `ls` output through grep would
# match the fixture path itself, not just the filenames.
dir="$(fixture rotateboth)"
seed_dailies "$dir"
i=1
for d in 21 22 23 24 25; do
  printf "weekly-%s" "$d" > "$dir/backups/two-web-weekly-202609${d}T030000Z.dump"
  touch -d "2026-09-${d} 03:00 UTC" "$dir/backups/two-web-weekly-202609${d}T030000Z.dump"
done
out="$(run_bare "$dir" rotate 2>&1)"; status=$?
if [ "$status" -eq 0 ] \
    && [ ! -f "$dir/backups/two-web-weekly-20260921T030000Z.dump" ] \
    && [ "$(ls "$dir"/backups/two-web-weekly-*.dump | wc -l | tr -d ' ')" -eq 4 ] \
    && [ "$(ls "$dir"/backups/two-web-2*.dump 2>/dev/null | wc -l | tr -d ' ')" -eq 7 ]; then
  pass "rotate-weekly"
else
  fail "rotate-weekly: expected the oldest weekly gone, 4 weeklies + 7 dailies kept (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

# Rotate works with no docker at all (asserted by run_bare above — every
# rotation case in this section runs docker-free). This case names it, so a
# future reader knows the bare PATH is the point, not an accident.
dir="$(fixture rotatenodocker)"
seed_dailies "$dir"
out="$(run_bare "$dir" rotate --dry-run 2>&1)"; status=$?
if [ "$status" -eq 0 ] && grep -qF "Nothing removed" <<< "$out"; then
  pass "rotate-no-docker"
else
  fail "rotate-no-docker: rotate --dry-run must succeed with no docker on PATH (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

# promote-weekly copies the newest daily under a weekly name; the daily stays.
dir="$(fixture promote)"
seed_dailies "$dir"
out="$(run_bare "$dir" promote-weekly 2>&1)"; status=$?
weekly="$(ls -t "$dir"/backups/two-web-weekly-*.dump 2>/dev/null | head -n 1 || true)"
if [ "$status" -eq 0 ] && [ -n "$weekly" ] \
    && cmp -s "$dir/backups/two-web-20260908T030000Z.dump" "$weekly" \
    && [ -f "$dir/backups/two-web-20260908T030000Z.dump" ]; then
  pass "promote-weekly"
else
  fail "promote-weekly: expected a weekly copy of the newest daily, daily intact (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

# BACKUP_COPY_DEST set to a directory: the backup lands in both places. The
# backup itself runs against the stub (it needs docker); the assertion is on
# the copy existing with identical content.
dir="$(fixture copydest)"
mkdir -p "$dir/offsite"
out="$(cd "$dir" && PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
  BACKUP_COPY_DEST="$dir/offsite" ./bin/pg-backup.sh backup 2>&1)"; status=$?
dump="$(ls -t "$dir"/backups/two-web-*.dump 2>/dev/null | head -n 1 || true)"
copied="$(ls -t "$dir"/offsite/*.dump 2>/dev/null | head -n 1 || true)"
if [ "$status" -eq 0 ] && [ -n "$dump" ] && [ -n "$copied" ] \
    && cmp -s "$dump" "$copied" && grep -qF "copied" <<< "$out"; then
  pass "copy-dest"
else
  fail "copy-dest: expected exit 0 with an identical copy in BACKUP_COPY_DEST (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

# BACKUP_COPY_DEST pointing nowhere: loud failure, backup kept. A silent
# copy that never lands is the failure this check exists for — an unmounted
# offsite volume must page the operator, not pass.
dir="$(fixture copymissing)"
out="$(cd "$dir" && PATH="$STUB_DIR:$PATH" STUB_LOG="$dir/docker.log" \
  BACKUP_COPY_DEST="$dir/no-such-mount" ./bin/pg-backup.sh backup 2>&1)"; status=$?
dump="$(ls -t "$dir"/backups/two-web-*.dump 2>/dev/null | head -n 1 || true)"
if [ "$status" -eq 1 ] && grep -qF "no-such-mount" <<< "$out" && [ -n "$dump" ]; then
  pass "copy-dest-missing"
else
  fail "copy-dest-missing: expected exit 1 naming the missing dest with the backup kept (got ${status})"
  printf '%s\n' "$out" | sed 's/^/        /'
fi

printf '\n'
if [ "$rc" -ne 0 ]; then
  printf '\033[31mthe backup script does not do what it claims. Fix it before trusting a dump.\033[0m\n'
else
  printf '\033[1m%d/%d — the backup script refuses, backs up, proves, rotates, and copies, for the stated reasons.\033[0m\n' "$n" "$n"
fi
exit "$rc"
