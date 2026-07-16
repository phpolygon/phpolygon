#!/bin/sh
# Run the PHPolygon test suite against the real php-vio backend, headless.
#
# vio's MINIT calls glfwInit(), which needs a display, and the native 3D VRT
# needs a GPU context — both provided GPU-free by Xvfb + Mesa lavapipe/llvmpipe.
# Font VRT is excluded: FreeType output here differs from the pinned Alpine
# image that owns those snapshots (.docker/vrt.Dockerfile).
#
# Inside the phpolygon-vrt-vio image:
#   docker run --rm -v "$(pwd)":/app -w /app phpolygon-vrt-vio .docker/vrt-vio-run.sh
# Extra phpunit args pass through, e.g. `.docker/vrt-vio-run.sh tests/Rendering`.
set -eu

if [ ! -d vendor ]; then
    composer install --no-interaction --ignore-platform-reqs
fi

echo "=== backend availability ==="
xvfb-run -a php -r 'echo "vio: ", extension_loaded("vio") ? "loaded" : "MISSING", "\n";'
xvfb-run -a vulkaninfo --summary 2>/dev/null | grep -iE "driverName|deviceName" | head -2 || true

# native-gpu is excluded here: those tests need a hardware/real backend. Mesa's
# software Vulkan (lavapipe) rejects the mesh shader's sampler-register layout,
# and per-backend pixel read-back baselines are taken on the target GPU. The
# rest of the suite runs green with vio loaded as the primary backend.
echo "=== phpunit (vio backend, excl. font-vrt + native-gpu) ==="
xvfb-run -a vendor/bin/phpunit --exclude-group font-vrt --exclude-group native-gpu "$@"
