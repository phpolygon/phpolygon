#!/usr/bin/env bash
#
# install-profilers.sh - install SPX and Excimer PHP extensions for PHPolygon
# dev profiling. Both are C-extensions that Composer cannot install directly.
#
# Usage:
#   ./scripts/install-profilers.sh           # install both
#   ./scripts/install-profilers.sh spx       # install only SPX
#   ./scripts/install-profilers.sh excimer   # install only Excimer
#
# After install, enable via env vars:
#   SPX_ENABLED=1 php examples/...
#   PHPOLYGON_EXCIMER=1 php examples/...

set -euo pipefail

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_INI_DIR="$(php --ini | grep 'Scan for additional' | sed 's/.*: //')"
EXT_DIR="$(php-config --extension-dir)"

if [[ -z "${PHP_INI_DIR}" || "${PHP_INI_DIR}" == "(none)" ]]; then
  echo "Could not detect a php.ini scan dir. Add the .ini lines manually after install." >&2
  PHP_INI_DIR=""
fi

echo "PHP ${PHP_VERSION} detected"
echo "Extension dir: ${EXT_DIR}"
echo "Scan dir:      ${PHP_INI_DIR:-<none>}"
echo

target="${1:-both}"

install_spx() {
  echo "==> Installing SPX"
  if php -m | grep -qi '^spx$'; then
    echo "    SPX is already loaded - skipping build."
    return
  fi

  local tmp
  tmp="$(mktemp -d)"
  trap "rm -rf '${tmp}'" RETURN

  git clone --depth 1 https://github.com/NoiseByNorthwest/php-spx.git "${tmp}"
  pushd "${tmp}" >/dev/null
  phpize
  ./configure
  make -j"$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 4)"
  make install
  popd >/dev/null

  if [[ -n "${PHP_INI_DIR}" ]]; then
    local ini="${PHP_INI_DIR}/spx.ini"
    if [[ ! -f "${ini}" ]]; then
      cat > "${ini}" <<'EOF'
extension=spx.so
spx.http_enabled=1
spx.http_key="phpolygon-dev"
spx.http_ip_whitelist="127.0.0.1"
EOF
      echo "    Wrote ${ini}"
    fi
  else
    cat <<'EOF'
    Add the following to your php.ini manually:
      extension=spx.so
      spx.http_enabled=1
      spx.http_key="phpolygon-dev"
      spx.http_ip_whitelist="127.0.0.1"
EOF
  fi
}

install_excimer() {
  echo "==> Installing Excimer"
  if php -m | grep -qi '^excimer$'; then
    echo "    Excimer is already loaded - skipping build."
    return
  fi

  if command -v pecl >/dev/null 2>&1; then
    pecl install excimer
  else
    echo "    pecl not found, falling back to source build"
    local tmp
    tmp="$(mktemp -d)"
    trap "rm -rf '${tmp}'" RETURN
    git clone --depth 1 https://gerrit.wikimedia.org/r/mediawiki/libs/Excimer "${tmp}"
    pushd "${tmp}" >/dev/null
    phpize
    ./configure
    make -j"$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 4)"
    make install
    popd >/dev/null
  fi

  if [[ -n "${PHP_INI_DIR}" ]]; then
    local ini="${PHP_INI_DIR}/excimer.ini"
    if [[ ! -f "${ini}" ]]; then
      echo "extension=excimer.so" > "${ini}"
      echo "    Wrote ${ini}"
    fi
  else
    echo "    Add 'extension=excimer.so' to your php.ini manually."
  fi
}

case "${target}" in
  spx)     install_spx ;;
  excimer) install_excimer ;;
  both|"") install_spx; install_excimer ;;
  *)
    echo "Unknown target: ${target}" >&2
    echo "Usage: $0 [spx|excimer|both]" >&2
    exit 1
    ;;
esac

echo
echo "Done. Verify with:"
echo "  php -m | grep -E 'spx|excimer'"
echo
echo "See docs/profiling.md for activation and usage."
