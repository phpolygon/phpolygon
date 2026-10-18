#!/usr/bin/env bash
#
# Fake steamcmd for docker/tests/steam-upload-test.sh: understands
#   +login <user> [+run_app_build <vdf>]... +quit
# and, per VDF, writes app_build_<app>.log and depot_build_<depot>.log into the
# VDF's BuildOutput the way steamcmd does - line by line, spread over time - so
# the status watcher in docker/steam-upload.sh can be tested without Steam.
#
#   FAKE_STEAM_SAMPLES   folder with real depot_build_*.log files: replayed in
#                        name order onto the VDF depots (ids substituted, the
#                        original timestamps and pauses kept); default synthetic
#   FAKE_STEAM_SCALE     wall seconds per log second (default 0.05)
#   FAKE_STEAM_NET_DEV   /proc/net/dev-style file whose TX counter grows while a
#                        depot uploads (FAKE_STEAM_TX_MBPS wall MB/s, default 20)
#   FAKE_STEAM_FAIL_DEPOT  depot id whose upload fails (ERROR!, exit 6)
#   FAKE_STEAM_ARGS_LOG  append the argv here (to assert one session)
set -uo pipefail

SCALE="${FAKE_STEAM_SCALE:-0.05}"
TX_MBPS="${FAKE_STEAM_TX_MBPS:-20}"
NET="${FAKE_STEAM_NET_DEV:-}"
[ -n "${FAKE_STEAM_ARGS_LOG:-}" ] && echo "$*" >> "$FAKE_STEAM_ARGS_LOG"

user="" ; vdfs=()
while [ "$#" -gt 0 ]; do
    case "$1" in
        +login) user="$2"; shift 2 ;;
        +run_app_build) vdfs+=("$2"); shift 2 ;;
        +quit) shift ;;
        *) echo "fake-steamcmd: unknown argument $1" >&2; exit 2 ;;
    esac
done

echo "Steam Console Client (c) Valve Corporation - version fake"
echo "Logging in user '${user}' to Steam Public...OK"
echo "Waiting for client config...OK"

tx_add() { # tx_add <bytes>: grow the TX counter of the fake eth0
    [ -n "$NET" ] || return 0
    local cur; cur="$(awk -F: '/eth0/ { split($2, f, " "); print f[9] }' "$NET" 2>/dev/null)"
    cur=$(( ${cur:-0} + $1 ))
    printf 'Inter-|   Receive                            |  Transmit\n face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed\n    lo: 100 1 0 0 0 0 0 0 100 1 0 0 0 0 0 0\n  eth0: 5000 50 0 0 0 0 0 0 %s 900 0 0 0 0 0 0\n' "$cur" > "$NET.tmp"
    mv "$NET.tmp" "$NET"
}

# Wall-clock pause for <log seconds>; with up=1 the TX counter grows meanwhile.
pause() {
    local secs="$1" up="$2" wall steps i
    wall="$(awk -v s="$secs" -v k="$SCALE" 'BEGIN { printf "%.3f", s * k }')"
    if [ "$up" = 1 ] && [ -n "$NET" ]; then
        steps="$(awk -v w="$wall" 'BEGIN { n = int(w / 0.25); print (n < 1 ? 1 : n) }')"
        for ((i = 0; i < steps; i++)); do
            sleep "$(awk -v w="$wall" -v n="$steps" 'BEGIN { printf "%.3f", w / n }')"
            tx_add "$(awk -v r="$TX_MBPS" -v w="$wall" -v n="$steps" 'BEGIN { printf "%.0f", r * 1048576 * w / n }')"
        done
    else
        sleep "$wall"
    fi
}

ts() { date -u -d "@$1" '+%Y-%m-%d %H:%M:%S'; }

# Synthetic depot log as "<log-epoch>\t<line>" rows, starting at <epoch>.
synthetic() {
    local id="$1" t="$2" man=$((RANDOM * 32768 + RANDOM))
    local rows=(
        "0|Starting build for depot ${id}, content path is \"/tmp/stage/\", build flags 0x0"
        "0|Building file listing for depot ${id} ..."
        "1|Found 1200 files (300 MB) for depot ${id}"
        "2|Building depot ${id}, baseline manifest 111 (400 files)"
        "5|Found 900 new chunks ( 50 used previously ) for depot ${id}, took 0 minutes, 3 seconds"
        "5|Changes to baseline manifest 111 :"
        "5|Added  : \"tests\\\\failed_cases\\\\ERROR.txt\" 1 KB "
        "5|Removed: old\\\\Failed to load.txt (1 KB)"
        "5|Summary: 1000 files added (250.00 MB), 20 files changed (30.50 MB), 5 files removed (1.25 MB)"
        "5|Uploading new manifest to depot-ingest.example (handle 1, 2000 bytes)."
        "8|Uploading 900 out of 900 missing chunks."
        "40|Success! New manifestID ${man} created and 900 new chunks uploaded."
    )
    local r
    for r in "${rows[@]}"; do
        printf '%s\t[%s]: %s\n' "$((t + ${r%%|*}))" "$(ts $((t + ${r%%|*})))" "${r#*|}"
    done
}

