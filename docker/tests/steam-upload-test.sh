#!/usr/bin/env bash
#
# Tests for docker/steam-upload.sh: steam_upload_many (one steamcmd session for
# several apps), the live status block and the summary - against a fake steamcmd
# (docker/tests/fake-steamcmd.sh), never Steam. Needs bash, jq, GNU date/stat -
# run it in the build image:
#
#   docker run --rm --entrypoint bash -v "$PWD/docker:/harness" phpolygon-build \
#       /harness/tests/steam-upload-test.sh
#
# FAKE_STEAM_SAMPLES=<dir with real depot_build_*.log> replays real steamcmd logs
# instead of the synthetic ones (the content checks below assume synthetic logs).
set -uo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
fails=0
check() { # check NAME CONDITION...
    local name="$1"; shift
    if "$@"; then echo "ok   $name"; else echo "FAIL $name"; fails=$((fails + 1)); fi
}
has() { grep -qF -- "$2" <<<"$1"; }
hasnt() { ! grep -qF -- "$2" <<<"$1"; }
no_cr() { [[ "$1" != *$'\r'* ]]; }

sed 's/\r$//' "$here/fake-steamcmd.sh" > "$tmp/steamcmd"; chmod +x "$tmp/steamcmd"
sed 's/\r$//' "$here/../macos-launcher.sh" > "$tmp/launcher.sh"

# ── a game dir with fake packages for two build types ────────────────────────
game="$tmp/game"; mkdir -p "$game"; cd "$game" || exit 1
APP_NAME=App VARIANT=steam VERSION=1.2.3 INTERACTIVE=0
C_B='' C_DIM='' C_G='' C_Y='' C_R='' C_C='' C_0=''
macho() { # macho <file> arm64|x86_64|fat
    case "$2" in
        arm64)  printf '\xcf\xfa\xed\xfe\x0c\x00\x00\x01' > "$1" ;;
        x86_64) printf '\xcf\xfa\xed\xfe\x07\x00\x00\x01' > "$1" ;;
        fat)    printf '\xca\xfe\xba\xbe\x00\x00\x00\x02\x01\x00\x00\x0c\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01\x00\x00\x07' > "$1" ;;
    esac
}
for s in steam steam-demo; do
    w="build/windows-x86_64-$s/App"; l="build/linux-x86_64-$s/App"
    mkdir -p "$w" "$l"
    touch "$w/App.exe" "$w/steam_api64.dll" "$w/d3dcompiler_47.dll" "$l/App" "$l/libsteam_api.so"
    for a in arm64 x86_64; do
        m="build/macos-$a-$s/App.app/Contents"
        mkdir -p "$m/MacOS" "$m/Frameworks"
        macho "$m/MacOS/App" "$a"
        macho "$m/Frameworks/libsteam_api.dylib" fat
    done
done
cat > steam-build.json <<'JSON'
{
  "steamUser": "tester",
  "uploads": {
    "alpha": { "appId": "1000", "buildType": "full", "setLive": "", "label": "Alpha App",
      "depots": { "windows-x86_64": "1001", "linux-x86_64": "1002", "macos-universal": "1003" } },
    "beta":  { "appId": "2000", "buildType": "demo", "setLive": "beta",
      "depots": { "windows-x86_64": "2001", "linux-x86_64": "2002", "macos-universal": "2003" } }
  }
}
JSON

# shellcheck source=../steam-upload.sh
source <(sed 's/\r$//' "$here/../steam-upload.sh")
STEAM_CONFIG="$game/steam-build.json"
STEAM_STAGE="$tmp/stage" STEAM_BUILD_OUTPUT="$tmp/out" STEAM_VDF_DIR="$tmp/vdf"
PHPOLYGON_MAC_LAUNCHER="$tmp/launcher.sh" STEAMCMD="$tmp/steamcmd"
STEAM_NET_DEV="$tmp/netdev" STEAM_STATUS_INTERVAL="${STEAM_STATUS_INTERVAL:-1}" STEAM_STATUS_HEARTBEAT="${STEAM_STATUS_HEARTBEAT:-3}"
export FAKE_STEAM_NET_DEV="$tmp/netdev" FAKE_STEAM_ARGS_LOG="$tmp/args.log" FAKE_STEAM_SCALE="${FAKE_STEAM_SCALE:-0.05}"

stage_hook() { echo "$*" > "$tmp/hook.args"; [ -d "$STEAM_STAGE/macos-universal-steam-demo/App.app" ]; }
STEAM_STAGE_HOOK=stage_hook

