#!/bin/sh
# Run the OpenGL version-matrix harness across every ladder rung.
#
# Forces the Mesa-reported GL version per rung via MESA_GL_VERSION_OVERRIDE and
# runs .docker/gl-harness.php under a virtual display. Each rung must create a
# context, detect the expected version, compile every built-in shader and render
# a scene (plain + instanced draws) without a GL error.
#
# Intended to run inside the phpolygon-gl image:
#   docker run --rm -v "$(pwd)":/app -w /app phpolygon-gl .docker/gl-matrix.sh
set -eu

if [ ! -d vendor ]; then
    composer install --no-interaction --ignore-platform-reqs
fi

# rung: "GL_VERSION GLSL_VERSION". 3.3CORE/4.1CORE request a core profile so
# the engine's core-profile path (the shipping default on 3.2+) is exercised.
RUNGS="3.0:130 3.1:140 3.3CORE:150 4.1CORE:410 4.6CORE:460"

fail=0
for rung in $RUNGS; do
    gl="${rung%%:*}"
    glsl="${rung##*:}"
    printf '\n=== GL override %s (GLSL %s) ===\n' "$gl" "$glsl"
    if xvfb-run -a env \
            MESA_GL_VERSION_OVERRIDE="$gl" \
            MESA_GLSL_VERSION_OVERRIDE="$glsl" \
            php .docker/gl-harness.php; then
        :
    else
        echo "RUNG FAILED: GL $gl"
        fail=1
    fi
done

echo ""
if [ "$fail" -eq 0 ]; then
    echo "ALL RUNGS PASSED"
else
    echo "ONE OR MORE RUNGS FAILED"
fi
exit "$fail"
