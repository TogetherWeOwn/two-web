#!/usr/bin/env bash
#
# Dependency audit gate (TOG-8405): `composer audit` + `npm audit`, failing on
# high/critical advisories. Both read the lockfiles, so this needs no install,
# no vendor/, no node_modules, no database — seconds, not minutes.
#
# Why JSON + a predicate instead of trusting exit codes: composer's advisory
# feed carries entries with no severity field at all (observed live 2026-09-28
# on laravel/framework 8.4.0), and the threshold has to treat those as blocking
# rather than as "not high". The predicate below allowlists low/medium and
# fails on everything else — high, critical, or a severity it has never seen.
# A loud failure on a new severity is the point: someone looks, decides, and
# allowlists it deliberately, instead of a quiet pass nobody chose.
#
# Fail-closed throughout: unparseable output, a crashed tool, or an unknown
# JSON shape is red, never green. "Could not check" must not read as "clean".
#
# No paid services: packagist.org and the npm registry are free public feeds.
# Nothing here phones anything else.
#
# Usage:
#   ./ci/deps-audit.sh            # audit the working tree, exit 0 clean / 1 findings-or-error
#   ./ci/deps-audit.sh --selftest # offline predicate tests on canned fixtures, no network
#
# The selftest runs in the `static` CI job (fast, offline); the audit runs in
# the `deps-audit` job. Check 13 in ci/verify-pipeline.sh pins both halves.

set -euo pipefail

pass() { printf '\033[32mPASS\033[0m  %s\n' "$*"; }
fail() { printf '\033[31mFAIL\033[0m  %s\n' "$*" >&2; }

# --- predicates -----------------------------------------------------------
# Each reads one JSON file and reports. They take a path (not stdin) so the
# selftest can point them at fixtures and so a failure can name the file.

# gate_composer_json <file>: exit 0 iff every advisory is low/medium severity.
gate_composer_json() {
  node -e '
    const fs = require("fs");
    const file = process.argv[1];
    let doc;
    try {
      doc = JSON.parse(fs.readFileSync(file, "utf8"));
    } catch (err) {
      console.error(`::error::composer audit output in ${file} is not JSON (${err.message}) — failing closed; "could not check" is not "clean"`);
      process.exit(1);
    }
    // A clean audit reports `"advisories": []` — an empty array, not an
    // object. That is zero advisories, so it is green. A non-empty array
    // would be a shape this predicate does not understand, so that stays red.
    if (Array.isArray(doc.advisories)) {
      if (doc.advisories.length === 0) {
        console.log("composer audit: clean — 0 advisories");
        process.exit(0);
      }
      console.error(`::error::composer audit output in ${file} carries advisories as a non-empty list — unknown shape, failing closed`);
      process.exit(1);
    }
    if (typeof doc !== "object" || doc === null || typeof doc.advisories !== "object" || doc.advisories === null) {
      console.error(`::error::composer audit output in ${file} has no advisories object — unknown shape, failing closed`);
      process.exit(1);
    }
    const blocking = [];
    let total = 0;
    for (const [pkg, advisories] of Object.entries(doc.advisories)) {
      if (!Array.isArray(advisories)) {
        console.error(`::error::composer advisories for ${pkg} are not a list — unknown shape, failing closed`);
        process.exit(1);
      }
      for (const advisory of advisories) {
        total += 1;
        const severity = String(advisory.severity ?? "unknown").toLowerCase();
        if (severity === "low" || severity === "medium") continue;
        blocking.push(`${pkg} [${advisory.severity ?? "severity missing"}] ${advisory.title ?? advisory.advisoryId ?? "untitled"} ${advisory.link ?? ""}`.trim());
      }
    }
    if (blocking.length > 0) {
      for (const line of blocking) console.error(`::error::composer audit: ${line}`);
      console.error(`::error::composer audit: ${blocking.length} blocking advisor${blocking.length === 1 ? "y" : "ies"} of ${total} total (high/critical/unknown severity)`);
      process.exit(1);
    }
    console.log(`composer audit: clean — ${total} advisor${total === 1 ? "y" : "ies"}, all low/medium`);
  ' "$1"
}

