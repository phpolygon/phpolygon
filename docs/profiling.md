# Performance profiling

PHPolygon ships with a layered profiling setup tailored for game-engine workloads:

| Tool       | Use case                                   | Overhead | UI                          |
|------------|--------------------------------------------|----------|-----------------------------|
| **SPX**    | Deep-dive flamegraphs during a dev session | 10-30%   | Built-in web UI             |
| **Excimer**| CI regression checks, long benchmark runs  | < 1%     | Speedscope (drag JSON in)   |
| **PHPBench** | Micro-benchmarks (Mat4, Quaternion etc.) | n/a      | Console / HTML reports      |
| **Custom benchmark runner** | Frame-loop scenarios        | n/a      | JSON in `benchmarks/results/` |

For most engine work the order is: SPX to find a hot spot, Custom runner / PHPBench to lock a regression test in place, Excimer in CI to catch silent regressions later.

## One-time setup

```bash
./scripts/install-profilers.sh           # both
./scripts/install-profilers.sh spx       # only SPX
./scripts/install-profilers.sh excimer   # only Excimer

php -m | grep -E 'spx|excimer'           # verify
composer install                         # pulls phpbench/phpbench
```

PHPBench installs via Composer. SPX and Excimer are PHP C-extensions and cannot be installed via Composer.

## SPX - flamegraphs

Activate per run with env vars:

```bash
SPX_ENABLED=1 SPX_REPORT=full php examples/vio_3d_scene.php
```

This dumps a report into `~/.local/share/spx/` (or wherever SPX is configured to write). To browse interactively:

```bash
SPX_UI_URI=/_spx php -S 127.0.0.1:8080 examples/vio_3d_scene.php
# then open http://127.0.0.1:8080/?SPX_KEY=phpolygon-dev&SPX_UI_LIST=1
```

Useful env vars:

| Variable                 | Purpose                                      |
|--------------------------|----------------------------------------------|
| `SPX_ENABLED=1`          | Activate profiler                            |
| `SPX_REPORT=full`        | Full call tree (vs. flat)                    |
| `SPX_SAMPLING_PERIOD_US` | Sample period in microseconds (default 100)  |
| `SPX_BUILTINS=1`         | Include internal functions in trace          |

### Reading a flamegraph

The X axis is time spent. The Y axis is the call stack. Wide stacks at the bottom are hot paths. Look for:

- Single PHP functions taking a disproportionate share of frame time
- Tight loops calling Mat4 / Quaternion ops repeatedly
- `array_*` calls inside System updates - often a sign that ECS queries should be cached

## Excimer - low-overhead sampling

Excimer is statistical, runs in production without measurable overhead, and writes a Speedscope-compatible log. The engine wires Excimer through `PerfProfiler::startExcimer()` / `stopExcimer()`. A bench scenario:

```bash
PHPOLYGON_EXCIMER=1 php benchmarks/run.php php-district
# writes benchmarks/results/<sha>.speedscope.json
```

Drop the JSON into <https://www.speedscope.app> to inspect.

## PerfProfiler - instrumented sections

`src/Runtime/PerfProfiler.php` provides begin/end markers used throughout the engine:

```php
use PHPolygon\Runtime\PerfProfiler;

PerfProfiler::section('mesh.generate.box', fn() => BoxMesh::generate(1, 1, 1));

PerfProfiler::begin('render3d.flush');
$this->renderer3d->endFrame();
PerfProfiler::end();
```

When no profiler is active, `begin()`/`end()` collapse to a single bool check. Game code should use these around cross-system work it expects to be hot. Inside tight inner loops (e.g. per-vertex), do **not** instrument - the marker overhead distorts the measurement.

Built-in section names:

| Name                          | Where                                       |
|-------------------------------|---------------------------------------------|
| `engine.update`               | per-update tick (may fire N times per frame at fixed dt) |
| `engine.render`               | per-frame render body                       |
| `ecs.update`                  | `World::update()`                           |
| `ecs.system.<ClassShortName>` | per-System loop in `World::update()`        |
| `render3d.build_commands`     | `Renderer3DSystem::render()`                |
| `render3d.flush`              | Backend `endFrame()`                        |
| `render2d.frame`              | 2D renderer frame block                     |
| `mesh.generate.<id>`          | Procedural mesh generators                  |
| `texture.upload`              | `TextureManager::load()`                    |
| `physics.tick`                | `Physics3DSystem::update()`                 |

## GC pause tracking

Heavy GC is a common 3D-game stutter source. `PerfProfiler::gcDelta()` returns runs and collected counts since the previous call - intended to be invoked once per frame. The benchmark runner aggregates this and includes a per-scenario GC histogram in its output.

## Benchmarks

