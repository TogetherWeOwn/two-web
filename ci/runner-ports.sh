#!/usr/bin/env bash
set -euo pipefail

# All five self-hosted runners share one network namespace. GitHub schedules only
# one job at a time on a runner, so give each runner a stable ten-port block.
# Numeric coolify-vps-* suffixes are collision-free; the checksum fallback keeps
# local/renamed runners usable without restoring globally fixed ports.
runner_name="${RUNNER_NAME:?RUNNER_NAME is required}"
suffix="${runner_name#coolify-vps-}"

if [[ "$runner_name" == coolify-vps-* && "$suffix" =~ ^[0-9]+$ ]]; then
  slot=$((10#$suffix))
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
