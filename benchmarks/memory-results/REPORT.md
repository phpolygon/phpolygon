# PHPolygon Memory Profiling Report

**Date**: 2026-05-10
**SHA**: `03f9240` (main)
**Method**: `php benchmarks/memory.php all` — re-execs per scenario in a clean
subprocess, milestones recorded with `gc_collect_cycles()` before each
`memory_get_usage(false)` snapshot so cycle-trapped allocations don't
inflate the readings.

## Per-scenario steady-state footprint (PHP heap only)

| Scenario | startup | +engine | +scene-build | +frame 1 | steady (61f) | per-frame Δ |
|---|---:|---:|---:|---:|---:|---:|
| empty-scene | 1.58 MB | +1.02 MB | +74 KB | +30 KB | **2.70 MB** | +376 B |
| boxes-1000 | 1.58 MB | +1.02 MB | +1.92 MB | +888 KB | **5.45 MB** | +376 B |
| boxes-1000-instanced | 1.58 MB | +1.02 MB | +739 KB | +12 KB | **3.39 MB** | +376 B |
| mixed-scene | 1.58 MB | +1.02 MB | +920 KB | +227 KB | **3.72 MB** | +376 B |
| mesh-gen-stress | 1.58 MB | +1.02 MB | +416 B | +1.33 MB | **3.93 MB** | +376 B |
| physics-stack | 1.58 MB | +1.02 MB | +463 KB | +107 KB | **3.16 MB** | +376 B |

The 376 B per-frame delta is the milestone snapshot's own overhead
(`gc_status()` array). **Every scenario reaches steady-state at 0 B
genuine per-frame allocation.** That is the strongest single finding:
the per-frame hot path is already allocation-clean.

## Five concrete observations

### 1. PHP heap is not the bottleneck for typical workloads

The largest scenario (`boxes-1000`) tops out at **5.45 MB** of PHP-side
memory. A modern game targets 256 MB - 2 GB depending on platform; at
~2% of that budget the PHP heap is comfortable. **The "memory is
tight" intuition that drove the SplFixedArray attempt was wrong** -
or at least, not for any scenario currently shipped.

### 2. Per-entity ECS overhead is the most attributable hotspot

Comparing `boxes-1000` (1000 individual entities) vs.
`boxes-1000-instanced` (1 entity drawing 1000 instances):

  - boxes-1000:           5.45 MB
  - boxes-1000-instanced: 3.39 MB
  - **delta: ~2.06 MB across 1000 entities = ~2.0 KB per entity**

Two kilobytes per ECS entity is the closest thing to a real per-unit
cost in the engine today. For a 10k-entity open world that becomes
~20 MB, which matters. Optimisations that would help:

  - Component **struct-of-arrays storage** in the ECS world (parallel
    columns per Component-type instead of per-entity Component
    objects). Same SoA-vs-AoS trade-off the particle refactor
    surfaced — measure before refactoring.
  - **Component pooling** for fast-spawn/despawn workloads (bullets,
    pickups). Avoids the "create + GC" churn at the entity level.

### 3. Lazy mesh generation already works

`mesh-gen-stress`'s `scenario.setup` milestone shows a **+416-byte**
delta. The procedural meshes are not generated during
`SceneBuilder::materialize()`; the +1.33 MB shows up on `frame.1`
when `MeshRegistry::get()` first resolves them. This is the correct
shape — and it explains why M1 (`MeshRegistry::registerLazy()` /
`prefetchAll()`) is the right priority over storage-layer refactors:
the engine already lazy-defers the work. M1 just adds the splash-
screen progress UI for it.

### 4. Engine boilerplate is a constant 1.02 MB

Every scenario pays the same +1.02 MB on `engine.constructed`. That
is the autoload + system registration + ECS world + null backends +
event dispatcher + persistent-state machinery. Splitting that bill:

  - Could be reduced by **lazy backend autoload** (only load the GL
    extension stubs when a non-Null backend is requested), but the
    1 MB is shared across all scenarios so the saving is single-shot
    per process, not per-scene.
  - **Not worth optimising** until a profile-guided run shows what
    inside the 1 MB is dominant. Could be ECS scheduler tables,
    could be event-bus listener arrays, could be Reflection cache
    for `#[Component]` discovery.

### 5. GPU memory is invisible to this report

`memory_get_usage()` only reports PHP-allocated bytes. Texture
uploads, FBOs, instance buffers, shader programs — all GPU-resident,
**none of them appear here**. The scenario benchmarks deliberately
use the `null` 3D backend so they're comparable run-to-run, but that
also means we're profiling a path that does no real GPU work.

For real GPU memory readings the platform tools are mandatory:

  - **macOS**: Xcode → Open Developer Tool → Metal System Trace,
    or `Profiler` instrument's "GPU Counters" + "Allocations"
    track.
  - **Linux**: `nvidia-smi` for NVIDIA, `radeontop` for AMD,
    `intel_gpu_top` for Intel iGPUs. Per-process VRAM breakdown via
    `nvtop`.
  - **Windows**: Task Manager's GPU tab (per-process committed
    bytes), or PIX for Windows for frame-level captures.

## Optimisation candidates, ranked by ROI

| Rank | Idea | Estimated PHP saving | Effort | Notes |
|---|---|---|---|---|
| 1 | **ECS SoA component storage** | up to 50% per entity (~1 KB) at large entity counts | Substantial — ECS internals refactor | Justified for 10k+ entity worlds. Measure first. |
| 2 | **Component pooling** for high-churn types (Bullet, Pickup, Particle) | Tiny per-frame, big GC pressure relief | Medium — per-component-type opt-in | Already done for ParticleEmitter (inline storage). Apply pattern to gameplay components. |
| 3 | **Lazy backend autoload** | ~200-400 KB on engine startup | Small — `EngineConfig` switches the use statement to a factory | One-shot saving; not worth chasing alone but trivial in a refactor pass. |
| 4 | **Engine.boilerplate audit** | unknown — needs Excimer pass | Small to medium | Run `PHPOLYGON_EXCIMER=1` against `EmptyScene` and pick the top 3 allocators. |
| 5 | **MeshData → SplFixedArray** | 15.8% per resident mesh (measured) | Substantial — 30+ consumer call sites | **Currently rejected.** Would need a 100+ MB resident mesh footprint to be worthwhile, which no scenario reaches. Revisit if a profile shows MeshRegistry is actually a top-3 allocator. |

## What this report should change in the dev culture

- **"Memory is tight" needs a number now.** This profile becomes the
  baseline; every "we should optimise X" claim should be cross-
  referenced against it.
- **The frame loop is allocation-clean.** Future code reviews should
  jealously protect this — any per-frame `new Mat4(...)` or array
  literal is a regression worth flagging.
- **Per-entity cost is the next frontier.** When the engine grows
  past 1000-entity scenes, a focused ECS-storage refactor is the
  right next step, not a piecemeal "make this one buffer compact"
  change.
- **The benchmark stays.** `php benchmarks/memory.php all` runs in
  ~1 s and is now part of the toolset. Add it to CI when a memory
  regression PR shows up.

## How to reproduce

```sh
php benchmarks/memory.php all
# writes benchmarks/memory-results/memory-<sha>.json
```

Single-scenario run for iteration:

```sh
php benchmarks/memory.php boxes-1000
```

The report data lives next to this file as `memory-<sha>.json`; `git
log -- benchmarks/memory-results/` keeps a history of how the heap
moves over time.
