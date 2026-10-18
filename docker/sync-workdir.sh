#!/usr/bin/env bash
#
# PHPOLYGON_WORKDIR mirror for build-all.sh (sourced, not run): copies the slow
# /app bind mount into a fast volume, with a progress log that reads line by line.
#
# On Docker Desktop (Windows/macOS) the /app bind mount is slow for many small
# files - measured on a 594 MB / 18k-file package: 98 s to write it there, 1 s
# into a volume, 64 s to delete it, 186 s to read it. With PHPOLYGON_WORKDIR set
# (mount a named volume there), /app is mirrored into it with rsync - the first
# run copies, later runs only what changed - and everything (hooks, staging,
# packaging, build/) happens there.
#
# The source is /app (PHPOLYGON_SYNC_SRC overrides it - for docker/tests only).
#
#   sync_workdir DST   mirror /app -> DST (rsync --delete). Not mirrored: build/
#                      (outputs and caches stay in the volume), .git and the
#                      project-relative rsync patterns in PHPOLYGON_SYNC_EXCLUDE
#                      ("artwork/ docs/"). resources.exclude IS mirrored: those
#                      files never ship, but hooks read them.
#   seed_workdir DST   for every path in PHPOLYGON_SYNC_SEED (relative to /app,
#                      space-separated): copy host files that are NEWER than the
#                      volume's (rsync --update, no --delete). For hook outputs
#                      (prebakes) that are excluded from the mirror so a stale host
#                      copy never overwrites the container's, but a newer host copy
#                      (re-rendered on the host) still saves the container the work.
#                      A path may be in both lists; a path missing on the host is
#                      reported and skipped.
#
# Progress: rsync --info=progress2 (one total percentage over the whole transfer,
# --no-inc-recursive) is turned into plain lines - at most one per
# PHPOLYGON_SYNC_PROGRESS_STEP percent (default 5) or PHPOLYGON_SYNC_PROGRESS_SECS
# seconds (default 10) - then one summary line. Nothing changed = only the summary.
#
#   sync: 42 % – 1.2 GB – 18.0 MB/s – 0:45 left      (rsync's rate, our ETA)
#   sync: done – 1,992 of 18,345 files, 59.0 MB of 2.1 GB, 1 deleted – 14 s

# The awk program for rsync_progress. mawk (Debian's awk) has systime/fflush.
# RS splits on \r too: progress2 redraws its line with \r, which a pipe would
# otherwise deliver as one endless line.
_SYNC_PROGRESS_AWK='
function hb(n,   u, i) {
    split("B KB MB GB TB", u, " "); i = 1
    while (n >= 1024 && i < 5) { n /= 1024; i++ }
    return i == 1 ? sprintf("%d B", n) : sprintf("%.1f %s", n, u[i])
}
function num(s) { gsub(/[^0-9]/, "", s); return s + 0 }
function grp(n,   s, out) {
    s = sprintf("%d", n); out = ""
    while (length(s) > 3) { out = "," substr(s, length(s) - 2) out; s = substr(s, 1, length(s) - 3) }
    return s out
}
function rate(r,   v, u) {
    if (!match(r, /^[0-9.]+/)) return r
    v = substr(r, 1, RLENGTH); u = substr(r, RLENGTH + 1)
    sub(/^k/, "K", u); sub(/\/s$/, "", u)
    return sprintf("%.1f %s/s", v, u)
}
# rsync prints the elapsed time on a finished file, so the ETA is our own:
# elapsed * (100 - p) / p.
function clock(sec) {
    sec = int(sec + 0.5)
    if (sec >= 3600) return sprintf("%d:%02d:%02d", sec / 3600, (sec % 3600) / 60, sec % 60)
    return sprintf("%d:%02d", sec / 60, sec % 60)
}
function eta(p, elapsed) { return p > 0 && elapsed > 0 ? clock(elapsed * (100 - p) / p) : "?" }
BEGIN { RS = "[\r\n]+"; t0 = systime(); lastt = t0; lastp = 0 }
$1 ~ /^[0-9,]+$/ && $2 ~ /^[0-9]+%$/ {
    p = $2 + 0; now = systime()
    if (p < 100 && num($1) > 0 && (p >= lastp + step || now - lastt >= every)) {
        left = eta(p, now - t0)
        printf "  %s: %d %% – %s – %s%s\n", label, p, hb(num($1)), rate($3), (left == "?" ? "" : " – " left " left"); fflush()
        lastp = p; lastt = now
    }
    next
}
/^Number of files:/ {
    files = num($4)
    if (match($0, /reg: [0-9,]+/)) files = num(substr($0, RSTART + 5, RLENGTH - 5))
    next
}
/^Number of regular files transferred:/ { xfer = num($NF); next }
/^Number of deleted files:/             { deleted = num($5); next }
/^Total file size:/                     { total = num($4); next }
/^Total transferred file size:/         { bytes = num($5); next }
/^(Number of|Total |Literal data|Matched data|File list|sent |total size is)/ { next }
/^[ \t]*$/ { next }
{ print "  " label ": " $0; fflush() }
END {
    dur = systime() - t0
    if (xfer == 0 && deleted == 0)
        printf "  %s: up to date – %s files checked (%s) – %d s\n", label, grp(files), hb(total), dur
    else
        printf "  %s: done – %s of %s files, %s of %s%s – %d s\n", label, grp(xfer), grp(files), \
            hb(bytes), hb(total), (deleted > 0 ? ", " grp(deleted) " deleted" : ""), dur
}'