# 1. dry run: the one session with both VDFs, no steamcmd call
out="$(STEAM_DRY_RUN=1 steam_upload_many alpha beta 2>&1)"; rc=$?
echo "$out" | sed 's/^/     | /'
check "dry run exit 0"             [ "$rc" = 0 ]
check "dry run shows one session"  has "$out" "$tmp/steamcmd +login tester +run_app_build $tmp/vdf/app_build_alpha.vdf +run_app_build $tmp/vdf/app_build_beta.vdf +quit"
check "dry run calls no steamcmd"  [ ! -e "$tmp/args.log" ]
check "VDF build output"           grep -qF "\"BuildOutput\" \"$tmp/out\"" "$tmp/vdf/app_build_beta.vdf"
check "VDF demo content"           grep -qF 'linux-x86_64-steam-demo/App/*' "$tmp/vdf/app_build_beta.vdf"
check "stage hook got both types"  grep -qwF "steam-demo" "$tmp/hook.args"
check "stage keeps both types"     [ -d "$tmp/stage/windows-x86_64-steam/App" ] && [ -d "$tmp/stage/windows-x86_64-steam-demo/App" ]

# 2. upload: one session, status blocks, summary; an old depot log is ignored
mkdir -p "$tmp/out"
printf '[2020-01-01 00:00:00]: Starting build for depot 2003\n[2020-01-01 00:00:09]: Success! New manifestID 999999 created and 7 new chunks uploaded.\n' \
    > "$tmp/out/depot_build_2003.log"
touch -d '2020-01-01' "$tmp/out/depot_build_2003.log"
out="$(steam_upload_many alpha beta 2>&1)"; rc=$?
echo "$out" | sed 's/^/     | /'
check "upload exit 0"              [ "$rc" = 0 ]
check "exactly one session"        [ "$(wc -l < "$tmp/args.log")" = 1 ]
check "session runs both apps"     [ "$(grep -o '+run_app_build' "$tmp/args.log" | wc -l)" = 2 ]
check "no CR, no cursor control"   no_cr "$out"
check "label from config"          has "$out" "  Alpha App "
check "target name as label"       grep -qE '^  beta +windows' <<<"$out"
check "waiting state"              has "$out" "macos waiting"
check "scan state"                 has "$out" "⠿ scan"
check "upload state with rate"     grep -qE '⠿ upload [1-9][0-9]*/~28[01] MB · [0-9.]+ MB/s' <<<"$out"
check "done with duration"         has "$out" "windows ✓ 40 s"
check "old log not taken as done"  hasnt "$out" "999999"
check "summary manifest + chunks"  grep -qE 'windows +✓ 40 s · manifest [0-9]+ · 900 new chunks · added 1000 \(250.00 MB\), changed 20 \(30.50 MB\), removed 5 \(1.25 MB\)' <<<"$out"
check "summary build ids"          grep -qE 'beta \(app 2000, BuildID [0-9]+\)' <<<"$out"
check "summary total"              has "$out" "  total: "
check "file names do not fail"     hasnt "$out" "✗"
steamlog="$(ls "$tmp"/out/steamcmd-*.log | head -1)"
check "raw steamcmd output kept"   grep -q "Logging in user 'tester'" "$steamlog"
check "status throttled"           [ "$(grep -c 'steam upload$' <<<"$out")" -lt 30 ]

# 3. a depot upload fails: non-zero, marked, log tail + path
rm -f "$tmp/args.log"
out="$(FAKE_STEAM_FAIL_DEPOT=1002 steam_upload_many alpha beta 2>&1)"; rc=$?
echo "$out" | sed 's/^/     | /'
check "failure exit non-zero"      [ "$rc" != 0 ]
check "failed depot marked"        has "$out" "linux ✗ failed"
check "later depot not built"     has "$out" "macos ✗ not built"
check "failure names the log"      has "$out" "full steamcmd log: $tmp/out/steamcmd-"
check "failure shows log lines"    has "$out" "ERROR! Failed to commit build for AppID 1000"

# 4. a missing build stops everything before steamcmd
rm -f "$tmp/args.log"; rm -rf build/macos-x86_64-steam-demo
out="$(steam_upload_many alpha beta 2>&1)"; rc=$?
echo "$out" | sed 's/^/     | /'
check "missing build non-zero"     [ "$rc" != 0 ]
check "missing build no upload"    [ ! -e "$tmp/args.log" ]
check "missing build reported"     has "$out" "nothing uploaded"

[ "$fails" = 0 ] && echo "all passed" || { echo "$fails failed"; exit 1; }
