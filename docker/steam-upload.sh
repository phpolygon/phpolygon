#!/usr/bin/env bash
#
# Steam upload helper for the PHPolygon Docker build harness.
#
# Sourced by build-all.sh; relies on these already being set there:
#   APP_NAME  VARIANT  VERSION  INTERACTIVE  and the C_* colour vars.
#
# Config lives in the game dir as steam-build.json:
#   {
#     "steamUser": "<login>",
#     "uploads": {
#       "<target>": {
#         "appId": "...",
#         "buildType": "full",          # which build/<plat>-<suffix> dirs to ship
#         "setLive": "",                # branch to set live, or "" to just upload
#         "depots": { "windows-x86_64": "...", "linux-x86_64": "...", "macos-universal": "..." }
#       }
#     }
#   }
# If a requested upload target is missing, it is prompted for and saved here.
#
# macOS ships as a single "universal" bundle: one .app whose launcher picks the
# arm64 or x86_64 binary at runtime (both are already ad-hoc signed). No lipo.

STEAM_CONFIG="/app/steam-build.json"

# build/<plat>-<suffix> suffix for a build type (full has no type suffix).
steam_suffix_for() {
    local type="$1"
    if [ "$type" = "full" ]; then echo "$VARIANT"; else echo "${VARIANT}-${type}"; fi
}

