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
#         "label": "",                  # optional name in the upload status (default: <target>)
#         "depots": { "windows-x86_64": "...", "linux-x86_64": "...", "macos-universal": "..." }
#       }
#     }
#   }
# If a requested upload target is missing, it is prompted for and saved here.
#
# macOS ships as a single "universal" bundle: one .app whose launcher picks the
# arm64 or x86_64 binary at runtime (both are already ad-hoc signed). No lipo.

STEAM_CONFIG="/app/steam-build.json"
# steamcmd binary (a test points this at a fake), the VDF BuildOutput folder
# (steamcmd writes depot_build_<depot>.log / app_build_<app>.log there) and where
# the generated app_build VDFs go.
STEAMCMD="${STEAMCMD:-steamcmd}"
STEAM_BUILD_OUTPUT="${STEAM_BUILD_OUTPUT:-/app/steam/output}"
STEAM_VDF_DIR="${STEAM_VDF_DIR:-/tmp}"

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
# macOS bundle there; set and verify the executable bits. Replaces only this
# suffix's folders, so several build types can be staged side by side for one
# steamcmd session.
steam_stage_content() {
    local suffix="$1" d
    mkdir -p "$STEAM_STAGE"
    for d in "windows-x86_64-${suffix}" "linux-x86_64-${suffix}"; do
        rm -rf "${STEAM_STAGE:?}/${d}"
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
    steam_check_case_collisions "$suffix" || return 1
    echo "  staged upload content: $STEAM_STAGE (executable bits verified)"
}