# gate_npm_json <file>: exit 0 iff metadata counts show zero high/critical.
gate_npm_json() {
  node -e '
    const fs = require("fs");
    const file = process.argv[1];
    let doc;
    try {
      doc = JSON.parse(fs.readFileSync(file, "utf8"));
    } catch (err) {
      console.error(`::error::npm audit output in ${file} is not JSON (${err.message}) — failing closed; "could not check" is not "clean"`);
      process.exit(1);
    }
    const counts = doc && doc.metadata && doc.metadata.vulnerabilities;
    if (typeof counts !== "object" || counts === null) {
      console.error(`::error::npm audit output in ${file} has no metadata.vulnerabilities — unknown shape, failing closed`);
      process.exit(1);
    }
    const high = Number(counts.high ?? NaN);
    const critical = Number(counts.critical ?? NaN);
    if (!Number.isInteger(high) || !Number.isInteger(critical)) {
      console.error(`::error::npm audit output in ${file} has non-numeric high/critical counts — unknown shape, failing closed`);
      process.exit(1);
    }
    if (high > 0 || critical > 0) {
      const names = doc.vulnerabilities && typeof doc.vulnerabilities === "object" && !Array.isArray(doc.vulnerabilities)
        ? Object.entries(doc.vulnerabilities)
            .filter(([, v]) => v && (String(v.severity).toLowerCase() === "high" || String(v.severity).toLowerCase() === "critical"))
            .map(([name, v]) => `${name} [${v.severity}]`)
        : [];
      console.error(`::error::npm audit: ${high} high, ${critical} critical${names.length > 0 ? ` (${names.join(", ")})` : ""}`);
      process.exit(1);
    }
    console.log(`npm audit: clean — high 0, critical 0 (info ${counts.info ?? 0}, low ${counts.low ?? 0}, moderate ${counts.moderate ?? 0})`);
  ' "$1"
}

# --- live audit ------------------------------------------------------------

run_audit() {
  local rc=0 work
  work="$(mktemp -d "${TMPDIR:-/tmp}/deps-audit.XXXXXX")"
  trap 'rm -rf "$work"' RETURN

  # composer first: the PHP surface is the larger one and its output names the
  # advisory precisely. --locked audits the lockfile — no vendor/ needed.
  # The tool's own exit code is deliberately ignored: it exits 1 on any
  # advisory severity, so the predicate below is the threshold, not it.
  local composer_bin="${CI_COMPOSER_BIN:-composer}"
  local php_bin="${CI_PHP_BIN:-}"
  if [ -n "$php_bin" ]; then
    "$php_bin" "$composer_bin" audit --locked --no-interaction --format=json > "$work/composer.json" 2> "$work/composer.err" || true
  else
    "$composer_bin" audit --locked --no-interaction --format=json > "$work/composer.json" 2> "$work/composer.err" || true
  fi
  if ! gate_composer_json "$work/composer.json"; then
    if [ ! -s "$work/composer.json" ]; then
      sed 's/^/composer audit stderr: /' "$work/composer.err" >&2 || true
    fi
    rc=1
  fi

  # npm second. Same arrangement: exit code ignored, JSON counts are the gate.
  npm audit --json > "$work/npm.json" 2> "$work/npm.err" || true
  if ! gate_npm_json "$work/npm.json"; then
    if [ ! -s "$work/npm.json" ]; then
      sed 's/^/npm audit stderr: /' "$work/npm.err" >&2 || true
    fi
    rc=1
  fi

  if [ "$rc" -eq 0 ]; then
    echo "dependency audit green: no high/critical advisories in composer.lock or package-lock.json"
  else
    printf '::error::dependency audit red — resolve the advisories above, then re-run\n' >&2
  fi
  return "$rc"
}

# --- selftest ---------------------------------------------------------------
# Offline: canned fixtures through the real predicates. Every case asserts the
# exit code, and every red case asserts the output names the package — a gate
# that fails without saying what failed sends the reader to the log tail.

