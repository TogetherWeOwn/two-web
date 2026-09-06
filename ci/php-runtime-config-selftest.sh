#!/usr/bin/env bash
#
# Pins security-sensitive settings in the repository-owned nginx template.
#
# Nixpacks starts PHP-FPM behind nginx.template.conf. The PHP_ADMIN_VALUE
# FastCGI parameter applies the PHP_INI_SYSTEM directive without replacing
# Nixpacks' generated php.ini or extension scan directories. The host map keeps
# the crawler directive on staging without making production noindex.
#
# Usage: ./ci/php-runtime-config-selftest.sh

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="${REPO_ROOT}/nginx.template.conf"

fail() { printf '\033[31mFAIL: %s\033[0m\n' "$*" >&2; }
pass() { printf '\033[32mPASS: %s\033[0m\n' "$*"; }

if [ ! -r "$CONFIG" ]; then
  fail "nginx.template.conf is missing or unreadable"
  exit 1
fi

php_location="$({
  awk '
    /^[[:space:]]*location[[:space:]]+~.*\\\.php\$[[:space:]]*\{/ { in_php = 1; depth = 1; next }
    in_php {
      line = $0
      opens = gsub(/\{/, "{", line)
      closes = gsub(/\}/, "}", line)
      if (depth > 0) print
      depth += opens - closes
      if (depth == 0) exit
    }
  ' "$CONFIG"
} 2>/dev/null)"

if [ -z "$php_location" ]; then
  fail "nginx.template.conf has no PHP FastCGI location block"
  exit 1
fi

setting_count="$(grep -Ec '^[[:space:]]*fastcgi_param[[:space:]]+PHP_ADMIN_VALUE[[:space:]]+"expose_php=Off";[[:space:]]*$' <<< "$php_location")"

if [ "$setting_count" -ne 1 ]; then
  fail "the PHP FastCGI location must set PHP_ADMIN_VALUE to expose_php=Off exactly once (found ${setting_count})"
  exit 1
fi

pass "PHP-FPM receives expose_php=Off from the repository-owned nginx template"

robots_map="$({
  awk '
    /^[[:space:]]*map[[:space:]]+\$host[[:space:]]+\$robots_tag[[:space:]]*\{/ { in_map = 1; depth = 1; print; next }
    in_map {
      line = $0
      opens = gsub(/\{/, "{", line)
      closes = gsub(/\}/, "}", line)
      print
      depth += opens - closes
      if (depth == 0) exit
    }
  ' "$CONFIG"
} 2>/dev/null)"

if [ -z "$robots_map" ]; then
  fail 'nginx.template.conf has no $host -> $robots_tag map'
  exit 1
fi

staging_rule_count="$(grep -Ec '^[[:space:]]*staging\.togetherweown\.com[[:space:]]+"noindex, nofollow";[[:space:]]*$' <<< "$robots_map")"
default_rule_count="$(grep -Ec '^[[:space:]]*default[[:space:]]+"";[[:space:]]*$' <<< "$robots_map")"
header_count="$(grep -Ec '^[[:space:]]*add_header[[:space:]]+X-Robots-Tag[[:space:]]+\$robots_tag[[:space:]]+always;[[:space:]]*$' "$CONFIG")"

if [ "$staging_rule_count" -ne 1 ] || [ "$default_rule_count" -ne 1 ] || [ "$header_count" -ne 1 ]; then
  fail "staging must map exactly once to noindex, nofollow, the default must be empty, and X-Robots-Tag must be emitted from the map with always"
  exit 1
fi

pass "nginx emits X-Robots-Tag only for staging.togetherweown.com"