# Steam depots map paths case-insensitively: two files that differ only in case
# (terminfo's E/Eterm and e/eterm) fail the depot build in steamcmd - after the
# upload of the other depots. Find them here, before anything goes to Steam.
steam_check_case_collisions() {
    local suffix="$1" depot dupes bad=0
    for depot in "windows-x86_64-${suffix}" "linux-x86_64-${suffix}" "macos-universal-${suffix}"; do
        [ -d "${STEAM_STAGE}/${depot}" ] || continue
        dupes="$(cd "${STEAM_STAGE}/${depot}" && find . -mindepth 1 | awk '{ print tolower($0) "\t" $0 }' \
            | sort | awk -F'\t' '$1 == prev { print last; print $2 } { prev = $1; last = $2 }' | sort -u)"
        if [ -n "$dupes" ]; then
            echo "  ${C_R}${depot}: paths that differ only in case (a Steam depot refuses them):${C_0}"
            echo "$dupes" | head -20 | sed 's/^/    /'
            bad=1
        fi
    done
    return "$bad"
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
    # A library both binaries may load needs both slices. One inside a folder
    # named for an architecture (runtimes/macos-x86_64/..., lib-arm64/...) is
    # only ever loaded by that architecture and is thin on purpose.
    local lib want
    while IFS= read -r -d '' lib; do
        want="$(macho_dir_arch "${lib#"$uni"/}")"
        if [ -n "$want" ]; then
            macho_require_arch "$lib" "$want" || return 1
            continue
        fi
        macho_require_arch "$lib" arm64 || return 1
        macho_require_arch "$lib" x86_64 || return 1
    done < <(find "$uni" -name '*.dylib' -print0)
    echo "  universal bundle: ${uni}"
}

# Architecture a bundle-relative path is scoped to by one of its folder names
# (arm64/aarch64 or x86_64/amd64/x64 as a whole word between - _ .), else empty.
macho_dir_arch() {
    local dir seg
    dir="$(dirname "$1")"
    local IFS=/
    for seg in $dir; do
        if [[ "$seg" =~ (^|[-_.])(arm64|aarch64)($|[-_.]) ]]; then echo arm64; return; fi
        if [[ "$seg" =~ (^|[-_.])(x86_64|amd64|x64)($|[-_.]) ]]; then echo x86_64; return; fi
    done
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
    "BuildOutput" "${STEAM_BUILD_OUTPUT}"
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
    if ! "$STEAMCMD" +login "$user" +quit; then
        echo "  ${C_R}steamcmd login failed${C_0} - cannot upload."
        return 1
    fi
    echo "  ${C_G}Steam session ready.${C_0}"
}

# Upload one target. Same path as several (steam_upload_many), one app.
steam_upload() {
    steam_upload_many "$1"
}

# Upload several targets in ONE steamcmd session (one login, one
# +run_app_build per app). Everything is prepared first - builds checked,
# content staged, files verified, VDFs written; if any target fails there,
# nothing is uploaded. steamcmd's own output goes to a log file in
# $STEAM_BUILD_OUTPUT; the terminal gets a compact status block per app, read
# from the depot_build_*/app_build_* logs of this run, and a summary at the end.
#
#   STEAM_STAGE_HOOK      shell function called once after staging, with the
#                         staged suffixes as arguments (game-side checks of
#                         $STEAM_STAGE); non-zero aborts before any upload
#   STEAM_STATUS=0        stream steamcmd's raw output instead (still logged)
#   STEAM_STATUS_INTERVAL seconds between status checks (default 5)
#   STEAM_STATUS_HEARTBEAT re-print an unchanged block after this many s (15)
#   STEAM_NET_DEV         interface counters for the throughput (/proc/net/dev)
#
# Usage: steam_upload_many <target>...   (returns non-zero on any failure)
steam_upload_many() {
    [ "$#" -gt 0 ] || { echo "  ${C_R}steam_upload_many: no target given${C_0}"; return 1; }
    local t btype suffix d miss=0
    local -a targets=("$@") suffixes=() vdfs=()
    local -A staged=()

    for t in "${targets[@]}"; do
        steam_prepare "$t" || return 1
    done

    # 1. builds present for every target
    for t in "${targets[@]}"; do
        btype="$(steam_cfg_get ".uploads[\"$t\"].buildType")"; btype="${btype:-full}"
        suffix="$(steam_suffix_for "$btype")"
        echo ""
        echo "${C_B}Preparing '${t}' (app $(steam_cfg_get ".uploads[\"$t\"].appId"), type ${btype})${C_0}"
        for d in "build/windows-x86_64-${suffix}" "build/linux-x86_64-${suffix}" \
                 "build/macos-arm64-${suffix}" "build/macos-x86_64-${suffix}"; do
            [ -d "$d" ] || { echo "  ${C_R}missing build: $d${C_0}"; miss=1; }
        done
        suffixes+=("$suffix")
    done
    if [ "$miss" = "1" ]; then
        echo "  ${C_R}build every listed type for all desktop targets first - nothing uploaded.${C_0}"
        return 1
    fi

    # 2. stage each build type once (two targets may ship the same type)
    rm -rf "$STEAM_STAGE"
    for suffix in "${suffixes[@]}"; do
        [ -n "${staged[$suffix]:-}" ] && continue
        echo "  staging ${suffix}"
        steam_stage_content "$suffix" || { echo "  ${C_R}staging failed - nothing uploaded.${C_0}"; return 1; }
        staged[$suffix]=1
    done
    if [ -n "${STEAM_STAGE_HOOK:-}" ]; then
        "$STEAM_STAGE_HOOK" "${!staged[@]}" \
            || { echo "  ${C_R}stage check (${STEAM_STAGE_HOOK}) failed - nothing uploaded.${C_0}"; return 1; }
    fi
    for suffix in "${!staged[@]}"; do
        steam_verify_files "$suffix" || return 1
    done

    # 3. one VDF per target
    mkdir -p "$STEAM_BUILD_OUTPUT" "$STEAM_VDF_DIR"
    local i vdf
    for i in "${!targets[@]}"; do
        t="${targets[$i]}"
        vdf="${STEAM_VDF_DIR}/app_build_${t}.vdf"
        steam_gen_vdf "$t" "${suffixes[$i]}" > "$vdf" || return 1
        vdfs+=("$vdf")
        echo "  VDF ${t}: $vdf  (SetLive: '$(steam_cfg_get ".uploads[\"$t\"].setLive")')"
    done

    local user="${STEAM_RESOLVED_USER:-$(steam_cfg_get '.steamUser')}"
    local -a cmd=("$STEAMCMD" +login "$user")
    for vdf in "${vdfs[@]}"; do cmd+=(+run_app_build "$vdf"); done
    cmd+=(+quit)

    if [ "${STEAM_DRY_RUN:-0}" = "1" ]; then
        echo ""
        echo "  ${C_Y}DRY RUN${C_0} - not uploading. One steamcmd session:"
        echo "    ${cmd[*]}"
        for i in "${!targets[@]}"; do
            echo "  VDF '${targets[$i]}':"
            sed 's/^/    /' "${vdfs[$i]}"
        done
        return 0
    fi

    _steam_run_session "${targets[*]}" "${cmd[@]}"
}

