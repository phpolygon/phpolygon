#!/usr/bin/env bash
#
# Tests for docker/sync-workdir.sh (sync_workdir / seed_workdir + progress log).
# Needs bash, rsync and awk - run it in the build image:
#
#   docker run --rm --entrypoint bash -v "$PWD/docker:/harness" phpolygon-build \
#       /harness/tests/sync-workdir-test.sh
set -uo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../sync-workdir.sh
source <(sed 's/\r$//' "$here/../sync-workdir.sh")

tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
fails=0
check() { # check NAME CONDITION...
    local name="$1"; shift
    if "$@"; then echo "ok   $name"; else echo "FAIL $name"; fails=$((fails + 1)); fi
}
has() { grep -qF -- "$2" <<<"$1"; }

src="$tmp/app"; dst="$tmp/work"
export PHPOLYGON_SYNC_SRC="$src"
mkdir -p "$src/src" "$src/artwork" "$src/assets/voice" "$src/build"
for i in $(seq 1 40); do head -c 200000 /dev/urandom > "$src/src/f$i.bin"; done
echo art > "$src/artwork/a.txt"; echo out > "$src/build/x"

# 1. first mirror: copies, prints progress + a summary, honours the excludes
export PHPOLYGON_SYNC_EXCLUDE="artwork/ assets/voice/" PHPOLYGON_SYNC_PROGRESS_STEP=25
out="$(sync_workdir "$dst")"; rc=$?
echo "$out" | sed 's/^/     | /'
check "mirror exit 0"              [ "$rc" = 0 ]
check "mirror copied files"        [ -f "$dst/src/f40.bin" ]
check "mirror skips excludes"      [ ! -e "$dst/artwork" ]
check "mirror skips build/"        [ ! -e "$dst/build/x" ]
check "mirror summary"             has "$out" "sync: done – 40 of 40 files"
no_cr() { [[ "$1" != *$'\r'* ]]; }
check "progress lines, no CR"      no_cr "$out"
check "progress line format"       has "$out" " % – "

# 2. nothing changed: exactly one line after the header (even with a 0 s
#    progress interval: no "0 % – 0 B" lines)
out="$(PHPOLYGON_SYNC_PROGRESS_SECS=0 sync_workdir "$dst")"
echo "$out" | sed 's/^/     | /'
check "no-op is one line"          [ "$(wc -l <<<"$out")" = 2 ]
check "no-op says up to date"      has "$out" "sync: up to date – 40 files checked"

# 3. seed: newer host files win, older ones do not, volume-only files stay
mkdir -p "$dst/assets/voice"
echo container-new > "$dst/assets/voice/keep.txt"; touch -d '2030-01-01' "$dst/assets/voice/keep.txt"
echo host-old > "$src/assets/voice/keep.txt";      touch -d '2020-01-01' "$src/assets/voice/keep.txt"
echo host-new > "$src/assets/voice/new.txt"
echo only-vol > "$dst/assets/voice/only-volume.txt"
export PHPOLYGON_SYNC_SEED="assets/voice/ assets/missing/"
out="$(seed_workdir "$dst")"; rc=$?
echo "$out" | sed 's/^/     | /'
check "seed exit 0"                [ "$rc" = 0 ]
check "seed copies newer host"     [ "$(cat "$dst/assets/voice/new.txt")" = host-new ]
check "seed keeps newer volume"    [ "$(cat "$dst/assets/voice/keep.txt")" = container-new ]
check "seed deletes nothing"       [ -f "$dst/assets/voice/only-volume.txt" ]
check "seed summary"               has "$out" "seed assets/voice/: done – 1 of 2 files"
check "seed missing path reported" has "$out" "seed assets/missing: not on the host – skipped"

# 4. the mirror keeps seeded (excluded) paths
sync_workdir "$dst" >/dev/null
check "mirror keeps excluded seed" [ -f "$dst/assets/voice/new.txt" ]

[ "$fails" = 0 ] && echo "all passed" || { echo "$fails failed"; exit 1; }
