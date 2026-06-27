#!/usr/bin/env bash
#
# Container entrypoint: cross-platform build for a PHPolygon game.
#
# Builds the four desktop Steam targets from one Linux container - no macOS host -
# and ad-hoc signs the macOS bundles with rcodesign. Game-agnostic: the game is
# bind-mounted at /app and its name/version/build-types come from /app/build.json.
#
# Two modes:
#   * Interactive (a TTY is attached, i.e. `docker run -it`): prompts for the
#     target version, platforms, build type and signing, then confirms.
#   * Non-interactive (no TTY, or NONINTERACTIVE=1): driven entirely by env vars.
#
# Optimisation: the staged PHP code is identical for every desktop target of the
# same variant/build-type, so the PHAR is built ONCE (first target) and reused by
# the rest via `phpolygon build --phar` (only micro.sfx + combine + package run
# per reuse target - seconds instead of ~30-40s each).
#
# Env (all optional):
#   VERSION        target version to stamp into build.json (+ version const file)
#   PLATFORMS      space-separated targets, or "all"   (default: all)
#                  valid: windows-x86_64 linux-x86_64 macos-arm64 macos-x86_64
#   TYPE           a build type from build.json, or "all-types"   (default: full)
#   VARIANT        build variant (e.g. base, steam)    (default: steam)
#   SIGN_MAC       1 | 0  ad-hoc sign macOS bundles     (default: 1)
#   PHP_VERSION    micro.sfx PHP runtime version        (default: 8.5)
#   SPC_REPO       static-php-cli releases repo         (default: hmennen90/static-php-cli)
#   NONINTERACTIVE 1 to force env-driven mode even with a TTY
#   VERSION_CONST_FILE / VERSION_CONST  override the in-game version constant file
#                  (default: auto-detect bootstrap_version.php, const GAME_VERSION)
set -uo pipefail

cd /app

if [ ! -f build.json ]; then
    echo "FATAL: /app/build.json not found. Mount the game project at /app." >&2
    echo "       e.g.  -v \"\$PWD:/app\"" >&2
    exit 1
fi
command -v jq >/dev/null || { echo "jq missing"; exit 1; }

# ── colours (degrade to empty when not a TTY) ────────────────────────────────
if [ -t 1 ]; then
    C_B=$'\033[1m'; C_DIM=$'\033[2m'; C_G=$'\033[32m'; C_Y=$'\033[33m'
    C_R=$'\033[31m'; C_C=$'\033[36m'; C_0=$'\033[0m'
else
    C_B=''; C_DIM=''; C_G=''; C_Y=''; C_R=''; C_C=''; C_0=''
fi

PLATFORMS="${PLATFORMS:-all}"
TYPE="${TYPE:-full}"
VARIANT="${VARIANT:-steam}"
SIGN_MAC="${SIGN_MAC:-1}"
UPLOAD="${UPLOAD:-}"   # space-separated Steam upload target names, or empty
PHP_VERSION="${PHP_VERSION:-8.5}"
# Release is keyed by MAJOR.MINOR (tag runtime-php8.5). Normalize a full patch
# version (the php base image exports PHP_VERSION=8.5.x) down to major.minor.
PHP_VERSION="$(echo "$PHP_VERSION" | grep -oE '^[0-9]+\.[0-9]+')"; [ -z "$PHP_VERSION" ] && PHP_VERSION="8.5"
SPC_REPO="${SPC_REPO:-hmennen90/static-php-cli}"

ALL_TARGETS="windows-x86_64 linux-x86_64 macos-arm64 macos-x86_64"

APP_NAME="$(jq -r '.name // empty' build.json)"
[ -z "$APP_NAME" ] || [ "$APP_NAME" = "null" ] && { echo "FATAL: build.json has no \"name\"."; exit 1; }
CUR_VERSION="$(jq -r '.version // "0.0.0"' build.json)"
VERSION="${VERSION:-}"   # explicit override; empty = ask (interactive) or keep current