```bash
php benchmarks/run.php empty-scene             # baseline (engine overhead only)
php benchmarks/run.php boxes-1000              # 1000 DrawMesh
php benchmarks/run.php boxes-1000-instanced    # same count, DrawMeshInstanced
php benchmarks/run.php php-district            # composite scene
php benchmarks/run.php mesh-gen-stress         # 100 BuildingMesh::generate
php benchmarks/run.php physics-stack           # 100 RigidBody3D in free fall

php benchmarks/run.php php-district --compare HEAD~1
```

Results are JSON files under `benchmarks/results/<git-sha>.json` and contain p50/p95/p99 frame times per `PerfProfiler` section, plus the GC histogram.

Baselines that the CI compares against live in `benchmarks/baselines/`. Update them deliberately:

```bash
php benchmarks/run.php php-district --accept    # writes baseline
```

PHPBench drives micro-benchmarks for hot leaf functions (Mat4, Quaternion, MeshData):

```bash
vendor/bin/phpbench run benchmarks/micro --report=aggregate
```

## CI

`.github/workflows/perf-bench.yml` runs all six scenarios twice on the same runner — once on the PR HEAD and once on `main` — and diffs them via `bin/phpolygon perf:report`. A regression > 15% on any frame-time metric fails the job (exit code 2 from `perf:report`). The CI does NOT use `benchmarks/baselines/` as the reference because runner hardware differs from local hardware; the dual-run approach gives stable relative deltas on identical hardware.

Trigger: any PR that touches `src/`, `benchmarks/`, `composer.{json,lock}`, or the workflow itself. Result JSON for both runs is uploaded as the `perf-results` artifact for inspection.

## Optimization loop

A repeatable workflow when you suspect or observe a performance issue:

1. **Reproduce locally.** Run the relevant scenario a few times to confirm the regression is real, not measurement noise:
   `php benchmarks/run.php <scenario> --warmup 60 --frames 600`
2. **Find the hot spot.** Re-run with SPX to get a flamegraph:
   `SPX_ENABLED=1 SPX_REPORT=full php benchmarks/run.php <scenario>`
   Look for unexpectedly wide stacks. Most engine wins come from reducing array allocation in tight loops, caching matrix work, or short-circuiting Renderer3DSystem culling.
3. **Snapshot the current state.**
   `php benchmarks/run.php <scenario> --warmup 60 --frames 600 --accept` writes a baseline you can diff against.
4. **Implement the fix on a branch.** Keep changes focused — one optimization at a time so the diff has a clean story.
5. **Diff before / after.**
   `php benchmarks/run.php <scenario>` then `php bin/phpolygon perf:report <scenario> --baseline`. Aim for a clearly negative delta on p95.
6. **Update the baseline if the win is real.**
   Re-run with `--accept` to commit the new baseline alongside the code change. Both belong in the same PR so reviewers can see "fix X, p95 dropped from Y to Z".
7. **Document anything surprising.** If the win was non-obvious (a pattern that wasn't in the flamegraph at first, an interaction between systems), add a sentence to the relevant CLAUDE.md so the next person doesn't re-discover it.

## Baselines

`benchmarks/baselines/<scenario>.json` files are checked into git. They serve two purposes:

- **Local regression sanity** — `php bin/phpolygon perf:report <scenario> --baseline` compares the latest result in `benchmarks/results/` against the committed baseline. Useful when iterating on a single optimization.
- **Documentation of expected performance** — anyone looking at the repo can see what numbers the engine should hit on the maintainer's reference hardware.

Baselines are NOT used as the CI reference. Different hardware between local and CI runners would make absolute numbers unreliable. CI uses a dual-run approach (PR HEAD vs main) to get stable relative deltas on identical hardware.

Update local baselines deliberately when:
- A behavioural change unavoidably slows a path (e.g. correctness fix that adds a check)
- A new feature increases the floor cost (e.g. extra system added to a scenario's setUp)
- An optimization wins enough that the old baseline is now noise

Do NOT update baselines when:
- You don't understand why the number changed
- The change is within natural run-to-run variance (re-run a few times first)
- A regression slipped in unintentionally — fix it instead

## Anti-patterns

- **Profiler enabled in production** - SPX and Excimer are dev-only. Never ship a build with them on.
- **Markers in tight inner loops** - per-vertex / per-pixel instrumentation distorts the measurement and pollutes flamegraphs.
- **Benchmarks without warm-up frames** - the first 30-60 frames are always slower (JIT, class loading, texture upload). The runner discards them.
- **Benchmarks with unseeded random** - procedural worlds need fixed seeds for reproducible numbers.
- **Profiling on battery / under thermal throttle** - macOS in Low Power mode produces meaningless numbers. Pin to High Performance.
- **Comparing instanced vs non-instanced runs** with different mesh counts - apples to oranges.