# ── upload status ─────────────────────────────────────────────────────────────
# Per app i: _SU_LABEL[i], _SU_APP[i], _SU_DEPOTS[i] ("plat:id plat:id ...").
# Per depot id: _SU_PHASE (wait|scan|upload|done|fail|skip), _SU_UPTX (TX bytes when
# its upload began), _SU_TOTMB (added+changed MB from the depot log summary).

# Run the steamcmd session in the background, watch it, print the summary.
# Usage: _steam_run_session "<targets>" <steamcmd argv...>
_steam_run_session() {
    local targets="$1"; shift
    local start log rc t i plat id
    start="$(date +%s)"
    log="${STEAM_BUILD_OUTPUT}/steamcmd-$(date +%Y%m%d-%H%M%S).log"
    _SU_START="$start"; _SU_LOG="$log"
    _SU_LABEL=(); _SU_APP=(); _SU_DEPOTS=(); _SU_PREVTX=""
    declare -gA _SU_PHASE=() _SU_UPTX=() _SU_TOTMB=()

    i=0
    for t in $targets; do
        _SU_LABEL[i]="$(steam_cfg_get ".uploads[\"$t\"].label")"; _SU_LABEL[i]="${_SU_LABEL[i]:-$t}"
        _SU_APP[i]="$(steam_cfg_get ".uploads[\"$t\"].appId")"
        _SU_DEPOTS[i]="$(jq -r --arg t "$t" '.uploads[$t].depots // {} | to_entries[]
            | select(.value != "") | "\(.key | split("-")[0]):\(.value)"' "$STEAM_CONFIG" | tr '\n' ' ')"
        for plat in ${_SU_DEPOTS[i]}; do id="${plat#*:}"; _SU_PHASE[$id]=wait; done
        i=$((i + 1))
    done

    echo ""
    echo "${C_B}Steam upload: ${#_SU_APP[@]} app(s) in one steamcmd session${C_0}"
    echo "  ${C_DIM}steamcmd log: ${log}${C_0}"

    if [ "${STEAM_STATUS:-1}" = "0" ]; then
        "$@" < /dev/null 2>&1 | tee "$log"
        rc="${PIPESTATUS[0]}"
    else
        "$@" < /dev/null > "$log" 2>&1 &
        local pid=$!
        _steam_watch "$pid"
        wait "$pid"; rc=$?
    fi

    _steam_eval_all 1
    _steam_summary "$rc"
}

# Sum of TX bytes over all non-loopback interfaces.
_steam_tx_bytes() {
    local f="${STEAM_NET_DEV:-/proc/net/dev}"
    [ -r "$f" ] || { echo 0; return; }
    awk -F: 'NR > 2 { gsub(/ /, "", $1); if ($1 != "lo") { split($2, f, " "); s += f[9] } }
        END { printf "%.0f\n", s + 0 }' "$f" 2>/dev/null
}

