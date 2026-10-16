#!/bin/bash
#
# Contents/MacOS/<App> of a universal PHPolygon bundle: starts <App>-arm64 on
# Apple Silicon - also when this script itself runs translated by Rosetta, where
# `uname -m` says x86_64 - and <App>-x86_64 on Intel Macs.
#
# Writes ~/Library/Logs/<App>/launch.log (the previous start is kept as
# launch.log.1): the chosen binary, the game's stderr and its exit status. A
# runtime the system kills on start (e.g. a rejected signature: status 137 =
# SIGKILL) leaves no game.log, but these lines. SIGTERM/SIGINT/SIGHUP (Steam's
# "stop", logout) are passed on to the game.
#
# A game can ship its own script instead: build.json platforms.macos.launcher.

DIR="$(cd "$(dirname "$0")" && pwd)"
APP="$(basename "$0")"
LOG_DIR="$HOME/Library/Logs/$APP"
mkdir -p "$LOG_DIR" 2>/dev/null
LOG="$LOG_DIR/launch.log"
[ -f "$LOG" ] && mv -f "$LOG" "$LOG.1" 2>/dev/null
log() { echo "[$(date -u '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG" 2>/dev/null; }

if [ "$(sysctl -n hw.optional.arm64 2>/dev/null)" = "1" ]; then
    BIN="$DIR/$APP-arm64"
    RUN=(arch -arm64 "$BIN")
else
    BIN="$DIR/$APP-x86_64"
    RUN=("$BIN")
fi
log "start $BIN (uname -m: $(uname -m), macOS $(sw_vers -productVersion 2>/dev/null))"
if [ ! -f "$BIN" ]; then log "missing: $BIN"; exit 127; fi
if [ ! -x "$BIN" ]; then log "not executable: $BIN"; exit 126; fi

"${RUN[@]}" "$@" 2>>"$LOG" &
child=$!
trap 'kill -TERM "$child" 2>/dev/null' TERM INT HUP
wait "$child"
rc=$?
# A trapped signal ends `wait` early: wait for the game's own exit status.
while kill -0 "$child" 2>/dev/null; do
    wait "$child"
    rc=$?
done
if [ "$rc" -gt 128 ]; then log "exit $rc (signal $((rc - 128)))"; else log "exit $rc"; fi
exit "$rc"