steam_cfg_get() { jq -r "$1 // empty" "$STEAM_CONFIG" 2>/dev/null; }
steam_has_target() { [ -f "$STEAM_CONFIG" ] && [ -n "$(steam_cfg_get ".uploads[\"$1\"].appId")" ]; }

# Interactively collect + persist config for one upload target.
steam_prompt_and_save() {
    local target="$1"
    if [ "${INTERACTIVE:-0}" != "1" ]; then
        echo "  ${C_R}Steam config for '$target' is missing${C_0} and no TTY to prompt." >&2
        echo "  Create $STEAM_CONFIG or run with -it. See docker/README.md." >&2
        return 1
    fi
    echo ""
    echo "${C_C}Steam upload '$target' is not configured yet - let's set it up.${C_0}"

    local user; user="$(steam_cfg_get '.steamUser')"
    read -rp "Steam login user${user:+ [$user]}: " _u; user="${_u:-$user}"
    [ -z "$user" ] && { echo "  ${C_R}a login user is required${C_0}"; return 1; }

    local appid btype setlive dwin dlin dmac
    read -rp "Steam App ID for '$target': " appid
    [ -z "$appid" ] && { echo "  ${C_R}an App ID is required${C_0}"; return 1; }
    read -rp "Build type to ship (full/demo) [full]: " btype; btype="${btype:-full}"
    read -rp "Windows depot ID: " dwin
    read -rp "Linux depot ID: " dlin
    read -rp "macOS (universal) depot ID: " dmac
    read -rp "Set live on branch (blank = upload only): " setlive

    [ -f "$STEAM_CONFIG" ] || echo '{}' > "$STEAM_CONFIG"
    local tmp; tmp="$(mktemp)"
    jq \
        --arg user "$user" --arg t "$target" --arg app "$appid" --arg bt "$btype" \
        --arg sl "$setlive" --arg dw "$dwin" --arg dl "$dlin" --arg dm "$dmac" \
        '.steamUser = $user
         | .uploads[$t] = {appId:$app, buildType:$bt, setLive:$sl,
             depots:{"windows-x86_64":$dw, "linux-x86_64":$dl, "macos-universal":$dm}}' \
        "$STEAM_CONFIG" > "$tmp" && mv "$tmp" "$STEAM_CONFIG"
    echo "  ${C_G}saved${C_0} -> $STEAM_CONFIG"
}

# Upload content is staged on the container's own filesystem: on a Windows or
# macOS host /app is a bind mount (9p/virtiofs) where chmod may not stick, and
# the Steam depot takes the executable bits from what it reads here.
STEAM_STAGE="${STEAM_STAGE:-/tmp/phpolygon-steam-content}"
# The universal bundle's launcher: the game's own (build.json
# platforms.macos.launcher) or the harness default.
PHPOLYGON_MAC_LAUNCHER="${PHPOLYGON_MAC_LAUNCHER:-/usr/local/share/phpolygon/macos-launcher.sh}"

# Copy the Windows + Linux packages into $STEAM_STAGE and build the universal
# macOS bundle there; set and verify the executable bits.
steam_stage_content() {
    local suffix="$1" d
    rm -rf "$STEAM_STAGE"; mkdir -p "$STEAM_STAGE"
    for d in "windows-x86_64-${suffix}" "linux-x86_64-${suffix}"; do
        cp -R "build/${d}" "${STEAM_STAGE}/${d}" || return 1
    done
    steam_make_universal "$suffix" || return 1

    local lin="${STEAM_STAGE}/linux-x86_64-${suffix}/${APP_NAME}/${APP_NAME}"
    local mac="${STEAM_STAGE}/macos-universal-${suffix}/${APP_NAME}.app/Contents/MacOS"
    chmod 0755 "$lin" "$mac"/* || return 1
    local f bad=0
    for f in "$lin" "$mac"/*; do
        [ -x "$f" ] || { echo "  ${C_R}not executable after chmod: $f${C_0}"; bad=1; }
    done
    [ "$bad" = "0" ] || return 1
    echo "  staged upload content: $STEAM_STAGE (executable bits verified)"
}

# Build $STEAM_STAGE/macos-universal-<suffix>/<App>.app from the arm64 + x86_64 bundles.
steam_make_universal() {
    local suffix="$1"
    local arm="build/macos-arm64-${suffix}/${APP_NAME}.app"
    local x86="build/macos-x86_64-${suffix}/${APP_NAME}.app"
    local uni="${STEAM_STAGE}/macos-universal-${suffix}/${APP_NAME}.app"
    [ -d "$arm" ] || { echo "  ${C_R}missing $arm${C_0}"; return 1; }
    [ -d "$x86" ] || { echo "  ${C_R}missing $x86${C_0}"; return 1; }

    rm -rf "${STEAM_STAGE}/macos-universal-${suffix}"
    mkdir -p "${STEAM_STAGE}/macos-universal-${suffix}"
    # The arm64 bundle brings everything shared: Contents/Resources with the one
    # PHAR (the binaries only carry a launcher), its pre-extracted assets and the
    # external resources. Only the x86_64 binary is taken from the other bundle.
    cp -R "$arm" "$uni" || return 1
    local mac="${uni}/Contents/MacOS"
    mv "${mac}/${APP_NAME}" "${mac}/${APP_NAME}-arm64"
    cp "${x86}/Contents/MacOS/${APP_NAME}" "${mac}/${APP_NAME}-x86_64"

    local launcher; launcher="$(jq -r '.platforms.macos.launcher // empty' build.json 2>/dev/null)"
    if [ -n "$launcher" ]; then
        [ -f "$launcher" ] || { echo "  ${C_R}build.json platforms.macos.launcher not found: $launcher${C_0}"; return 1; }
    else
        launcher="$PHPOLYGON_MAC_LAUNCHER"
        # An image older than the game's engine: take the template from vendor/.
        [ -f "$launcher" ] || launcher="vendor/phpolygon/phpolygon/docker/macos-launcher.sh"
        [ -f "$launcher" ] || { echo "  ${C_R}launcher template missing (rebuild the image)${C_0}"; return 1; }
    fi
    cp "$launcher" "${mac}/${APP_NAME}"
    sed -i 's/\r$//' "${mac}/${APP_NAME}"   # a script checked out on Windows
    bash -n "${mac}/${APP_NAME}" || { echo "  ${C_R}launcher has a syntax error: $launcher${C_0}"; return 1; }
    echo "  launcher: $launcher"

    chmod 0755 "${mac}/${APP_NAME}" "${mac}/${APP_NAME}-arm64" "${mac}/${APP_NAME}-x86_64"
    macho_require_arch "${mac}/${APP_NAME}-arm64" arm64 || return 1
    macho_require_arch "${mac}/${APP_NAME}-x86_64" x86_64 || return 1
    local lib
    while IFS= read -r -d '' lib; do
        macho_require_arch "$lib" arm64 || return 1
        macho_require_arch "$lib" x86_64 || return 1
    done < <(find "$uni" -name '*.dylib' -print0)
    echo "  universal bundle: ${uni}"
}
# CPU architectures of a Mach-O file (thin or fat), space-separated.
_macho_cpu_name() { case "$1" in 0100000c) echo arm64 ;; 01000007) echo x86_64 ;; *) echo "cpu-$1" ;; esac; }
macho_archs() {
    local f="$1" magic n i cpu
    magic="$(od -An -tx1 -N4 "$f" 2>/dev/null | tr -d ' \n')"
    case "$magic" in
        cafebabe)   # fat header, big-endian: nfat_arch, then 20-byte entries starting with cputype
            n=$((16#$(od -An -tx1 -j4 -N4 "$f" | tr -d ' \n')))
            for ((i = 0; i < n; i++)); do
                _macho_cpu_name "$(od -An -tx1 -j$((8 + 20 * i)) -N4 "$f" | tr -d ' \n')"
            done | tr '\n' ' ' ;;
        cffaedfe)   # thin 64-bit, little-endian cputype
            cpu="$(od -An -tx1 -j4 -N4 "$f" | tr -d ' \n')"
            _macho_cpu_name "${cpu:6:2}${cpu:4:2}${cpu:2:2}${cpu:0:2}" ;;
        *) echo "not-mach-o" ;;
    esac
}

macho_require_arch() {
    local f="$1" want="$2" have
    have="$(macho_archs "$f")"
    case " $have " in
        *" $want "*) return 0 ;;
    esac
    echo "  ${C_R}${f}: no ${want} slice (has: ${have})${C_0}"
    return 1
}

# Pre-deploy guard: verify every runtime file a Steam build needs is actually
# present, per platform. A missing Steam API lib ships a build that crashes at
# startup with no window and no log (e.g. Windows can't resolve steam_api64.dll),
# so we ABORT the deploy instead of uploading a broken build.
steam_verify_files() {
    local suffix="$1" miss=0
    local win="${STEAM_STAGE}/windows-x86_64-${suffix}/${APP_NAME}"
    local lin="${STEAM_STAGE}/linux-x86_64-${suffix}/${APP_NAME}"
    local mac="${STEAM_STAGE}/macos-universal-${suffix}/${APP_NAME}.app"

    _req()  { [ -e "$1" ] || { echo "  ${C_R}MISSING required file: $1${C_0}"; miss=1; }; }
    _reqf() { [ -n "$(find "$1" -name "$2" -print -quit 2>/dev/null)" ] \
                || { echo "  ${C_R}MISSING required file in $1: $2${C_0}"; miss=1; }; }

    # Windows (D3D11/12 backend hard-imports D3DCOMPILER_47.dll; missing it
    # crashes the exe at load with 0xC0000135 - no window, no log)
    _req  "${win}/${APP_NAME}.exe"
    _req  "${win}/steam_api64.dll"
    _req  "${win}/d3dcompiler_47.dll"
    # Linux
    _req  "${lin}/${APP_NAME}"
    _reqf "${lin}" "libsteam_api.so"
    # macOS (universal .app)
    _req  "${mac}/Contents/MacOS/${APP_NAME}"
    _reqf "${mac}" "libsteam_api.dylib"

    if [ "$miss" = "1" ]; then
        echo "  ${C_R}Steam deploy ABORTED: required runtime files are missing (see above).${C_0}"
        echo "  ${C_R}Shipping this build would crash at startup. Fix the build before deploying.${C_0}"
        return 1
    fi
    echo "  ${C_G}Pre-deploy file check OK${C_0} - all required runtime files present."
}

# Emit the app_build VDF for a target to stdout.
steam_gen_vdf() {
    local target="$1" suffix="$2"
    local appid setlive dwin dlin dmac
    appid="$(steam_cfg_get ".uploads[\"$target\"].appId")"
    setlive="$(steam_cfg_get ".uploads[\"$target\"].setLive")"
    dwin="$(steam_cfg_get ".uploads[\"$target\"].depots[\"windows-x86_64\"]")"
    dlin="$(steam_cfg_get ".uploads[\"$target\"].depots[\"linux-x86_64\"]")"
    dmac="$(steam_cfg_get ".uploads[\"$target\"].depots[\"macos-universal\"]")"
    cat <<VDF
"AppBuild"
{
    "AppID" "${appid}"
    "Desc" "${APP_NAME} v${VERSION} (${target}) - Windows x64, Linux x64, macOS Universal"
    "ContentRoot" "${STEAM_STAGE}"
    "BuildOutput" "/app/steam/output"
    "SetLive" "${setlive}"
    "Depots"
    {
        "${dwin}"
        {
            "FileMapping" { "LocalPath" "windows-x86_64-${suffix}/${APP_NAME}/*" "DepotPath" "." "Recursive" "1" }
        }
        "${dlin}"
        {
            "FileMapping" { "LocalPath" "linux-x86_64-${suffix}/${APP_NAME}/*" "DepotPath" "." "Recursive" "1" }
        }
        "${dmac}"
        {
            "FileMapping" { "LocalPath" "macos-universal-${suffix}/${APP_NAME}.app/*" "DepotPath" "${APP_NAME}.app" "Recursive" "1" }
        }
    }
}
VDF
}

# Log in once (caches the session / Steam Guard sentry in the /root/Steam volume)
# so the later run_app_build needs no 2FA. Called BEFORE the build.
steam_auth() {
    local user="$1"
    if [ "${STEAM_DRY_RUN:-0}" = "1" ]; then
        echo "  ${C_Y}DRY RUN${C_0} - skipping Steam login."
        return 0
    fi
    echo ""
    echo "${C_C}Authenticating with Steam (user: ${user})${C_0}"
    echo "  ${C_DIM}First time needs your password + Steam Guard code; then it's cached.${C_0}"
    if ! steamcmd +login "$user" +quit; then
        echo "  ${C_R}steamcmd login failed${C_0} - cannot upload."
        return 1
    fi
    echo "  ${C_G}Steam session ready.${C_0}"
}

# Verify builds, assemble universal bundle, generate VDF, run_app_build.
steam_upload() {
    local target="$1"
    local btype suffix
    btype="$(steam_cfg_get ".uploads[\"$target\"].buildType")"; btype="${btype:-full}"
    suffix="$(steam_suffix_for "$btype")"

    echo ""
    echo "${C_B}Uploading '${target}' to Steam (app $(steam_cfg_get ".uploads[\"$target\"].appId"), type ${btype})${C_0}"

    local miss=0 d
    for d in "build/windows-x86_64-${suffix}" "build/linux-x86_64-${suffix}" \
             "build/macos-arm64-${suffix}" "build/macos-x86_64-${suffix}"; do
        [ -d "$d" ] || { echo "  ${C_R}missing build: $d${C_0}"; miss=1; }
    done
    [ "$miss" = "1" ] && { echo "  build the '${btype}' type for all desktop targets first."; return 1; }

    steam_stage_content "$suffix" || return 1
    steam_verify_files "$suffix" || return 1

    mkdir -p /app/steam/output
    local vdf="/tmp/app_build_${target}.vdf"
    steam_gen_vdf "$target" "$suffix" > "$vdf"
    echo "  VDF: $vdf  (SetLive: '$(steam_cfg_get ".uploads[\"$target\"].setLive")')"

    if [ "${STEAM_DRY_RUN:-0}" = "1" ]; then
        echo "  ${C_Y}DRY RUN${C_0} - not uploading. Generated VDF:"
        sed 's/^/    /' "$vdf"
        return 0
    fi

    local user; user="$(steam_cfg_get '.steamUser')"
    steamcmd +login "$user" +run_app_build "$vdf" +quit
}

# Entry point: prepare config (prompt if needed), return the resolved user.
# Usage: steam_prepare <target>   (sets STEAM_RESOLVED_USER on success)
steam_prepare() {
    local target="$1"
    if ! steam_has_target "$target"; then
        steam_prompt_and_save "$target" || return 1
    fi
    STEAM_RESOLVED_USER="$(steam_cfg_get '.steamUser')"
    [ -n "$STEAM_RESOLVED_USER" ] || { echo "  ${C_R}no steamUser configured${C_0}"; return 1; }
}
