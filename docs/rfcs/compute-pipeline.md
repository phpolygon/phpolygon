# RFC: GPU Compute Pipeline

**Status**: draft
**Author**: engineering
**Last update**: 2026-05-10
**Related issues / PRs**: cloth-procedural (this branch), Netrunner roadmap

## Summary

Add general-purpose GPU compute support to the engine: a single
`Renderer3DInterface::dispatchCompute()` plus storage-buffer (SSBO)
lifecycle, callable from any system that wants to offload data-parallel
work to the GPU. Unlocks **physical cloth simulation, GPU particle
solvers, GPU navmesh queries, and GPU procedural mesh generation**
under one foundation.

The procedural-vertex-shader cloth that this branch adds is the
acknowledged stop-gap. It is good for ~90% of background characters
in a Cyberpunk-class scene; it is **not** sufficient for hero-cloth
that needs collision against the world or self-intersection. That is
the use case this RFC enables.

## Why this is its own RFC, not a PR

The change is **out-of-band of the visual-quality + memory streams**
because it touches the lowest layer of the engine (the Renderer3D
interface and every backend's pipeline / buffer abstraction). It will
be either a multi-week PR-stream (~3-4 weeks for the OpenGL + Vio +
Metal trio) or a `php-vio` extension change, depending on the path
chosen below.

## Two implementation paths

### Path A: Native PHP extension (`ext-phpolygon-compute`)

A new C extension that exposes a small compute-only API:

```php
$ctx = compute_create($glContext);  // attach to existing GL/Vulkan ctx
$buffer = compute_buffer_create($ctx, $sizeBytes, COMPUTE_USAGE_STORAGE);
compute_buffer_upload($buffer, $packedFloats);
$program = compute_program_create($ctx, $glslSource);
compute_dispatch($program, $groupX, $groupY, $groupZ, [$buffer => 0]);
$result = compute_buffer_download($buffer);
```

Pros:
- Decoupled from existing renderer extensions; can ship in any PHP
  install that has the right GPU API exposed.
- Control over performance (no FFI overhead).
- Versioned independently of the engine.

Cons:
- New extension = new repo + new CI matrix + new release pipeline.
- Has to wrap **three** GPU APIs (OpenGL compute / Vulkan compute /
  Metal compute) - basically duplicating the platform abstraction
  that `php-vio` already does internally.
- Forces every user to install yet another extension on top of
  php-vio / php-glfw.

### Path B: Extend `php-vio`

Add `vio_compute_*` functions to the existing `php-vio` extension.
vio already abstracts OpenGL / Vulkan / Metal / D3D internally; adding
a compute backend per platform is a 1-2 week task per backend (4-8
weeks total for full coverage).

Pros:
- **No new extension to install.** Anyone already using vio gets
  compute for free.
- vio's existing platform-abstraction means the engine talks one API,
  not three.
- vio's existing buffer / pipeline lifecycle scales naturally to
  compute objects.
- Compute pipelines and graphics pipelines can share buffers cheaply
  (no GL→Vulkan→Metal interop pain).

Cons:
- Couples PHPolygon's compute story to the upstream vio release
  cycle. If a feature is needed before vio ships it, the engine is
  stuck.
- vio is already a substantial codebase; growing it has friction.
- Doesn't help games that opt into the standalone GLFW / Vulkan /
  Metal backends without vio.

### Recommendation

**Path B (extend vio).** vio is already the engine's primary backend
("CLAUDE.md: vio is the production path on every platform"); the
standalone backends exist for environments where vio is unavailable.
Building compute on top of vio is the path of least resistance and
inherits vio's existing platform-abstraction layer.

Standalone OpenGL / Vulkan / Metal backends would gain a compute
implementation **second**, only when a real game needs it on a
vio-less environment. Most won't.

## Required engine-side surface

Regardless of which underlying extension provides the primitives,
the engine's renderer interface needs:

```php
interface Renderer3DInterface
{
    // ... existing methods ...

    /**
     * Whether this backend supports GPU compute. Vio + modern OpenGL
     * + Vulkan + Metal: yes. Null backend: no.
     */
    public function supportsCompute(): bool;

    /**
     * Allocate a GPU storage buffer (SSBO equivalent).
     * Returns an opaque handle the renderer can later bind.
     */
    public function createStorageBuffer(int $sizeBytes): int;
    public function uploadStorageBuffer(int $handle, string $packedBytes): void;
    public function downloadStorageBuffer(int $handle): string;
    public function releaseStorageBuffer(int $handle): void;

    /**
     * Compile a compute shader (GLSL `#version 430` for OpenGL,
     * MSL kernel for Metal, SPIR-V for Vulkan). Returns an opaque
     * program handle.
     */
    public function compileComputeProgram(string $sourceOrPath): int;

    /**
     * Dispatch a compute program. $bindings maps storage-buffer
     * handles to binding slots inside the shader.
     *
     * @param array<int, int> $bindings buffer-handle => slot
     */
    public function dispatchCompute(
        int $program,
        int $groupCountX,
        int $groupCountY = 1,
        int $groupCountZ = 1,
        array $bindings = [],
    ): void;
}
```

Plus an engine-wide `ComputeRegistry` (parallel to `MeshRegistry` /
`MaterialRegistry`) that caches compiled programs and buffer handles
by id so systems don't recompile every frame.

## What this unlocks (and why each matters)

| Feature | Why GPU compute is the right tool | Estimated benefit |
|---|---|---|
| **Physical cloth** (Bullet-class XPBD solver) | Embarrassingly parallel per-vertex constraint solving, native fit for compute. | Hero-character cloth with collision. |
| **GPU particles** | Per-particle integrate + collide + spawn/kill on the GPU; uploads zero per-frame data. | 100k+ particles at 60fps (instead of current 4k cap). |
| **GPU navmesh queries** | Many simultaneous A\* / flow-field queries from many AI agents. | 100+ NPC pathfinding without saturating the CPU AI thread. |
| **GPU procedural mesh-gen** | Marching cubes, voronoi, terrain heightmaps, building generators. Currently all PHP main-thread. | Procedural city blocks generated in milliseconds instead of seconds. |
| **Compute-driven LOD selection** | Per-instance distance + viewport size feed an indirect-draw culling pass. | 10x more visible instances per frame at the same draw-call budget. |
| **Auto-exposure / histogram for tone mapping** | Read scene luminance via reduction kernel; feed back into ACES curve. | Real auto-exposure (currently a hard-coded multiplier). |

The cloth use case alone is borderline-justifiable. The combined
five use cases above are the actual ROI of building this layer.

## Cost estimate

- **Path B (vio extension)**: ~2-3 weeks of vio work + ~1-2 weeks
  engine-side wrappers + cache + per-system integrations. Total
  **3-5 weeks** for a usable foundation; cloth solver itself another
  1-2 weeks on top.
- **Path A (new ext)**: ~6-8 weeks including the platform matrix,
  CI, release plumbing.

## Decision points

1. **Path A vs B** (recommendation: B).
2. **Which use case ships first** as the proof-of-concept that
   exercises the API? (recommendation: GPU particles - simplest,
   visually obvious, doesn't need world collision).
3. **Do we accept dependency on vio** for PHPolygon's compute story?
   (recommendation: yes; vio is already the documented primary
   backend.)

## Out of scope here

- Specific compute shader implementations (cloth solver, particle
  integrator, etc.). Each is a separate PR built on top of this
  foundation.
- Mesh storage refactor (covered by M2 in the memory-optimisation
  stream).
- Shader hot-reload tooling (worth its own RFC if compute makes
  iteration slow).

## Open questions

- **Synchronisation primitives**: explicit fences vs. let the driver
  schedule? For game-loop integration we probably need a
  `dispatchComputeAsync` + `waitCompute` pair so the cloth solver
  can run in parallel with the main render pass.
- **Buffer interop with VBO/EBO**: cloth output is the next frame's
  vertex buffer. vio's existing buffer types need to be unified or
  we need a `compute_buffer_to_vbo` zero-copy bridge.
- **PHP / GPU memory budget bookkeeping**: per-game cap on total GPU
  storage to prevent runaway allocation in user scripts.
