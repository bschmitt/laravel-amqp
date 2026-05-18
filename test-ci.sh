#!/usr/bin/env bash
#
# Local runner for .github/workflows/ci.yml
# Runs matrix jobs in order: PHP 7.4 → 8.0 → 8.1 → … → 8.5
#
# Usage:
#   ./test-ci.sh                    # composer for all jobs; phpunit only if PATH PHP matches
#   ./test-ci.sh --full             # composer + FULL phpunit per job (uses Laragon PHP bins)
#   ./test-ci.sh --job 8.3:13.*     # single job
#   ./test-ci.sh --deps-only        # composer only (no phpunit)
#   ./test-ci.sh --from-php 8.3     # skip jobs below PHP 8.3 (installed versions only)
#   ./test-ci.sh --list-php         # show detected PHP installations + extensions
#   ./test-ci.sh --fix-extensions   # enable ext-sockets in Laragon php.ini files
#   ./test-ci.sh --install-php      # download all missing matrix PHP (windows.php.net / php.net)
#   ./test-ci.sh --no-download      # never auto-download (--full downloads by default)
#   ./test-ci.sh --download-php     # auto-download missing PHP even without --full
#   ./test-ci.sh --php-root /e/laragon/bin/php   # override auto-detected Laragon php folder
#   ./test-ci.sh --skip-psr4
#   ./test-ci.sh --help
#
set -euo pipefail

export XDEBUG_MODE="${XDEBUG_MODE:-off}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

MATRIX=(
  "7.4:8.*"
  "8.0:9.*"
  "8.1:10.*"
  "8.2:10.*"
  "8.2:11.*"
  "8.2:12.*"
  "8.3:12.*"
  "8.3:13.*"
  "8.4:12.*"
  "8.4:13.*"
  "8.5:13.*"
)

REQUIRED_EXTENSIONS=(sockets)

DEPS_ONLY=0
FULL_MODE=0
LIST_PHP=0
FIX_EXTENSIONS=0
INSTALL_PHP_ONLY=0
AUTO_DOWNLOAD_PHP=0
NO_DOWNLOAD_EXPLICIT=0
REQUESTED_JOB=""
FROM_PHP=""
SKIP_PSR4=0
PHP_ROOT="${PHP_ROOT:-}"
PHP_INSTALL_ROOT=""
PHP_CACHE_DIR=""

usage() {
  sed -n '2,17p' "$0" | sed 's/^# \{0,1\}//'
  exit 0
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --deps-only) DEPS_ONLY=1; shift ;;
    --full) FULL_MODE=1; shift ;;
    --job) REQUESTED_JOB="${2:-}"; shift 2 ;;
    --from-php) FROM_PHP="${2:-}"; shift 2 ;;
    --list-php) LIST_PHP=1; shift ;;
    --fix-extensions) FIX_EXTENSIONS=1; shift ;;
    --install-php) INSTALL_PHP_ONLY=1; AUTO_DOWNLOAD_PHP=1; shift ;;
    --no-download) AUTO_DOWNLOAD_PHP=0; NO_DOWNLOAD_EXPLICIT=1; shift ;;
    --download-php) AUTO_DOWNLOAD_PHP=1; shift ;;
    --php-root) PHP_ROOT="${2:-}"; shift 2 ;;
    --skip-psr4) SKIP_PSR4=1; shift ;;
    --help|-h) usage ;;
    *) echo "Unknown option: $1" >&2; usage ;;
  esac
done

# --full auto-downloads missing PHP from windows.php.net unless --no-download
if [[ "$FULL_MODE" -eq 1 && "$NO_DOWNLOAD_EXPLICIT" -eq 0 ]]; then
  AUTO_DOWNLOAD_PHP=1
fi

if [[ "$FIX_EXTENSIONS" -eq 1 ]]; then
  exec bash "$ROOT/scripts/enable-laragon-extensions.sh"
fi

