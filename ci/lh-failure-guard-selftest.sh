#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

mkdir -p "$work/results"

if "$root/ci/lh-failure-guard.sh" "$work/results" > "$work/missing.out" 2>&1; then
  echo "FAIL: a Lighthouse failure without assertion results passed"
  exit 1
fi
grep -q 'failed without assertion results' "$work/missing.out"

printf '[]\n' > "$work/results/assertion-results.json"
"$root/ci/lh-failure-guard.sh" "$work/results" > "$work/present.out"
grep -q 'annotation step owns the verdict' "$work/present.out"

echo "2 Lighthouse failure-guard assertions passed."