# A log written by this run (not one left over from an earlier upload).
_steam_fresh() {
    [ -f "$1" ] && [ "$(stat -c %Y "$1" 2>/dev/null || echo 0)" -ge "$_SU_START" ]
}

# Epoch of a "[YYYY-MM-DD HH:MM:SS]" log line (steamcmd writes UTC).
_steam_ts() {
    local ts; ts="$(sed -n 's/^\[\([0-9-]* [0-9:]*\)\].*/\1/p' <<<"$1" | head -1)"
    [ -n "$ts" ] && date -u -d "$ts" +%s 2>/dev/null
}

# Seconds between a depot log's first line and its success line.
_steam_depot_secs() {
    local log="$1" a b
    a="$(_steam_ts "$(head -1 "$log")")"
    b="$(_steam_ts "$(grep -m1 'Success! New manifestID' "$log")")"
    [ -n "$a" ] && [ -n "$b" ] && echo $((b - a))
}

_steam_fmt_secs() {
    local s="${1:-0}"
    if [ "$s" -lt 120 ]; then echo "${s} s"; else printf '%d:%02d min\n' $((s / 60)) $((s % 60)); fi
}

# Lines of a depot log that are not the per-file change listing.
_steam_log_events() { grep -vE ': (Added|Changed|Removed) *:' "$1" 2>/dev/null; }

# Update _SU_PHASE for every depot. With final=1 (steamcmd has exited), a depot
# that never finished is a failure.
_steam_eval_all() {
    local final="${1:-0}" i plat id log ev app_log app_ok
    for i in "${!_SU_APP[@]}"; do
        app_log="${STEAM_BUILD_OUTPUT}/app_build_${_SU_APP[i]}.log"
        app_ok=0
        _steam_fresh "$app_log" && grep -q 'Successfully finished AppID' "$app_log" && app_ok=1
        for plat in ${_SU_DEPOTS[i]}; do
            id="${plat#*:}"; log="${STEAM_BUILD_OUTPUT}/depot_build_${id}.log"
            case "${_SU_PHASE[$id]}" in done|fail) continue ;; esac
            if _steam_fresh "$log"; then
                ev="$(_steam_log_events "$log")"
                if grep -q 'Success! New manifestID' <<<"$ev"; then
                    _SU_PHASE[$id]=done
                elif grep -qE 'ERROR|Failed to' <<<"$ev"; then
                    _SU_PHASE[$id]=fail
                elif grep -qE 'Uploading new manifest|Uploading [0-9]+ out of' <<<"$ev"; then
                    if [ "${_SU_PHASE[$id]}" != upload ]; then
                        # began after the previous check: count from its TX reading
                        _SU_PHASE[$id]=upload; _SU_UPTX[$id]="${_SU_PREVTX:-$(_steam_tx_bytes)}"
                        _SU_TOTMB[$id]="$(sed -n 's/.*Summary: [0-9]* files added (\([0-9.]*\) MB), [0-9]* files changed (\([0-9.]*\) MB).*/\1 \2/p' <<<"$ev" \
                            | awk '{ printf "%.0f", $1 + $2 }')"
                    fi
                else
                    _SU_PHASE[$id]=scan
                fi
            elif { _steam_fresh "$app_log" && grep -qw "Building depot ${id}" "$app_log"; } \
                 || grep -qw "Building depot ${id}" "$_SU_LOG" 2>/dev/null; then
                _SU_PHASE[$id]=scan
            fi
            if [ "$final" = "1" ]; then
                case "${_SU_PHASE[$id]}" in
                    done|fail) ;;
                    wait) if [ "$app_ok" = "1" ]; then _SU_PHASE[$id]=done; else _SU_PHASE[$id]=skip; fi ;;
                    *)    if [ "$app_ok" = "1" ]; then _SU_PHASE[$id]=done; else _SU_PHASE[$id]=fail; fi ;;
                esac
            fi
        done
    done
}