# ── interactivity ─────────────────────────────────────────────────────────────
# Interactive when a TTY is attached (docker run -it). NONINTERACTIVE=1 forces
# env-driven mode; INTERACTIVE=1 forces the menu on even without a detected TTY
# (some Windows terminals mis-report a TTY; also lets answers be piped in).
_force_interactive="${INTERACTIVE:-}"
INTERACTIVE=0
if [ "${NONINTERACTIVE:-0}" = "1" ]; then
    INTERACTIVE=0
elif [ "$_force_interactive" = "1" ] || [ -t 0 ]; then
    INTERACTIVE=1
fi

# expand a platform selection token into the canonical target list
expand_platforms() {
    case "$1" in
        all|"") echo "$ALL_TARGETS" ;;
        desktop) echo "$ALL_TARGETS" ;;
        *) echo "$1" ;;
    esac
}

if [ "$INTERACTIVE" = "1" ]; then
    echo ""
    echo "${C_B}${C_C}PHPolygon - interactive build${C_0}  (${APP_NAME})"
    echo "${C_DIM}Press Enter to accept the [default].${C_0}"
    echo ""

    # version
    read -rp "Target version [${CUR_VERSION}]: " _v
    VERSION="${_v:-$CUR_VERSION}"

    # platforms
    echo ""
    echo "Platforms:  1) windows-x86_64   2) linux-x86_64   3) macos-arm64   4) macos-x86_64"
    read -rp "Pick numbers (e.g. '2 3'), 'all', or Enter for all: " _p
    if [ -z "$_p" ] || [ "$_p" = "all" ]; then
        PLATFORMS="$ALL_TARGETS"
    else
        PLATFORMS=""
        for n in $_p; do
            case "$n" in
                1) PLATFORMS="$PLATFORMS windows-x86_64" ;;
                2) PLATFORMS="$PLATFORMS linux-x86_64" ;;
                3) PLATFORMS="$PLATFORMS macos-arm64" ;;
                4) PLATFORMS="$PLATFORMS macos-x86_64" ;;
                windows-x86_64|linux-x86_64|macos-arm64|macos-x86_64) PLATFORMS="$PLATFORMS $n" ;;
                *) echo "  ${C_Y}ignoring unknown '$n'${C_0}" ;;
            esac
        done
        PLATFORMS="$(echo "$PLATFORMS" | xargs)"
        [ -z "$PLATFORMS" ] && PLATFORMS="$ALL_TARGETS"
    fi

    # build type
    echo ""
    _types="$(jq -r '.buildTypes // {} | keys[]' build.json 2>/dev/null | xargs)"
    echo "Build type:  full${_types:+, $_types}, or all-types"
    read -rp "Type [full]: " _t
    TYPE="${_t:-full}"

    # signing (only relevant if a macOS target is selected)
    if [[ "$PLATFORMS" == *macos-* ]]; then
        read -rp "Ad-hoc sign macOS bundles? [Y/n]: " _s
        case "$_s" in n|N|no|No) SIGN_MAC=0 ;; *) SIGN_MAC=1 ;; esac
    fi

    # optional Steam upload after the build
    echo ""
    _known="$(jq -r '.uploads // {} | keys | join(", ")' steam-build.json 2>/dev/null)"
    echo "Steam upload after build?  ${_known:+configured: $_known;  }blank = no upload"
    read -rp "Upload target(s) (e.g. 'full', 'full demo'), or Enter to skip: " _ul
    UPLOAD="$(echo "${_ul:-}" | xargs)"
else
    PLATFORMS="$(expand_platforms "$PLATFORMS")"
fi
PLATFORMS="$(expand_platforms "$PLATFORMS")"

# A Steam upload ships all four desktop depots (win + linux + universal mac), so
# every desktop target must be built regardless of the platform selection.
if [ -n "$UPLOAD" ]; then PLATFORMS="$ALL_TARGETS"; fi

