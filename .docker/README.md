# Docker test images

Two independent images. Neither needs a GPU.

## `vrt.Dockerfile` — deterministic 2D pixel VRT

Pinned Alpine so FreeType/libgd output is byte-identical across machines. Runs
the `tests/Testing/` snapshot suite (shapes, scenes, fonts, UI widgets).

```sh
docker build -f .docker/vrt.Dockerfile -t phpolygon-vrt .
# verify
docker run --rm -v "$(pwd)":/app -w /app phpolygon-vrt \
  sh -c 'composer install --ignore-platform-reqs && vendor/bin/phpunit tests/Testing/'
# regenerate snapshots
docker run --rm -v "$(pwd)":/app -w /app phpolygon-vrt \
  sh -c 'composer install --ignore-platform-reqs && PHPOLYGON_UPDATE_SNAPSHOTS=1 vendor/bin/phpunit tests/Testing/'
```

## `gl.Dockerfile` — OpenGL version matrix (3.0 → 4.6)

Runs the standalone **php-glfw** OpenGL 3D backend headlessly on Mesa's
`llvmpipe` software rasteriser under Xvfb. The reported GL version is forced per
rung with `MESA_GL_VERSION_OVERRIDE`, so a single image exercises every step of
the engine's context ladder without any real GPU.

This closes the "verify on real hardware" gap for the GL 3.0–3.3 support: it
validates context creation, GLSL `#version` injection (150 core / 140 / 130),
shader compilation and rendering (plain + instanced draws) at each version.

```sh
docker build -f .docker/gl.Dockerfile -t phpolygon-gl .
docker run --rm -v "$(pwd)":/app -w /app phpolygon-gl .docker/gl-matrix.sh
```

`gl-matrix.sh` runs `gl-harness.php` under each rung and prints one line each:

```
[gl-harness] gl=3.0 tier=30 glsl='#version 130'      instancing=cpu-fallback shaders=all-ok
[gl-harness] gl=3.1 tier=31 glsl='#version 140'      instancing=cpu-fallback shaders=all-ok
[gl-harness] gl=3.3 tier=33 glsl='#version 150 core' instancing=core         shaders=all-ok
[gl-harness] gl=4.1 tier=41 glsl='#version 150 core' instancing=core         shaders=all-ok
[gl-harness] gl=4.6 tier=46 glsl='#version 150 core' instancing=core         shaders=all-ok
ALL RUNGS PASSED
```

### Why software rendering

`llvmpipe` reports ~4.5 natively; `MESA_GL_VERSION_OVERRIDE` pins it down to any
lower version exactly (proven: 3.0/3.1/3.3/4.1/4.6 → exact `glGetString`). The
override drives what `GlCapabilities::detect()` sees and how strict the GLSL
compiler is, which is precisely what the ladder + version-injection logic needs
to be exercised against. Caveat: software Mesa is stricter than most GPU drivers
(it already caught a real call-before-definition shader bug that lenient GPU
drivers silently accept) but cannot reproduce vendor-specific (NVIDIA/AMD) quirks.

### Follow-up: 3D pixel VRT

The harness currently asserts compile + render success, not pixel output. Adding
`glReadPixels` framebuffer read-back + `ScreenshotComparer` (as the 2D path does)
would turn this into full 3D pixel VRT across GL versions.