# Build the status block into _SU_BLOCK and its change signature into _SU_SIG.
# Usage: _steam_status_block <tx-rate-bytes-per-s>
_steam_status_block() {
    local rate="${1:-0}" i plat id name log part line w=0 secs sent now
    now="$(date +%s)"
    for i in "${!_SU_LABEL[@]}"; do [ "${#_SU_LABEL[i]}" -gt "$w" ] && w="${#_SU_LABEL[i]}"; done
    secs=$((now - _SU_START))
    _SU_BLOCK="$(printf '%s[%d:%02d] steam upload%s' "${C_DIM}" $((secs / 60)) $((secs % 60)) "${C_0}")"
    _SU_SIG=""
    for i in "${!_SU_APP[@]}"; do
        line="$(printf "  %-${w}s " "${_SU_LABEL[i]}")"
        part=""
        for plat in ${_SU_DEPOTS[i]}; do
            name="${plat%%:*}"; id="${plat#*:}"
            log="${STEAM_BUILD_OUTPUT}/depot_build_${id}.log"
            [ -n "$part" ] && part+=" · "
            _SU_SIG+="${id}=${_SU_PHASE[$id]} "
            case "${_SU_PHASE[$id]}" in
                wait)   part+="${name} ${C_DIM}waiting${C_0}" ;;
                scan)   part+="${name} ${C_C}⠿ scan${C_0}" ;;
                upload)
                    sent=$(( ($(_steam_tx_bytes) - ${_SU_UPTX[$id]:-0}) / 1048576 ))
                    [ "$sent" -lt 0 ] && sent=0
                    part+="${name} ${C_C}⠿ upload ${sent}"
                    [ -n "${_SU_TOTMB[$id]:-}" ] && part+="/~${_SU_TOTMB[$id]}"
                    part+=" MB"
                    [ "$rate" -gt 0 ] && part+=" · $(awk -v r="$rate" 'BEGIN { printf "%.1f", r / 1048576 }') MB/s"
                    part+="${C_0}" ;;
                done)   part+="${name} ${C_G}✓ $(_steam_fmt_secs "$(_steam_depot_secs "$log")")${C_0}" ;;
                fail)   part+="${name} ${C_R}✗ failed${C_0}" ;;
                skip)   part+="${name} ${C_R}✗ not built${C_0}" ;;
            esac
        done
        _SU_BLOCK+=$'\n'"${line}${part}"
    done
}

# Print the status block while steamcmd runs: every STEAM_STATUS_INTERVAL s
# when a depot changed phase, else every STEAM_STATUS_HEARTBEAT s. Plain lines
# (no CR, no cursor control) so it reads the same in a pipe or a log file.
_steam_watch() {
    local pid="$1" interval="${STEAM_STATUS_INTERVAL:-5}" beat="${STEAM_STATUS_HEARTBEAT:-15}"
    local last_sig="" last_print=0 now tx prev_tx prev_t rate s
    prev_tx="$(_steam_tx_bytes)"; prev_t="$(date +%s)"
    while kill -0 "$pid" 2>/dev/null; do
        for ((s = 0; s < interval; s++)); do kill -0 "$pid" 2>/dev/null || break; sleep 1; done
        kill -0 "$pid" 2>/dev/null || break
        now="$(date +%s)"; tx="$(_steam_tx_bytes)"
        rate=0; [ "$now" -gt "$prev_t" ] && rate=$(( (tx - prev_tx) / (now - prev_t) ))
        [ "$rate" -lt 0 ] && rate=0
        _SU_PREVTX="$prev_tx"; prev_tx="$tx"; prev_t="$now"
        _steam_eval_all 0
        _steam_status_block "$rate"
        if [ "$_SU_SIG" != "$last_sig" ] || [ $((now - last_print)) -ge "$beat" ]; then
            echo "$_SU_BLOCK"
            last_sig="$_SU_SIG"; last_print="$now"
        fi
    done
}

