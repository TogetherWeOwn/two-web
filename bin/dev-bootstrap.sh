#!/usr/bin/env bash
#
# Get a working PHP + Composer without installing anything system-wide.
#
# Why this exists: the README's normal path assumes PHP, Composer and Docker are
# already on your machine. Several of our working environments have Node and git
# and nothing else, with no root. Rather than each person inventing their own
# fix, this puts a self-contained PHP into ./.tooling/bin (gitignored) and stops.
#
# It does not touch your system, your shell profile, or an existing PHP. If you
# already have PHP 8.2+ with pdo_pgsql, this script tells you so and exits.
#
# Usage:  bin/dev-bootstrap.sh
set -euo pipefail

PHP_VERSION="8.3.29"
PHP_URL="https://dl.static-php.dev/static-php-cli/common/php-${PHP_VERSION}-cli-linux-x86_64.tar.gz"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BIN="${ROOT}/.tooling/bin"

has_usable_php() {
  command -v php >/dev/null 2>&1 &&
    php -r 'exit(PHP_VERSION_ID >= 80200 && extension_loaded("pdo_pgsql") ? 0 : 1);' 2>/dev/null
}

if has_usable_php && command -v composer >/dev/null 2>&1; then
  echo "PHP $(php -r 'echo PHP_VERSION;') and Composer are already usable. Nothing to do."
  exit 0
fi

mkdir -p "${BIN}"

if [ ! -x "${BIN}/php" ]; then
  echo "Downloading PHP ${PHP_VERSION} (static, ~30 MB)..."
  tmp="$(mktemp -d)"
  trap 'rm -rf "${tmp}"' EXIT
  curl -fsSL -o "${tmp}/php.tgz" "${PHP_URL}"
  tar xzf "${tmp}/php.tgz" -C "${BIN}"
  chmod +x "${BIN}/php"
fi

if [ ! -f "${BIN}/composer" ]; then
  echo "Installing Composer..."
  curl -fsSL -o "${BIN}/composer-setup.php" https://getcomposer.org/installer
  "${BIN}/php" "${BIN}/composer-setup.php" --quiet --install-dir="${BIN}" --filename=composer
  rm -f "${BIN}/composer-setup.php"
fi

echo
echo "PHP $("${BIN}/php" -r 'echo PHP_VERSION;') and Composer $("${BIN}/php" "${BIN}/composer" --version --no-ansi 2>/dev/null | awk '{print $3}') are in .tooling/bin"

# Filament (TWO-33) needs ext-intl, which this build does not carry. Real dev and
# production VMs do. Say so now rather than at install time.
"${BIN}/php" -r 'exit(extension_loaded("intl") ? 0 : 1);' 2>/dev/null ||
  echo "Note: no ext-intl in this build. Filament will need --ignore-platform-req=ext-intl here."

cat <<EOF

Add it to your PATH for this shell:

    export PATH="${BIN}:\$PATH"

Then carry on with the README. You still need a Postgres — either

    docker compose up -d                  # if you have Docker

or point DB_HOST / DB_PORT / DB_DATABASE / DB_USERNAME / DB_PASSWORD in .env at
any Postgres 14+ you can reach, with a database you are allowed to drop.
EOF