# ── version injection (build.json is canonical; const file is the in-game mirror) ─
inject_version() {
    local v="$1"
    [ -z "$v" ] && return 0
    [ "$v" = "$CUR_VERSION" ] && { echo "  version: unchanged ($v)"; return 0; }

    local tmp; tmp="$(mktemp)"
    jq --arg v "$v" '.version = $v' build.json > "$tmp" && mv "$tmp" build.json

    # locate the in-game version constant file
    local f="${VERSION_CONST_FILE:-}"
    if [ -z "$f" ]; then
        for c in bootstrap_version.php bootstrap_constants.php bootstrap_const.php; do
            [ -f "$c" ] && { f="$c"; break; }
        done
    fi
    local const="${VERSION_CONST:-GAME_VERSION}"
    if [ -n "$f" ] && [ -f "$f" ] && grep -q "$const" "$f"; then
        sed -i -E "s/(define\((['\"])${const}\2[[:space:]]*,[[:space:]]*(['\"]))[^'\"]*(['\"])/\1${v}\4/" "$f"
        echo "  version: ${C_G}${v}${C_0}  ->  build.json + ${f} (${const})"
    else
        echo "  version: ${C_G}${v}${C_0}  ->  build.json  ${C_DIM}(no version const file found)${C_0}"
    fi
    CUR_VERSION="$v"
}

# ── confirm (interactive) ─────────────────────────────────────────────────────
if [ "$INTERACTIVE" = "1" ]; then
    echo ""
    echo "${C_B}About to build:${C_0}"
    echo "  app        $APP_NAME"
    echo "  version    $VERSION ${C_DIM}(was $CUR_VERSION)${C_0}"
    echo "  platforms  $PLATFORMS"
    echo "  type       $TYPE"
    echo "  variant    $VARIANT"
    echo "  sign mac   $SIGN_MAC"
    [ -n "$UPLOAD" ] && echo "  steam      ${C_Y}upload after build: $UPLOAD${C_0}"
    echo ""
    read -rp "Start build? [Y/n]: " _go
    case "$_go" in n|N|no|No) echo "Aborted."; exit 0 ;; esac
fi

[ -n "$VERSION" ] && inject_version "$VERSION"
VERSION="$CUR_VERSION"

# ── composer auth (phpolygon/phpolygon resolve over authenticated HTTPS) ──────
TOKEN="${GITHUB_TOKEN:-${GH_TOKEN:-}}"
if [ -n "$TOKEN" ]; then
    composer config --global github-oauth.github.com "$TOKEN" >/dev/null 2>&1 || true
    echo "  GitHub token: configured for composer"
else
    echo "  ${C_Y}GitHub token: NONE${C_0} - composer may fail to resolve phpolygon/phpolygon."
    echo "                Pass one with:  -e GITHUB_TOKEN=\$(gh auth token)"
fi
git config --global url."https://github.com/".insteadOf "git@github.com:" || true

# ── micro.sfx prefetch (cache key + osName MUST match StaticPhpResolver) ──────
RELEASE_TAG="runtime-php${PHP_VERSION}"
CACHE_ROOT="${PHPOLYGON_HOME:-/root/.phpolygon}/build-cache"
RELEASE_JSON="/tmp/spc-release.json"
RELEASE_FETCHED=0

