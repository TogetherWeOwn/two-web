#!/usr/bin/env bash
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
php_bin=${PHP_BINARY:-php}
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

calls="$work/calls.jsonl"
receipt="$work/receipt.json"

printf '%s\n' \
  '{"method":"GET","pathname":"/oauth2/authorize"}' \
  '{"method":"POST","pathname":"/oauth2/token"}' \
  '{"method":"GET","pathname":"/users/@me"}' \
  '{"method":"POST","pathname":"/internal/actions"}' > "$calls"
printf '%s\n' '{"body":{"action":"event.upsert"}}' > "$receipt"

"$php_bin" "$root/ci/dusk-receipts-check.php" "$calls" "$receipt" >/dev/null
echo 'PASS  complete OAuth and bot receipts are accepted'

for scenario in wrong-action missing-action; do
  case "$scenario" in
    wrong-action) printf '%s\n' '{"body":{"action":"event.delete"}}' > "$work/mutated-receipt.json" ;;
    missing-action) printf '%s\n' '{"body":{}}' > "$work/mutated-receipt.json" ;;
  esac

  status=0
  "$php_bin" "$root/ci/dusk-receipts-check.php" "$calls" "$work/mutated-receipt.json" > "$work/out" 2> "$work/err" || status=$?
  if [[ "$status" -ne 1 ]]; then
    echo "FAIL  $scenario exited $status instead of 1" >&2
    exit 1
  fi

  grep -Fxq 'Expected event.upsert receipt was absent.' "$work/err"
  if grep -Fq 'OAuth and bot receipts present.' "$work/out" "$work/err"; then
    echo "FAIL  $scenario emitted the success line" >&2
    exit 1
  fi

  echo "PASS  $scenario makes the receipt gate red"
done

for missing in /oauth2/authorize /internal/actions; do
  grep -v "\"pathname\":\"$missing\"" "$calls" > "$work/mutated.jsonl"

  if "$php_bin" "$root/ci/dusk-receipts-check.php" "$work/mutated.jsonl" "$receipt" > "$work/out" 2>&1; then
    echo "FAIL  missing $missing was accepted" >&2
    exit 1
  fi

  grep -q "Expected Dusk call $missing was absent" "$work/out"
  echo "PASS  missing $missing makes the receipt gate red"
done