# Real sample log, depot id substituted, as "<log-epoch>\t<line>" rows.
sample_rows() {
    local file="$1" id="$2" old
    old="$(basename "$file" .log)"; old="${old#depot_build_}"
    sed "s/${old}/${id}/g" "$file" | awk '
        match($0, /^\[[0-9-]+ [0-9:]+\]/) {
            split(substr($0, 2, 19), a, /[- :]/)
            e = ((a[1] * 400 + a[2] * 31 + a[3]) * 86400) + a[4] * 3600 + a[5] * 60 + a[6]
        }
        { printf "%.0f\t%s\n", e, $0 }'
}

samples=()
[ -n "${FAKE_STEAM_SAMPLES:-}" ] && mapfile -t samples < <(ls "$FAKE_STEAM_SAMPLES"/depot_build_*.log 2>/dev/null | sort)
n_sample=0 rc=0 clock="$(date +%s)"

for vdf in "${vdfs[@]}"; do
    app="$(sed -n 's/^ *"AppID" *"\([0-9]*\)".*/\1/p' "$vdf")"
    out="$(sed -n 's/^ *"BuildOutput" *"\(.*\)".*/\1/p' "$vdf")"
    mapfile -t depots < <(sed -n 's/^ *"\([0-9][0-9]*\)" *$/\1/p' "$vdf")
    mkdir -p "$out"
    applog="$out/app_build_${app}.log"
    echo "Building app ${app}..."
    printf '[%s]: Starting AppID %s build (flags 0x0).\n' "$(ts "$clock")" "$app" > "$applog"
    app_ok=1
    for id in "${depots[@]}"; do
        printf '[%s]: Building depot %s...\n\n' "$(ts "$clock")" "$id" >> "$applog"
        echo "Building depot ${id}..."
        log="$out/depot_build_${id}.log"; : > "$log"
        if [ "${#samples[@]}" -gt 0 ]; then
            rows="$(sample_rows "${samples[$((n_sample % ${#samples[@]}))]}" "$id")"
            n_sample=$((n_sample + 1))
        else
            rows="$(synthetic "$id" "$clock")"
        fi
        prev="" uploading=0 failed=0 buf=""
        while IFS=$'\t' read -r e line; do
            if [ -n "$prev" ] && [ "$e" != "$prev" ]; then
                printf '%s' "$buf" >> "$log"; buf=""
                if [ "$uploading" = 1 ] && [ "$id" = "${FAKE_STEAM_FAIL_DEPOT:-}" ]; then
                    pause 2 1
                    printf '[%s]: ERROR! Failed to upload chunks for depot %s (Timeout)\n' "$(ts "$clock")" "$id" >> "$log"
                    failed=1; break
                fi
                pause $((e - prev)) "$uploading"
                clock=$((clock + e - prev))
            fi
            case "$line" in *"missing chunks."*) uploading=1 ;; *"Success! New manifestID"*) uploading=0 ;; esac
            buf+="${line}"$'\n'; prev="$e"
        done <<<"$rows"
        [ "$failed" = 0 ] && printf '%s' "$buf" >> "$log"
        if [ "$failed" = 1 ]; then
            echo "ERROR! Failed to commit build for AppID ${app} : Timeout"
            printf '[%s]: ERROR! Failed to build depot %s.\n' "$(ts "$clock")" "$id" >> "$applog"
            app_ok=0; rc=6; break
        fi
        clock=$((clock + 1))
    done
    if [ "$app_ok" = 1 ]; then
        printf '[%s]: Successfully finished AppID %s build (BuildID %s).\n' "$(ts "$clock")" "$app" "$((20000000 + RANDOM))" >> "$applog"
        echo "Successfully finished AppID ${app} build (BuildID fake)."
    fi
done
echo "Unloading Steam API...OK"
exit "$rc"