os_name_for() {
    case "$1" in
        macos-arm64)    echo "macos-aarch64" ;;
        macos-x86_64)   echo "macos-x86_64"  ;;
        linux-x86_64)   echo "linux-x86_64"  ;;
        linux-arm64)    echo "linux-aarch64" ;;
        windows-x86_64) echo "windows-x86_64";;
        *) echo "" ;;
    esac
}
cache_key_for() {
    if [ "$VARIANT" != "base" ]; then echo "$1-${VARIANT}-php${PHP_VERSION}"; else echo "$1-php${PHP_VERSION}"; fi
}
curl_gh() {
    if [ -n "$TOKEN" ]; then
        curl -fsSL --retry 4 --retry-delay 2 --retry-connrefused -H "Authorization: Bearer $TOKEN" "$@"
    else
        curl -fsSL --retry 4 --retry-delay 2 --retry-connrefused "$@"
    fi
}
ensure_release_json() {
    [ "$RELEASE_FETCHED" = "1" ] && return 0
    echo "  fetching release index ($RELEASE_TAG from $SPC_REPO)..."
    curl_gh -H "Accept: application/vnd.github+json" \
        "https://api.github.com/repos/${SPC_REPO}/releases/tags/${RELEASE_TAG}" \
        -o "$RELEASE_JSON" || { echo "  ! could not fetch release index"; return 1; }
    RELEASE_FETCHED=1
}
asset_url() { jq -r --arg n "$1" '.assets[]? | select(.name==$n) | .browser_download_url' "$RELEASE_JSON" 2>/dev/null | head -1; }
fetch_zip_member() {
    local asset="$1" dest="$2"; shift 2
    ensure_release_json || return 1
    local url; url="$(asset_url "$asset")"
    [ -z "$url" ] || [ "$url" = "null" ] && { echo "  ! asset not found in release: $asset"; return 1; }
    echo "  prefetch $asset"
    local zip="/tmp/dl-$$.zip" ex="/tmp/dlx-$$"
    curl_gh -L "$url" -o "$zip" || { echo "  ! download failed: $asset"; return 1; }
    rm -rf "$ex"; mkdir -p "$ex"
    unzip -o -q "$zip" -d "$ex" || { echo "  ! unzip failed: $asset"; rm -rf "$zip" "$ex"; return 1; }
    local found="" n
    for n in "$@"; do [ -f "$ex/$n" ] && { found="$ex/$n"; break; }; done
    [ -z "$found" ] && { echo "  ! none of [$*] in $asset"; rm -rf "$zip" "$ex"; return 1; }
    mkdir -p "$(dirname "$dest")"; cp "$found" "$dest"; rm -rf "$zip" "$ex"
}
prefetch_runtime() {
    local target="$1" os key dir sfx
    os="$(os_name_for "$target")"; key="$(cache_key_for "$target")"
    dir="${CACHE_ROOT}/${key}"; sfx="${dir}/micro.sfx"
    [ -z "$os" ] && { echo "  ! unknown target: $target"; return 1; }
    if [ ! -f "$sfx" ]; then
        fetch_zip_member "micro-sfx-${VARIANT}-${PHP_VERSION}-${os}.zip" "$sfx" \
            micro.sfx micro.sfx.exe buildroot/bin/micro.sfx buildroot/bin/micro.sfx.exe || return 1
        chmod +x "$sfx" || true
    else
        echo "  cached: $key/micro.sfx"
    fi
    # Sign the bare runtime BEFORE the PHAR is appended (see header / signing note).
    if [[ "$target" == macos-* ]] && [ "$SIGN_MAC" = "1" ]; then
        echo "  ad-hoc signing runtime $key/micro.sfx"
        rcodesign sign "$sfx" >/dev/null 2>&1 || echo "  ! rcodesign failed on $sfx"
    fi
    if [ "$target" = "windows-x86_64" ] && [ ! -f "${dir}/vulkan-1.dll" ]; then
        fetch_zip_member "vulkan-1-dll-${VARIANT}-${PHP_VERSION}-${os}.zip" "${dir}/vulkan-1.dll" \
            vulkan-1.dll buildroot/bin/vulkan-1.dll || echo "  (vulkan-1.dll optional - skipped)"
    fi
}

echo ""
echo "Prefetching micro.sfx runtimes..."
for target in $PLATFORMS; do
    prefetch_runtime "$target" || { echo "FATAL: could not prepare runtime for $target"; exit 1; }
done

echo ""
echo "${C_B}===============================================${C_0}"
echo "${C_B}  PHPolygon - Cross-platform Docker build${C_0}"
echo "==============================================="
printf "  %-10s %s\n" "App:" "$APP_NAME"
printf "  %-10s %s\n" "Version:" "$VERSION"
printf "  %-10s %s\n" "Platforms:" "$PLATFORMS"
printf "  %-10s %s\n" "Type:" "$TYPE"
printf "  %-10s %s\n" "Variant:" "$VARIANT"
printf "  %-10s %s\n" "Sign mac:" "$SIGN_MAC"
printf "  %-10s %s\n" "Container:" "$(uname -m) / $(nproc) cpus"
echo "==============================================="