selftest() {
  local rc=0 n=0 work
  work="$(mktemp -d "${TMPDIR:-/tmp}/deps-audit-selftest.XXXXXX")"
  trap 'rm -rf "$work"' RETURN

  # expect <slug> <red|green> <file> [expected-substring...]
  expect() {
    local slug="$1" want="$2" file="$3"; shift 3
    local out status
    n=$((n + 1))
    if out=$("$@" "$file" 2>&1); then status=green; else status=red; fi
    if [ "$status" != "$want" ]; then
      fail "$slug: wanted $want, got $status"
      printf '%s\n' "$out" | sed 's/^/        /'
      rc=1
      return
    fi
    local needle
    for needle in $EXPECTED_NEEDLES; do
      if ! grep -qF -- "$needle" <<< "$out"; then
        fail "$slug: $want, but the output never names \`${needle}\`"
        printf '%s\n' "$out" | sed 's/^/        /'
        rc=1
        return
      fi
    done
    pass "$slug"
  }

  EXPECTED_NEEDLES=""
  cat > "$work/composer-high.json" <<'JSON'
{"advisories": {"laravel/framework": [
  {"advisoryId": "PKSA-m5cs-t1y6-qpcs", "packageName": "laravel/framework", "title": "Laravel Framework: Temporary Signed URL Path Confusion", "link": "https://github.com/advisories/GHSA-crmm-hgp2-wgrp", "severity": "medium"},
  {"advisoryId": "PKSA-3r5d-mb8f-1qw9", "packageName": "laravel/framework", "title": "Laravel Framework: CRLF injection in default email rule", "link": "https://github.com/advisories/GHSA-5vg9-5847-vvmq", "severity": "high"}
]}, "abandoned": [], "filter": []}
JSON
  EXPECTED_NEEDLES="laravel/framework"
  expect composer-high-goes-red red "$work/composer-high.json" gate_composer_json

  cat > "$work/composer-medium-only.json" <<'JSON'
{"advisories": {"laravel/framework": [
  {"advisoryId": "PKSA-m5cs-t1y6-qpcs", "packageName": "laravel/framework", "title": "Laravel Framework: Temporary Signed URL Path Confusion", "link": "https://github.com/advisories/GHSA-crmm-hgp2-wgrp", "severity": "medium"}
]}, "abandoned": [], "filter": []}
JSON
  EXPECTED_NEEDLES=""
  expect composer-medium-only-stays-green green "$work/composer-medium-only.json" gate_composer_json

  printf '{"advisories": [], "abandoned": [], "filter": []}' > "$work/composer-clean.json"
  expect composer-clean-stays-green green "$work/composer-clean.json" gate_composer_json

  # The feed carries advisories with no severity at all (seen live on
  # laravel/framework 8.4.0). Those must block: an unknown severity is not a
  # low one.
  cat > "$work/composer-no-severity.json" <<'JSON'
{"advisories": {"laravel/framework": [
  {"advisoryId": "PKSA-img0-upl0-ad00", "packageName": "laravel/framework", "title": "Image upload bypass", "link": "https://example.invalid/advisory"}
]}, "abandoned": [], "filter": []}
JSON
  EXPECTED_NEEDLES="laravel/framework"
  expect composer-missing-severity-goes-red red "$work/composer-no-severity.json" gate_composer_json

  printf 'not json at all' > "$work/composer-malformed.json"
  EXPECTED_NEEDLES="failing closed"
  expect composer-malformed-goes-red red "$work/composer-malformed.json" gate_composer_json

  cat > "$work/npm-critical.json" <<'JSON'
{"auditReportVersion": 2,
 "vulnerabilities": {"minimist": {"name": "minimist", "severity": "critical", "range": "1.0.0 - 1.2.5", "fixAvailable": {"name": "minimist", "version": "1.2.8"}}},
 "metadata": {"vulnerabilities": {"info": 0, "low": 0, "moderate": 0, "high": 0, "critical": 1, "total": 1}}}
JSON
  EXPECTED_NEEDLES="minimist"
  expect npm-critical-goes-red red "$work/npm-critical.json" gate_npm_json

  cat > "$work/npm-high-only.json" <<'JSON'
{"auditReportVersion": 2,
 "vulnerabilities": {"lodash": {"name": "lodash", "severity": "high", "range": "<=4.17.20"}},
 "metadata": {"vulnerabilities": {"info": 0, "low": 0, "moderate": 0, "high": 1, "critical": 0, "total": 1}}}
JSON
  EXPECTED_NEEDLES="lodash"
  expect npm-high-only-goes-red red "$work/npm-high-only.json" gate_npm_json

  # High is the threshold: moderate alone must not block, or every advisory
  # becomes an emergency and the gate gets routed around.
  cat > "$work/npm-moderate-only.json" <<'JSON'
{"auditReportVersion": 2,
 "vulnerabilities": {"lodash": {"name": "lodash", "severity": "moderate", "range": "<=4.17.20"}},
 "metadata": {"vulnerabilities": {"info": 0, "low": 0, "moderate": 1, "high": 0, "critical": 0, "total": 1}}}
JSON
  EXPECTED_NEEDLES=""
  expect npm-moderate-only-stays-green green "$work/npm-moderate-only.json" gate_npm_json

  printf '{"auditReportVersion": 2, "vulnerabilities": {}, "metadata": {"vulnerabilities": {"info": 0, "low": 0, "moderate": 0, "high": 0, "critical": 0, "total": 0}}}' > "$work/npm-clean.json"
  expect npm-clean-stays-green green "$work/npm-clean.json" gate_npm_json

  printf '{"auditReportVersion": 2}' > "$work/npm-no-metadata.json"
  EXPECTED_NEEDLES="failing closed"
  expect npm-unknown-shape-goes-red red "$work/npm-no-metadata.json" gate_npm_json

  printf '\n'
  if [ "$rc" -ne 0 ]; then
    fail "the audit predicate does not gate what it claims to. Fix it before trusting the job."
  else
    printf '\033[1m%d/%d — the audit predicate fails high/critical/unknown and passes everything else.\033[0m\n' "$n" "$n"
  fi
  return "$rc"
}

case "${1:---run}" in
  --selftest) selftest ;;
  --run) run_audit ;;
  *) printf 'usage: %s [--run|--selftest]\n' "$0" >&2; exit 2 ;;
esac
