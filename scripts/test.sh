#!/usr/bin/env bash
# Runs the full test suite: in parallel with ParaTest, then the serial groups with PHPUnit.
#
#   1. parallel: everything except the groups 'gpu' and 'timing' (ParaTest)
#   2. serial:   the groups 'gpu' and 'timing' (PHPUnit, one process)
#
# Options (environment variables):
#   PROCESSES=6        ParaTest worker processes
#   FILTER=<pattern>   PHPUnit --filter pattern, applied to both parts
#   NO_GPU=1           skip the serial 'gpu' group
#   EXCLUDE_GROUP=font-vrt   further group left out of both parts (default as in CI)
#
# Example: PROCESSES=4 FILTER=Geometry scripts/test.sh

set -o pipefail
cd "$(dirname "$0")/.."

processes="${PROCESSES:-6}"
exclude="${EXCLUDE_GROUP-font-vrt}"
filter_args=()
if [ -n "${FILTER:-}" ]; then
    filter_args=(--filter "$FILTER")
fi

# One option per group: comma lists are deprecated in PHPUnit 11 and the warnings fail the run.
serial_args=(--group gpu --group timing)
if [ "${NO_GPU:-0}" = "1" ]; then
    serial_args=(--group timing)
fi
parallel_args=(--exclude-group gpu --exclude-group timing)
if [ -n "$exclude" ]; then
    parallel_args+=(--exclude-group "$exclude")
    serial_args+=(--exclude-group "$exclude")
fi

echo "== parallel run ($processes processes, ${parallel_args[*]})"
php -d memory_limit=-1 vendor/bin/paratest --processes="$processes" --passthru-php="-d memory_limit=-1" \
    --no-progress "${parallel_args[@]}" "${filter_args[@]}"
parallel=$?

echo "== serial run (${serial_args[*]})"
php -d memory_limit=-1 vendor/bin/phpunit "${serial_args[@]}" "${filter_args[@]}"
serial=$?

if [ "$parallel" -ne 0 ] || [ "$serial" -ne 0 ]; then
    echo "FAILED: parallel exit $parallel, serial exit $serial"
    exit 1
fi
echo "OK"