# Final status block + per-depot summary; on failure the relevant tail of the
# steamcmd log. Returns 0 only when steamcmd exited 0 and every depot succeeded.
_steam_summary() {
    local rc="$1" i plat name id log ev failed=0 build total
    _steam_status_block 0
    echo "$_SU_BLOCK"
    total=$(( $(date +%s) - _SU_START ))
    echo ""
    echo "${C_B}Steam upload summary${C_0}"
    for i in "${!_SU_APP[@]}"; do
        build=""
        _steam_fresh "${STEAM_BUILD_OUTPUT}/app_build_${_SU_APP[i]}.log" \
            && build="$(sed -n 's/.*Successfully finished AppID [0-9]* build (BuildID \([0-9]*\)).*/\1/p' \
                "${STEAM_BUILD_OUTPUT}/app_build_${_SU_APP[i]}.log" | head -1)"
        echo "  ${C_B}${_SU_LABEL[i]}${C_0} (app ${_SU_APP[i]}${build:+, BuildID $build})"
        for plat in ${_SU_DEPOTS[i]}; do
            name="${plat%%:*}"; id="${plat#*:}"; log="${STEAM_BUILD_OUTPUT}/depot_build_${id}.log"
            if [ "${_SU_PHASE[$id]}" != done ]; then
                failed=1
                if [ "${_SU_PHASE[$id]}" = skip ]; then
                    echo "    $(printf '%-8s' "$name") ${C_R}✗ not built${C_0} (depot ${id}, an earlier depot of this app failed)"
                else
                    echo "    $(printf '%-8s' "$name") ${C_R}✗ failed${C_0} (depot ${id})"
                fi
                continue
            fi
            if ! _steam_fresh "$log"; then
                echo "    $(printf '%-8s' "$name") ${C_G}✓${C_0} depot ${id} (no depot log)"
                continue
            fi
            ev="$(_steam_log_events "$log")"
            local man chunks files
            man="$(sed -n 's/.*New manifestID \([0-9]*\) created.*/\1/p' <<<"$ev" | head -1)"
            chunks="$(sed -n 's/.*created and \([0-9]*\) new chunks uploaded.*/\1/p' <<<"$ev" | head -1)"
            files="$(sed -n 's/.*Summary: \([0-9]*\) files added (\([0-9.]*\) MB), \([0-9]*\) files changed (\([0-9.]*\) MB), \([0-9]*\) files removed (\([0-9.]*\) MB).*/added \1 (\2 MB), changed \3 (\4 MB), removed \5 (\6 MB)/p' <<<"$ev" | head -1)"
            echo "    $(printf '%-8s' "$name") ${C_G}✓ $(_steam_fmt_secs "$(_steam_depot_secs "$log")")${C_0}" \
                "· manifest ${man:-?} · ${chunks:-?} new chunks · ${files:-no file changes}"
        done
    done
    echo "  total: $(_steam_fmt_secs "$total")"

    if [ "$rc" -ne 0 ] || [ "$failed" = 1 ]; then
        echo ""
        echo "  ${C_R}Steam upload FAILED${C_0} (steamcmd exit ${rc}). Last relevant lines:"
        local tail_lines
        tail_lines="$(grep -iE 'error|fail|denied|invalid|timeout|steam guard|password' "$_SU_LOG" 2>/dev/null | tail -12)"
        [ -n "$tail_lines" ] || tail_lines="$(tail -12 "$_SU_LOG" 2>/dev/null)"
        sed 's/^/    /' <<<"$tail_lines"
        echo "  full steamcmd log: ${_SU_LOG}"
        return 1
    fi
    echo "  ${C_G}all depots uploaded.${C_0}  steamcmd log: ${_SU_LOG}"
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
