#!/usr/bin/env bash
#
# Minimum platform.php for Composer given CI matrix PHP + Laravel.
# Bare minors like "8.0" make illuminate 9.* (^8.0.2) unsatisfiable.
#
# Usage: ci-platform-php.sh <php> <laravel>   e.g. 8.0 9.*
#
set -euo pipefail

php_ver="${1:-}"
laravel_ver="${2:-}"

if [[ -z "$php_ver" ]]; then
  echo "Usage: $0 <php-version> <laravel-constraint>" >&2
  exit 1
fi

case "$php_ver" in
  7.3) php_floor="7.3.33" ;;
  7.4) php_floor="7.4.33" ;;
  8.0) php_floor="8.0.2" ;;
  8.1) php_floor="8.1.0" ;;
  8.2) php_floor="8.2.0" ;;
  8.3) php_floor="8.3.0" ;;
  8.4) php_floor="8.4.0" ;;
  8.5) php_floor="8.5.0" ;;
  *)   php_floor="${php_ver}.0" ;;
esac

laravel_major="${laravel_ver%%.*}"
case "$laravel_major" in
  8)  laravel_floor="7.3.0" ;;
  9)  laravel_floor="8.0.2" ;;
  10) laravel_floor="8.1.0" ;;
  11|12) laravel_floor="8.2.0" ;;
  13) laravel_floor="8.3.0" ;;
  *)  laravel_floor="7.3.0" ;;
esac

printf '%s\n%s\n' "$php_floor" "$laravel_floor" | sort -V | tail -n 1
