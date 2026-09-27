#!/usr/bin/env bash
set -euo pipefail

# Give each job a stable ten-port block derived from the runner name.
#
# On the old self-hosted runners five jobs shared one host network namespace, so
# fixed host ports collided and each runner needed its own block. GitHub-hosted
# runners are fresh VMs, so a collision there is impossible — but the block is
# still derived per runner name rather than hardcoded, which keeps the jobs
# portable and keeps parallel matrix entries from ever sharing a socket.
# Numeric suffixes hash straight to a slot; the checksum fallback keeps any
# other runner name usable without restoring globally fixed ports.
runner_name="${RUNNER_NAME:?RUNNER_NAME is required}"

# A trailing run of digits (e.g. a hosted runner pool index) hashes straight to
# a slot; anything else falls back to a checksum of the full name. Either way
# the block is stable for a given runner name without any globally fixed ports.
if [[ "$runner_name" =~ ([0-9]+)$ ]]; then
  slot=$((10#${BASH_REMATCH[1]}))
else
  read -r checksum _ < <(printf '%s' "$runner_name" | cksum)
  slot="$checksum"
fi

# Stay below Linux's usual ephemeral range (32768+) and leave ten ports per
# runner. The modulo only affects non-standard or unexpectedly large suffixes.
base=$((10000 + (slot % 1000) * 10))
app_port=$base
stub_port=$((base + 1))
driver_port=$((base + 2))
upstream_port=$((base + 3))
proxy_port=$((base + 4))

printf '%s\n' \
  "CI_APP_PORT=$app_port" \
  "CI_STUB_PORT=$stub_port" \
  "CI_DRIVER_PORT=$driver_port" \
  "PROXY_UPSTREAM_PORT=$upstream_port" \
  "PROXY_LISTEN_PORT=$proxy_port" \
  "CI_BASE_URL=http://127.0.0.1:$proxy_port" \
  "DUSK_DRIVER_PORT=$driver_port" \
  "DUSK_DRIVER_URL=http://127.0.0.1:$driver_port"
