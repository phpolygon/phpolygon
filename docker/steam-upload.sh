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

# Build build/macos-universal-<suffix>/<App>.app from the arm64 + x86_64 bundles.
steam_make_universal() {
    local suffix="$1"
    local arm="build/macos-arm64-${suffix}/${APP_NAME}.app"
    local x86="build/macos-x86_64-${suffix}/${APP_NAME}.app"
    local uni="build/macos-universal-${suffix}/${APP_NAME}.app"
    [ -d "$arm" ] || { echo "  ${C_R}missing $arm${C_0}"; return 1; }
    [ -d "$x86" ] || { echo "  ${C_R}missing $x86${C_0}"; return 1; }

    rm -rf "build/macos-universal-${suffix}"
    cp -R "build/macos-arm64-${suffix}" "build/macos-universal-${suffix}"
    local mac="${uni}/Contents/MacOS"
    mv "${mac}/${APP_NAME}" "${mac}/${APP_NAME}-arm64"
    cp "${x86}/Contents/MacOS/${APP_NAME}" "${mac}/${APP_NAME}-x86_64"
    cat > "${mac}/${APP_NAME}" <<LAUNCHER
#!/bin/bash
DIR="\$(dirname "\$0")"
if [ "\$(uname -m)" = "arm64" ]; then exec "\$DIR/${APP_NAME}-arm64" "\$@"; else exec "\$DIR/${APP_NAME}-x86_64" "\$@"; fi
LAUNCHER
    chmod +x "${mac}/${APP_NAME}" "${mac}/${APP_NAME}-arm64" "${mac}/${APP_NAME}-x86_64"
    echo "  universal bundle: build/macos-universal-${suffix}/${APP_NAME}.app"
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
    "ContentRoot" "/app/build"
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

    steam_make_universal "$suffix" || return 1

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