if ! command -v composer >/dev/null 2>&1; then
  echo "composer not found in PATH" >&2
  exit 1
fi

DEFAULT_PHP="$(command -v php 2>/dev/null || true)"
CURRENT_PHP=""
if [[ -n "$DEFAULT_PHP" ]]; then
  CURRENT_PHP="$("$DEFAULT_PHP" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
fi

# Laragon folder that already contains php-8.x installs (not the package directory).
resolve_php_install_root() {
  local php_bin ver_dir parent root

  if [[ -n "$PHP_ROOT" && -d "$PHP_ROOT" ]]; then
    echo "$PHP_ROOT"
    return 0
  fi

  if [[ -n "$DEFAULT_PHP" && -x "$DEFAULT_PHP" ]]; then
    ver_dir="$(cd "$(dirname "$DEFAULT_PHP")" && pwd)"
    parent="$(dirname "$ver_dir")"
    if [[ -d "$parent" ]] && compgen -G "${parent}/php-*" >/dev/null 2>&1; then
      echo "$parent"
      return 0
    fi
  fi

  for root in /e/laragon/bin/php /c/laragon/bin/php /e/Laragon/bin/php /c/Laragon/bin/php; do
    if [[ -d "$root" ]] && compgen -G "${root}/php-*" >/dev/null 2>&1; then
      echo "$root"
      return 0
    fi
  done

  for root in /e/laragon/bin/php /c/laragon/bin/php /e/Laragon/bin/php /c/Laragon/bin/php; do
    if [[ -d "$root" ]]; then
      echo "$root"
      return 0
    fi
  done

  return 1
}

PHP_INSTALL_ROOT="$(resolve_php_install_root || true)"
if [[ -n "$PHP_INSTALL_ROOT" ]]; then
  PHP_ROOT="$PHP_INSTALL_ROOT"
  PHP_CACHE_DIR="${PHP_INSTALL_ROOT}/.downloads"
  export PHP_ROOT PHP_CACHE_DIR
fi

php_search_roots() {
  if [[ -n "$PHP_INSTALL_ROOT" && -d "$PHP_INSTALL_ROOT" ]]; then
    echo "$PHP_INSTALL_ROOT"
    return
  fi
  if [[ -n "$PHP_ROOT" ]]; then
    echo "$PHP_ROOT"
    return
  fi
  echo "/e/laragon/bin/php"
  echo "/c/laragon/bin/php"
}

find_php_binary() {
  local want="$1"
  local root dir candidate

  while IFS= read -r root; do
    [[ -d "$root" ]] || continue
    for dir in "$root"/php-*"$want"* "$root"/php-src-php-"$want"*; do
      [[ -d "$dir" ]] || continue
      for candidate in "$dir/php.exe" "$dir/php"; do
        if [[ -x "$candidate" ]]; then
          echo "$candidate"
          return 0
        fi
      done
    done
  done < <(php_search_roots)

  return 1
}

ensure_php_installed() {
  local php_ver="$1"
  if find_php_binary "$php_ver" >/dev/null 2>&1; then
    return 0
  fi
  if [[ "$AUTO_DOWNLOAD_PHP" -ne 1 ]]; then
    return 1
  fi
  if [[ -z "$PHP_INSTALL_ROOT" ]]; then
    echo "✗ Cannot find existing PHP install folder (use --php-root or add PHP via Laragon)" >&2
    return 1
  fi
  echo "→ PHP ${php_ver} not found — downloading into: ${PHP_INSTALL_ROOT}"
  echo "   Cache: ${PHP_CACHE_DIR}"
  echo "   Source: https://windows.php.net/downloads/releases/ (php.net releases)"
  if ! PHP_ROOT="$PHP_INSTALL_ROOT" CACHE_DIR="$PHP_CACHE_DIR" \
    bash "$ROOT/scripts/install-php-windows.sh" "$php_ver"; then
    echo "✗ Failed to install PHP ${php_ver} (network or unzip). Retry or install via Laragon." >&2
    return 1
  fi
  PHP_ROOT="$PHP_INSTALL_ROOT" bash "$ROOT/scripts/enable-laragon-extensions.sh" 2>/dev/null || true
  if find_php_binary "$php_ver" >/dev/null 2>&1; then
    return 0
  fi
  echo "✗ PHP ${php_ver} installed but binary not detected" >&2
  return 1
}

