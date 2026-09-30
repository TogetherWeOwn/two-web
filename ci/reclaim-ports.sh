#!/usr/bin/env bash
set -uo pipefail

# Kill whatever is still listening on this runner's port block.
#
# On a GitHub-hosted runner every job got a fresh VM, so a server a job forgot
# to stop died with the machine and nobody ever paid for the omission. The
# self-hosted runners are persistent: the same host, the same checkout path and
# — by design, see ci/runner-ports.sh — the same ports, run after run.
#
# That turns a leaked process into a cross-run failure, and a confusing one.
# The `budgets` job starts `artisan serve` and the compressing proxy and never
# stops them (TOG-2847, first post-merge run on `main`: budgets died 57s in at
# the /admin session mint, having passed in 4m34 on the identical tree — same
# runner, coolify-vps-2, both times). A stale server from the previous run is
# still bound, so the new run's `artisan serve` loses the bind and exits, while
# the readiness probe cheerfully gets a 200 from the *old* process. The job then
# measures a zombie app: an app pointed at a torn-down Postgres service
# container, holding the previous run's cached config and a stale APP_KEY. The
# session cookie it mints is encrypted with a key the running app no longer has,
# so `/admin` reads the browser as a guest — which is exactly the symptom, and
# looks nothing like its cause.
#
# So: reclaim the block before starting anything. Belt and braces with the
# `if: always()` teardown that now follows the job — teardown handles the
# ordinary case, this handles the one where teardown never ran because the
# runner was restarted, the job was cancelled, or a step called exit first.
#
# Usage: ./ci/reclaim-ports.sh PORT [PORT...]

[ "$#" -ge 1 ] || { echo "usage: $0 PORT [PORT...]" >&2; exit 2; }

# Arguments are checked before anything else, so a typo is a usage error on any
# machine rather than depending on which tools happen to be installed.
for port in "$@"; do
  case "$port" in
    ''|*[!0-9]*)
      echo "reclaim-ports: '${port}' is not a port number" >&2
      exit 2
      ;;
  esac
done

# Refuse rather than no-op. This script's whole job is to notice something that
# is otherwise invisible, so a version that cannot look must not report success
# — that would restore the original bug while appearing to have fixed it. `ss`
# is in iproute2 and is present on the runners; if it ever is not, this fails
# loudly on the next run instead of quietly years later.
command -v ss > /dev/null 2>&1 || {
  echo "::error::reclaim-ports: \`ss\` (iproute2) is not installed, so leaked listeners cannot be detected. Install iproute2 on the runner — do not skip this check." >&2
  exit 1
}

for port in "$@"; do

  # `lsof` is not installed on the runners and `fuser` is in psmisc, which may
  # not be either. `ss` is part of iproute2 and is always present. Parse the pid
  # out of `users:(("php",pid=1234,fd=3))`.
  # An inspection failure is not an empty listing. Check ss before parsing:
  # grep legitimately returns 1 when a successful inspection has no PIDs.
  listeners="$(ss -lptnH "sport = :${port}" 2>/dev/null)"
  inspection_status=$?
  if [ "$inspection_status" -ne 0 ]; then
    echo "::error::reclaim-ports: listener inspection failed for port ${port} (ss exited ${inspection_status}); refusing cleanup." >&2
    exit 1
  fi
  pids="$(printf '%s\n' "$listeners" \
    | grep -oE 'pid=[0-9]+' | cut -d= -f2 | sort -u)"

  [ -n "$pids" ] || continue

  for pid in $pids; do
    # Only ever kill something this runner's own user owns. A pid we cannot
    # signal is a pid belonging to another tenant on the host, and killing
    # across that boundary would be a far worse bug than the one being fixed.
    if kill -0 "$pid" 2>/dev/null; then
      echo "reclaim-ports: port ${port} still held by pid ${pid} ($(ps -o comm= -p "$pid" 2>/dev/null || echo unknown)) — leaked by an earlier run, killing it"
      kill "$pid" 2>/dev/null || true
    else
      echo "::warning::reclaim-ports: port ${port} is held by pid ${pid}, which this job cannot signal. A job on this runner will now fail to bind it."
    fi
  done
done

# Give the kernel a moment to release the sockets before the caller binds them.
sleep 1
