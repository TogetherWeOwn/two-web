#!/usr/bin/env bash
# Mutation harness for the atomic-host classifier (TOG-3178).
#
# `--selftest` is only worth its runtime if it FAILS when the classifier is
# wrong. Each mutation below is a plausible wrong version — the old predicate,
# the rejected TOG-1269 relaxation, a hollowed fixture, and the arms that review
# caught returning UNKNOWN on a retirement gate that excludes UNKNOWN from its
# exit code. A SURVIVED line is a hole in the self-test, not a pass.
#
#   bash ci/atomic-host-mutants.sh
#
# Three traps this harness is written around, all of them things that make a
# mutation harness silently report success:
#   - a baseline that is already red scores 100% and means nothing, so it is
#     checked first and the run aborts if it is not green;
#   - a sed/perl expression that matches nothing leaves the file untouched and
#     the suite passes, which reads as SURVIVED, so every mutation is diffed
#     against the original and reported as NOT-APPLIED if it changed nothing;
#   - restoring with `git checkout --` destroys uncommitted work in the tree
#     being reviewed, so this copies `ci/` to a scratch directory and restores
#     from a private `.orig` copy, never from git.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/atomic-mutants.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
cp -r "$HERE" "$WORK/ci"
cd "$WORK"

TARGET=ci/cutover-check.mjs
ORIG="$WORK/cutover-check.mjs.orig"
cp "$TARGET" "$ORIG"

if ! node "$TARGET" --selftest >/dev/null 2>&1; then
  echo "BASELINE IS RED — every mutant would score as killed. Aborting."
  node "$TARGET" --selftest 2>&1 | tail -20
  exit 2
fi
echo "baseline: green"
echo

killed=0 survived=0 broken=0

mutate() { # name, then a perl -0pi expression
  local name="$1" expr="$2"
  cp "$ORIG" "$TARGET"
  perl -0pi -e "$expr" "$TARGET"
  if cmp -s "$ORIG" "$TARGET"; then
    printf 'NOT-APPLIED  %s\n' "$name"
    broken=$((broken + 1))
    cp "$ORIG" "$TARGET"
    return
  fi
  if node "$TARGET" --selftest >/dev/null 2>&1; then
    printf 'SURVIVED     %s\n' "$name"
    survived=$((survived + 1))
  else
    printf 'killed       %s\n' "$name"
    killed=$((killed + 1))
  fi
  cp "$ORIG" "$TARGET"
}

# --- the defect review caught: UNKNOWN on a gate that ignores UNKNOWN --------
mutate 'catch-all arm returns UNKNOWN instead of FAIL' \
  's/(Unclassified, and therefore )/$1/ && s/status: FAIL,\n    detail:\n      `\$\{ATOMIC_HOST\} answered/status: UNKNOWN,\n    detail:\n      `\${ATOMIC_HOST} answered/'

mutate 'a 3xx pointing elsewhere returns UNKNOWN instead of FAIL' \
  's/status: FAIL,\n      detail:\n        `\$\{ATOMIC_HOST\} redirects to/status: UNKNOWN,\n      detail:\n        `\${ATOMIC_HOST} redirects to/'

# A new arm returning UNKNOWN for a status no enumerated case mentions. This is
# the mutation the sweep exists for: mutating the sweep's own assertion instead
# would only prove it is redundant with the enumerated cases, which it is by
# construction — the sweep's job is the arm nobody thought to enumerate.
mutate 'a new arm returns UNKNOWN for a status no named case covers' \
  's/  \/\/ Measured, and not any of the states above\./  if (site.status === 451) return { status: UNKNOWN, detail: "blocked for legal reasons" };\n  \/\/ Measured, and not any of the states above./'

# --- the original defect: status alone, and the wrong sentence ---------------
mutate 'pass on any 3xx (the old "not a 3xx" predicate)' \
  's/if \(shape !== null && shape === controlShape\)/if (true)/'

mutate 'the 403 arm needs no domain-connection marker' \
  's/site\.status === 403 && markersIn\(body, DOMAIN_CONNECTION_ERROR_MARKERS\)\.length > 0/site.status === 403/'

mutate 'the old over-claiming sentence comes back' \
  's/is bound, no active site serving it/still answers, the install is reachable off-CDN,/'

mutate 'adopt the TOG-1269 relaxation (403 + no TWO content = retired)' \
  's/status: FAIL,\n      detail:\n        `\$\{ATOMIC_HOST\} is bound/status: PASS,\n      detail:\n        `\${ATOMIC_HOST} is bound/'

# --- the load-bearing control guard -----------------------------------------
mutate 'drop the control-host guard' \
  's/if \(!isRedirect\(control\.status\)\) \{/if (false) {/'

mutate 'a probe error stops being fatal' \
  's/if \(control\.error \|\| site\.error\) \{/if (false) {/'

# --- the fixture has to stay real -------------------------------------------
mutate 'hollow the fixture body' \
  's/const preAction = classifyAtomicHost\(preActionSite, liveControl\);/const preAction = classifyAtomicHost({ ...preActionSite, body: "" }, liveControl);/'

mutate 'install markers loosened to a bare wp-' \
  "s/const INSTALL_CONTENT_MARKERS = \\[[^\\]]*\\]/const INSTALL_CONTENT_MARKERS = ['wp-']/"

echo
echo "$killed killed, $survived survived, $broken not applied"
[ "$survived" -eq 0 ] && [ "$broken" -eq 0 ]