install_all_matrix_php() {
  local php_ver
  while IFS= read -r php_ver; do
    ensure_php_installed "$php_ver" || return 1
  done < <(list_unique_matrix_php)
}

php_has_extension() {
  local php_bin="$1"
  local ext="$2"
  "$php_bin" -m 2>/dev/null | grep -qi "^${ext}$"
}

check_php_extensions() {
  local php_bin="$1"
  local ext missing=()

  for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if ! php_has_extension "$php_bin" "$ext"; then
      missing+=("$ext")
    fi
  done

  if [[ ${#missing[@]} -gt 0 ]]; then
    echo "✗ Missing PHP extensions: ${missing[*]}"
    echo "  Fix: ./test-ci.sh --fix-extensions"
    echo "  Or in php.ini: extension=sockets"
    echo "  Ini: $("$php_bin" --ini 2>/dev/null | head -5)"
    return 1
  fi
  return 0
}

list_unique_matrix_php() {
  local seen="" entry php_ver
  for entry in "${MATRIX[@]}"; do
    php_ver="${entry%%:*}"
    if [[ " $seen " != *" $php_ver "* ]]; then
      seen="${seen} ${php_ver}"
      echo "$php_ver"
    fi
  done
}

list_installed_php() {
  echo "Detected PHP installations (required: ${REQUIRED_EXTENSIONS[*]}):"
  local php_ver bin exts
  while IFS= read -r php_ver; do
    if bin="$(find_php_binary "$php_ver" 2>/dev/null)"; then
      exts="ok"
      for ext in "${REQUIRED_EXTENSIONS[@]}"; do
        php_has_extension "$bin" "$ext" || exts="missing ${ext}"
      done
      printf "  PHP %-4s → %s (%s) [%s]\n" "$php_ver" "$bin" \
        "$("$bin" -r 'echo PHP_VERSION;' 2>/dev/null)" "$exts"
    else
      printf "  PHP %-4s → (not installed — Laragon → PHP → Version)\n" "$php_ver"
    fi
  done < <(list_unique_matrix_php)
}

if [[ "$LIST_PHP" -eq 1 ]]; then
  list_installed_php
  exit 0
fi

if [[ "$INSTALL_PHP_ONLY" -eq 1 ]]; then
  if [[ -z "$PHP_INSTALL_ROOT" ]]; then
    echo "✗ No PHP install directory found. Install one PHP via Laragon first, or pass --php-root." >&2
    exit 1
  fi
  echo "Installing missing PHP versions into: $PHP_INSTALL_ROOT"
  install_all_matrix_php
  echo ""
  list_installed_php
  exit 0
fi

COMPOSER_JSON_BAK=""
COMPOSER_LOCK_BAK=""

backup_composer_files() {
  COMPOSER_JSON_BAK="$(mktemp)"
  cp composer.json "$COMPOSER_JSON_BAK"
  if [[ -f composer.lock ]]; then
    COMPOSER_LOCK_BAK="$(mktemp)"
    cp composer.lock "$COMPOSER_LOCK_BAK"
  fi
}

restore_composer_files() {
  if [[ -n "$COMPOSER_JSON_BAK" && -f "$COMPOSER_JSON_BAK" ]]; then
    cp "$COMPOSER_JSON_BAK" composer.json
    rm -f "$COMPOSER_JSON_BAK"
    COMPOSER_JSON_BAK=""
  fi
  if [[ -n "$COMPOSER_LOCK_BAK" && -f "$COMPOSER_LOCK_BAK" ]]; then
    cp "$COMPOSER_LOCK_BAK" composer.lock
    rm -f "$COMPOSER_LOCK_BAK"
    COMPOSER_LOCK_BAK=""
  fi
  composer config --unset platform.php 2>/dev/null || true
}

cleanup() {
  restore_composer_files
}

trap cleanup EXIT

version_ge() {
  printf '%s\n%s\n' "$2" "$1" | sort -V -C
}

php_matches_bin() {
  local want="$1"
  local bin="$2"
  [[ "$("$bin" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)" == "$want" ]]
}

platform_php_for_job() {
  bash "$ROOT/scripts/ci-platform-php.sh" "$1" "${2:-}"
}

should_run_job() {
  local php_ver="$1"
  [[ -z "$FROM_PHP" ]] || version_ge "$php_ver" "$FROM_PHP"
}

run_composer() {
  local php_bin="$1"
  shift
  local php_dir
  php_dir="$(cd "$(dirname "$php_bin")" && pwd)"
  PATH="${php_dir}:${PATH}" XDEBUG_MODE=off composer "$@"
}

run_phpunit() {
  local php_bin="$1"
  XDEBUG_MODE=off "$php_bin" vendor/bin/phpunit
}

resolve_php_for_job() {
  local php_ver="$1"
  local bin

  if bin="$(find_php_binary "$php_ver" 2>/dev/null)"; then
    echo "$bin"
    return 0
  fi

  if [[ -n "$DEFAULT_PHP" ]] && php_matches_bin "$php_ver" "$DEFAULT_PHP"; then
    echo "$DEFAULT_PHP"
    return 0
  fi

  return 1
}

check_psr4_compliance() {
  echo ""
  echo "============================================================"
  echo "PSR-4 autoload check (composer dump-autoload)"
  echo "============================================================"
  local output
  output="$(composer dump-autoload -o 2>&1)" || true
  if echo "$output" | grep -q "does not comply with psr-4"; then
    echo "$output" | grep "does not comply with psr-4" || true
    echo "✗ PSR-4 violations found"
    return 1
  fi
  echo "✓ All classes are PSR-4 compliant"
  return 0
}

run_job() {
  local job_index="$1"
  local job_total="$2"
  local php_ver="$3"
  local laravel_ver="$4"
  local label="PHP ${php_ver} + Laravel ${laravel_ver}"
  local php_bin platform_php

  echo ""
  echo "============================================================"
  echo "JOB ${job_index}/${job_total}: ${label}"
  echo "============================================================"

  if [[ "$FULL_MODE" -eq 1 || "$AUTO_DOWNLOAD_PHP" -eq 1 ]]; then
    ensure_php_installed "$php_ver" || true
  fi

  if ! php_bin="$(resolve_php_for_job "$php_ver")"; then
    if [[ "$AUTO_DOWNLOAD_PHP" -eq 1 ]]; then
      echo "✗ FAIL — could not install PHP ${php_ver}"
      return 1
    fi
    echo "⊘ SKIP — PHP ${php_ver} not installed (use --full or --install-php)"
    return 2
  fi

  echo "Using: $php_bin ($("$php_bin" -r 'echo PHP_VERSION;' 2>/dev/null))"

  if ! check_php_extensions "$php_bin"; then
    return 1
  fi

  platform_php="$(platform_php_for_job "$php_ver" "$laravel_ver")"

  backup_composer_files
  run_composer "$php_bin" config platform.php "$platform_php"

  echo "→ composer require (CI step)..."
  if ! run_composer "$php_bin" require --dev --no-update \
    "illuminate/support:${laravel_ver}" \
    "illuminate/config:${laravel_ver}"; then
    echo "✗ FAIL: composer require — ${label}"
    restore_composer_files
    return 1
  fi

  echo "→ composer update (CI step)..."
  if ! run_composer "$php_bin" update --prefer-dist --optimize-autoloader --no-progress --ansi --no-interaction; then
    echo "✗ FAIL: composer update — ${label}"
    restore_composer_files
    return 1
  fi

  echo "✓ Composer OK — ${label}"

  if [[ "$DEPS_ONLY" -eq 1 ]]; then
    restore_composer_files
    return 0
  fi

  echo "→ phpunit — full test suite (CI step)..."
  if ! run_phpunit "$php_bin"; then
    echo "✗ FAIL: phpunit — ${label}"
    restore_composer_files
    return 1
  fi

  echo "✓ PHPUnit OK — ${label}"
  restore_composer_files
  return 0
}

JOBS=()
if [[ -n "$REQUESTED_JOB" ]]; then
  [[ "$REQUESTED_JOB" == *:* ]] || { echo "Use PHP:Laravel e.g. 8.3:13.*" >&2; exit 1; }
  JOBS+=("$REQUESTED_JOB")
else
  JOBS=("${MATRIX[@]}")
fi

FILTERED_JOBS=()
for entry in "${JOBS[@]}"; do
  php_ver="${entry%%:*}"
  should_run_job "$php_ver" && FILTERED_JOBS+=("$entry")
done

JOB_TOTAL=${#FILTERED_JOBS[@]}

echo "test-ci.sh — simulating .github/workflows/ci.yml"
echo "Package: $ROOT"
echo "PHP install dir: ${PHP_INSTALL_ROOT:-not detected}"
echo "Download cache: ${PHP_CACHE_DIR:-n/a}"
echo "PATH PHP: ${CURRENT_PHP:-none} (${DEFAULT_PHP:-n/a})"
echo "Mode: $([[ "$FULL_MODE" -eq 1 ]] && echo '--full' || echo 'default') | Download PHP: $([[ "$AUTO_DOWNLOAD_PHP" -eq 1 ]] && echo yes || echo no)"
echo "Order: PHP 7.4 → 8.0 → 8.1 → 8.2 → 8.3 → 8.4 → 8.5"
echo "Jobs: ${JOB_TOTAL}"
[[ -n "$FROM_PHP" ]] && echo "From PHP: ${FROM_PHP}"
list_installed_php
echo ""

if [[ "$SKIP_PSR4" -eq 0 ]]; then
  check_psr4_compliance || exit 1
fi

PASSED=0
FAILED=0
SKIPPED=0
JOB_INDEX=0

for entry in "${FILTERED_JOBS[@]}"; do
  JOB_INDEX=$((JOB_INDEX + 1))
  php_ver="${entry%%:*}"
  laravel_ver="${entry#*:}"
  set +e
  run_job "$JOB_INDEX" "$JOB_TOTAL" "$php_ver" "$laravel_ver"
  status=$?
  set -e
  case "$status" in
    0) PASSED=$((PASSED + 1)) ;;
    2) SKIPPED=$((SKIPPED + 1)) ;;
    *) FAILED=$((FAILED + 1)) ;;
  esac
done

echo ""
echo "============================================================"
echo "SUMMARY"
echo "============================================================"
echo "Jobs passed                 : $PASSED / ${JOB_TOTAL}"
echo "Jobs failed                 : $FAILED"
echo "Jobs skipped (no PHP)       : $SKIPPED"
if [[ "$FAILED" -gt 0 ]] || [[ "$SKIPPED" -gt 0 ]]; then
  echo ""
  echo "Missing PHP: ./test-ci.sh --install-php  (downloads from windows.php.net)"
  echo "Missing ext-sockets: ./test-ci.sh --fix-extensions"
  echo "Skip download: ./test-ci.sh --full --no-download"
fi
if [[ "$DEPS_ONLY" -eq 0 ]]; then
  echo "Start RabbitMQ before integration tests."
fi
echo "============================================================"

# Exit 1 only on real failures (not skipped missing PHP)
[[ "$FAILED" -eq 0 ]]
