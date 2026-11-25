#!/usr/bin/env bash
# Runs the full test suite: ParaTest for the plain tests, ParaTest on the hardware GPU for the render
# tests, then the timing-sensitive tests alone. Same parts as scripts/test.ps1.
#
#   1. parallel: everything except the groups 'gpu' and 'timing'
#   2. gpu:      group 'gpu' without 'timing', GPU_PROCESSES workers, each with its own headless context
#   3. serial:   group 'timing' in one PHPUnit process
#
# Render tests never run on a software adapter locally (llvmpipe, lavapipe): the script probes the adapter
# first and skips the gpu part with a message (REQUIRE_GPU=1 fails instead). With CI set the software
# adapter is used as before.
#
# Options (environment variables):
#   PROCESSES=6        ParaTest workers of the plain part
#   GPU_PROCESSES=6    ParaTest workers of the gpu part (default 6)
#   ADAPTER=nvidia     nvidia | intel | all: the hardware adapter(s) of the gpu part, one after the other
#   FILTER=<pattern>   PHPUnit --filter pattern, applied to every part
#   NO_GPU=1           skip the gpu part
#   REQUIRE_GPU=1      fail when no hardware adapter is available
#   EXCLUDE_GROUP=font-vrt   further group left out of every part (default as in CI)
#   PHPOLYGON_GPU_LOCK=<file>  GPU lock file, taken once around the gpu part (none by default)
#
# Example: PROCESSES=4 FILTER=Geometry scripts/test.sh

set -o pipefail
cd "$(dirname "$0")/.."

processes="${PROCESSES:-6}"
gpu_processes="${GPU_PROCESSES:-6}"
exclude="${EXCLUDE_GROUP-font-vrt}"
filter_args=()
if [ -n "${FILTER:-}" ]; then
    filter_args=(--filter "$FILTER")
fi
exclude_args=()
if [ -n "$exclude" ]; then
    exclude_args=(--exclude-group "$exclude")
fi

in_ci=0
for name in CI GITHUB_ACTIONS; do
    value="${!name:-}"
    if [ -n "$value" ] && [ "$value" != "0" ] && [ "$value" != "false" ]; then
        in_ci=1
    fi
done

echo "== parallel run ($processes processes, without groups gpu, timing, $exclude)"
php -d memory_limit=-1 vendor/bin/paratest --processes="$processes" --passthru-php="-d memory_limit=-1" \
    --no-progress --exclude-group gpu --exclude-group timing "${exclude_args[@]}" "${filter_args[@]}"
parallel=$?

gpu=0
gpu_note=""
adapter_report=()
if [ "${NO_GPU:-0}" != "1" ]; then
    adapter="${ADAPTER:-nvidia}"
    case "$adapter" in
        all) selected=(nvidia intel) ;;
        nvidia|intel) selected=("$adapter") ;;
        *) echo "FAILED: ADAPTER must be nvidia, intel or all"; exit 1 ;;
    esac
    saved_adapter="${PHPOLYGON_TEST_VIO_ADAPTER:-}"
    for which in "${selected[@]}"; do
        # nvidia = the machine's preferred (discrete) adapter, no explicit choice
        if [ "$adapter" != "all" ] && [ -n "$saved_adapter" ]; then
            export PHPOLYGON_TEST_VIO_ADAPTER="$saved_adapter"
        elif [ "$which" = "intel" ]; then
            export PHPOLYGON_TEST_VIO_ADAPTER="Intel"
        else
            unset PHPOLYGON_TEST_VIO_ADAPTER
        fi
        unset PHPOLYGON_TEST_ADAPTER_CLASS
        class="$(php -r 'require "vendor/autoload.php"; echo PHPolygon\Tests\GpuPolicy::adapterClass();')"
        export PHPOLYGON_TEST_ADAPTER_CLASS="$class"
        echo "== adapter $which -> class: $class"
        if [ "$in_ci" = "0" ] && { [ "$class" = "software" ] || [ "$class" = "none" ] \
            || { [ "$adapter" = "all" ] && [ "$class" != "hardware-$which" ]; }; }; then
            if [ "${REQUIRE_GPU:-0}" = "1" ]; then
                echo "FAILED: REQUIRE_GPU=1, but adapter '$which' is not available as hardware (adapter class '$class')"
                gpu=1
            elif [ "$adapter" = "all" ]; then
                echo "== adapter $which not available here (class '$class'), skipped"
                adapter_report+=("$which : not available")
            else
                gpu_note="gpu part SKIPPED: no hardware adapter (adapter class '$class'); render tests never run on software locally"
                echo "== $gpu_note"
            fi
            continue
        fi
        workers="${GPU_PROCESSES:-}"
        if [ -z "$workers" ]; then
            workers=6
        fi
        lock="${PHPOLYGON_GPU_LOCK:-}"
        if [ -n "$lock" ]; then
            while ! ( set -o noclobber; echo "$$" > "$lock" ) 2>/dev/null; do
                holder="$(cat "$lock" 2>/dev/null)"
                if [ -n "$holder" ] && ! kill -0 "$holder" 2>/dev/null; then
                    rm -f "$lock"
                    continue
                fi
                echo "waiting for the GPU lock (pid $holder)"
                sleep 15
            done
        fi
        began=$SECONDS
        echo "== gpu run on $which ($workers processes, group gpu without timing)"
        php -d memory_limit=-1 vendor/bin/paratest --processes="$workers" --passthru-php="-d memory_limit=-1" \
            --no-progress --group gpu --exclude-group timing "${exclude_args[@]}" "${filter_args[@]}"
        exit_code=$?
        if [ -n "$lock" ]; then
            rm -f "$lock"
        fi
        if [ "$exit_code" -ne 0 ]; then gpu=1; fi
        adapter_report+=("$which : exit $exit_code, $((SECONDS - began)) s")
    done
    for line in "${adapter_report[@]}"; do
        echo "== gpu report: $line"
    done
fi

echo "== serial run (group timing)"
php -d memory_limit=-1 vendor/bin/phpunit --group timing "${exclude_args[@]}" "${filter_args[@]}"
serial=$?

if [ -n "$gpu_note" ]; then
    echo "$gpu_note"
fi
if [ "$parallel" -ne 0 ] || [ "$gpu" -ne 0 ] || [ "$serial" -ne 0 ]; then
    echo "FAILED: parallel exit $parallel, gpu exit $gpu, serial exit $serial"
    exit 1
fi
echo "OK"
