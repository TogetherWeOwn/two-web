#!/usr/bin/env bash
set -euo pipefail

results_dir="${1:-.lighthouseci}"

# lhci exits non-zero for warn-level assertions as well as actual breaches.
# lh-annotate.mjs owns that distinction. Reaching this guard with assertion
# results means Lighthouse completed and the annotation step already made the
# verdict; only a failure with no results is an unmeasured, broken run.
if [ -f "$results_dir/assertion-results.json" ]; then
  echo "Lighthouse produced assertion results; the annotation step owns the verdict."
  exit 0
fi

echo "::error::the Lighthouse step failed without assertion results — see the budget-reports artifact for the browser or harness failure."
exit 1