# ── signature status of a finished macOS bundle ──────────────────────────────
mac_sig_status() {
    local app="$1" bin="$1/Contents/MacOS/${APP_NAME}"
    [ -f "$bin" ] || { echo "no-binary"; return; }
    local info; info="$(rcodesign print-signature-info "$bin" 2>/dev/null)"
    if echo "$info" | grep -q 'CodeDirectory'; then
        local se me
        se="$(echo "$info" | grep -m1 macho_signature_end_offset | grep -oE '[0-9]+' | head -1)"
        me="$(echo "$info" | grep -m1 macho_end_offset | grep -oE '[0-9]+' | head -1)"
        echo "signed(+$(( (${me:-0}-${se:-0})/1024/1024 ))MB phar)"
    else echo "unsigned"; fi
}
human_size() { local b; b="$(du -sb "$1" 2>/dev/null | cut -f1)"; awk -v b="${b:-0}" 'BEGIN{printf "%.0fMB", b/1048576}'; }

# Build one target. Writes a TSV result line to $1 (result file). $5=stream|quiet.
build_target() {
    local rfile="$1" target="$2" type="$3" phar="$4" mode="$5"
    local out="build/${target}-${VARIANT}"; [ "$type" != "full" ] && out="${out}-${type}"
    local t0=$SECONDS rc=0 log="/tmp/buildlog-${target}-${type}.txt"
    if [ "$mode" = "stream" ]; then
        php -d phar.readonly=0 vendor/bin/phpolygon build "$target" \
            --variant "$VARIANT" --type "$type" --php-version "$PHP_VERSION" --phar "$phar" \
            2>&1 | sed "s/^/    ${C_DIM}|${C_0} /"
        rc=${PIPESTATUS[0]}
    else
        php -d phar.readonly=0 vendor/bin/phpolygon build "$target" \
            --variant "$VARIANT" --type "$type" --php-version "$PHP_VERSION" --phar "$phar" >"$log" 2>&1
        rc=$?
    fi
    local dt=$(( SECONDS - t0 )) status="ok" size="-" sig="-"
    if [ "$rc" -ne 0 ]; then
        status="FAILED"
    else
        size="$(human_size "$out")"
        [[ "$target" == macos-* ]] && [ "$SIGN_MAC" = "1" ] && sig="$(mac_sig_status "${out}/${APP_NAME}.app")"
    fi
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$target" "$type" "$dt" "$status" "$size" "$sig" "$out" > "$rfile"
    if [ "$mode" != "stream" ]; then
        if [ "$rc" -eq 0 ]; then
            echo "  ${C_G}✓${C_0} ${target}/${type}  ${dt}s  ${size}  ${sig}"
        else
            echo "  ${C_R}✗${C_0} ${target}/${type}  failed (${dt}s)"; tail -4 "$log" | sed 's/^/      /'
        fi
    fi
}

# Resolve build types.
TYPES=()
if [ "$TYPE" = "all-types" ]; then
    TYPES=(full)
    while IFS= read -r t; do [ -n "$t" ] && [ "$t" != "full" ] && TYPES+=("$t"); done \
        < <(jq -r '.buildTypes // {} | keys[]' build.json 2>/dev/null)
else
    TYPES=("$TYPE")
fi

# ── Steam: prepare config + authenticate BEFORE building ─────────────────────
# (so Steam Guard is entered up front, not after a long build). Also ensures
# each upload target's build type is in the build set.
if [ -n "$UPLOAD" ]; then
    # shellcheck source=/dev/null
    source /usr/local/bin/steam-upload
    for t in $UPLOAD; do
        steam_prepare "$t" || { echo "FATAL: cannot prepare Steam target '$t'"; exit 1; }
        bt="$(steam_cfg_get ".uploads[\"$t\"].buildType")"; bt="${bt:-full}"
        case " ${TYPES[*]} " in *" $bt "*) ;; *) TYPES+=("$bt") ;; esac
    done
    steam_auth "$STEAM_RESOLVED_USER" || { echo "FATAL: Steam auth failed"; exit 1; }
fi

ETA_FILE="${CACHE_ROOT}/.phar-build-secs"
declare -a RESULTS=()
TOTAL_START=$SECONDS

