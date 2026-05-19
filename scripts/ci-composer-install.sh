#!/usr/bin/env bash
#
# Install dependencies for one CI matrix row (PHP + Laravel).
# Removes composer.lock so PHP 7.3/7.4 jobs do not inherit PHP 8+ locked packages.
#
# Usage: ci-composer-install.sh <php> <laravel>   e.g. 7.3 8.*
#
set -euo pipefail

PHP_VER="${1:-}"
LARAVEL_VER="${2:-}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ -z "$PHP_VER" || -z "$LARAVEL_VER" ]]; then
  echo "Usage: $0 <php-version> <laravel-constraint>" >&2
  exit 1
fi

cd "$ROOT"

if [[ -n "${PHP_BIN:-}" && -x "$PHP_BIN" ]]; then
  PHP_DIR="$(cd "$(dirname "$PHP_BIN")" && pwd)"
  export PATH="${PHP_DIR}:${PATH}"
fi

PLATFORM_PHP="$(bash "$ROOT/scripts/ci-platform-php.sh" "$PHP_VER" "$LARAVEL_VER")"
echo "→ Composer platform.php: ${PLATFORM_PHP} (PHP ${PHP_VER}, Laravel ${LARAVEL_VER})"

rm -f composer.lock
rm -rf vendor

composer config platform.php "$PLATFORM_PHP"

composer require --dev --no-update \
  "illuminate/support:${LARAVEL_VER}" \
  "illuminate/config:${LARAVEL_VER}"

composer update --prefer-dist --optimize-autoloader --no-progress --ansi --no-interaction

composer check-platform-reqs