# rsync_progress LABEL RSYNC_ARGS... - rsync with the line-wise progress log.
# Returns rsync's exit code; rsync's own errors stay on stderr.
rsync_progress() {
    local label="$1" rc; shift
    rsync --info=stats2,progress2 --no-inc-recursive "$@" \
        | awk -v label="$label" \
              -v step="${PHPOLYGON_SYNC_PROGRESS_STEP:-5}" \
              -v every="${PHPOLYGON_SYNC_PROGRESS_SECS:-10}" \
              "$_SYNC_PROGRESS_AWK"
    rc="${PIPESTATUS[0]}"
    case "$rc" in
        0)  ;;
        24) echo "  ${C_R:-}${label}: files vanished on the host while copying (rsync exit 24) – the host changed meanwhile, run again${C_0:-}" ;;
        *)  echo "  ${C_R:-}${label}: rsync failed (exit ${rc})${C_0:-}" ;;
    esac
    return "$rc"
}

sync_workdir() {
    local dst="$1" src="${PHPOLYGON_SYNC_SRC:-/app}" pattern
    local -a excludes=(--exclude=/build/ --exclude=/.git/)
    for pattern in ${PHPOLYGON_SYNC_EXCLUDE:-}; do
        excludes+=("--exclude=/${pattern#/}")
    done
    mkdir -p "$dst"
    echo "  mirroring ${src} -> ${dst} (rsync, ${#excludes[@]} excludes)..."
    rsync_progress sync -a --delete "${excludes[@]}" "$src/" "$dst/" || return 1
}

seed_workdir() {
    local dst="$1" src="${PHPOLYGON_SYNC_SRC:-/app}" path rel
    for path in ${PHPOLYGON_SYNC_SEED:-}; do
        rel="${path#/}"; rel="${rel%/}"
        [ -n "$rel" ] || continue
        if [ -d "$src/$rel" ]; then
            mkdir -p "$dst/$rel"
            rsync_progress "seed ${rel}/" -a --update "$src/$rel/" "$dst/$rel/" || return 1
        elif [ -e "$src/$rel" ]; then
            mkdir -p "$dst/$(dirname "$rel")"
            rsync_progress "seed ${rel}" -a --update "$src/$rel" "$dst/$rel" || return 1
        else
            echo "  seed ${rel}: not on the host – skipped"
        fi
    done
}
