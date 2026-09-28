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
#   password-not-in-argv  the secret never appears in any docker argv
#   proof-ok              equal counts                  -> PROOF OK, scratch dropped
#   proof-mismatch        one count differs             -> PROOF FAILED, exit 1
#   proof-corrupt         pg_restore exits 1            -> nonzero, scratch dropped
#   proof-missing-dump    explicit path that is absent  -> exit 1, names it
#   proof-no-dump         empty backups/, no argument   -> exit 1, says run backup
#   proof-no-container    compose service down          -> exit 1, says compose up
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

# Happy path: three tables, equal counts. Asserts the verdict, the per-table
# listing (a PROOF OK with no table detail proves nothing to a reader), and
# that both the scratch database and the container-side dump copy were removed.
dir="$(fixture proofok)"
seed_dump "$dir"
out="$(run_script "$dir" restore-proof "$dir/backups/seed.dump" 2>&1)"; status=$?
if [ "$status" -eq 0 ] && grep -qF "PROOF OK" <<< "$out" \
    && grep -qF "cache 3" <<< "$out" && grep -qF "migrations 2" <<< "$out" \
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

printf '\n'
if [ "$rc" -ne 0 ]; then
  printf '\033[31mthe backup script does not do what it claims. Fix it before trusting a dump.\033[0m\n'
else
  printf '\033[1m%d/%d — the backup script refuses, backs up, and proves, for the stated reasons.\033[0m\n' "$n" "$n"
fi
exit "$rc"