for type in "${TYPES[@]}"; do
    PHAR="/tmp/phpolygon-phar-${VARIANT}-${type}.phar"
    rm -f "$PHAR"

    # split: first target builds the shared PHAR (streamed), rest reuse in parallel
    first=""; rest=()
    for t in $PLATFORMS; do
        if [ -z "$first" ]; then first="$t"; else rest+=("$t"); fi
    done

    eta=""; [ -f "$ETA_FILE" ] && eta=" ${C_DIM}(~$(cat "$ETA_FILE")s)${C_0}"
    echo ""
    echo "${C_C}▶ [${type}] ${first}${C_0} - building shared PHAR + bundle${eta}"
    rf="/tmp/res-${first}-${type}"
    build_target "$rf" "$first" "$type" "$PHAR" "stream"
    RESULTS+=("$rf")
    # persist phar-build duration for next run's ETA
    awk -F'\t' '{print $3}' "$rf" > "$ETA_FILE" 2>/dev/null || true

    if [ "${#rest[@]}" -gt 0 ]; then
        echo ""
        echo "${C_C}▶ [${type}] reusing PHAR (parallel):${C_0} ${rest[*]}"
        pids=()
        for t in "${rest[@]}"; do
            rf="/tmp/res-${t}-${type}"; RESULTS+=("$rf")
            build_target "$rf" "$t" "$type" "$PHAR" "quiet" &
            pids+=("$!")
        done
        wait "${pids[@]}" 2>/dev/null || true
    fi
done

TOTAL=$(( SECONDS - TOTAL_START ))

# ── summary ───────────────────────────────────────────────────────────────────
echo ""
echo "${C_B}===============================================${C_0}"
echo "${C_B}  Build complete${C_0}  -  ${TOTAL}s  ($(uname -m), $(nproc) cpus)"
echo "==============================================="
printf "  ${C_B}%-15s %-6s %5s %7s %-22s %s${C_0}\n" "TARGET" "TYPE" "TIME" "SIZE" "SIGNATURE" "OUTPUT"
fails=0
for rf in "${RESULTS[@]}"; do
    [ -f "$rf" ] || continue
    IFS=$'\t' read -r tgt typ dt st sz sg out < "$rf"
    mark="${C_G}✓${C_0}"; [ "$st" = "FAILED" ] && { mark="${C_R}✗${C_0}"; fails=$((fails+1)); sz="-"; }
    printf "  %b %-13s %-6s %4ss %7s %-22s %s\n" "$mark" "$tgt" "$typ" "$dt" "$sz" "$sg" "$out"
done
echo "  -----------------------------------------------"
printf "  %-15s %-6s %4ss\n" "TOTAL" "" "$TOTAL"
echo "==============================================="

echo ""
if [ "$fails" -gt 0 ]; then
    echo "  ${C_R}${fails} target(s) failed${C_0} - see logs above."
else
    echo "  ${C_G}All targets built.${C_0}  Artifacts in ./build/"
    echo "  ${C_DIM}macOS bundles are ad-hoc signed (Steam 'not notarized'); verify launch on Apple Silicon.${C_0}"
fi
echo ""

# ── Steam upload (only when every build succeeded) ───────────────────────────
if [ -n "$UPLOAD" ] && [ "$fails" -eq 0 ]; then
    echo "${C_B}=== Steam upload ===${C_0}"
    for t in $UPLOAD; do
        steam_upload "$t" || { echo "  ${C_R}upload '$t' failed${C_0}"; fails=$((fails+1)); }
    done
    echo ""
    [ "$fails" -eq 0 ] && echo "  ${C_G}Steam upload(s) complete.${C_0}  Build logs in steam/output/."
elif [ -n "$UPLOAD" ]; then
    echo "  ${C_Y}Skipping Steam upload${C_0} - not all targets built."
fi

# clean up scratch result files (not the cache volume)
rm -f /tmp/res-*-* /tmp/buildlog-*-* 2>/dev/null || true
exit "$(( fails > 0 ? 1 : 0 ))"
