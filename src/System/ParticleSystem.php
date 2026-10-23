<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\ParticleEmitter;
use PHPolygon\Component\ParticleSimulation;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Frustum;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;

/**
 * Drives every {@see ParticleEmitter} in the world, on the best of three tiers
 * the context supports:
 *
 *   1. GPU simulation — a vio context (php-vio >=
 *      {@see GpuParticleBaker::MIN_VIO_VERSION}) with compute, vertex storage
 *      and indirect draws, an indexed particle mesh, emitter simulation Auto.
 *      Every emitter dispatches the same kernels, recorded into the frame.
 *      update() only spawns: the rows and the elapsed time go into the
 *      emitter's {@see GpuParticleLedger}. render() writes the new rows into
 *      the GPU slot ring ({@see GpuParticleBaker::inject()}), integrates,
 *      billboards and compacts every slot in one compute pass
 *      ({@see GpuParticleBaker::stepIndirect()}) and emits one indirect draw
 *      whose instance count the GPU wrote. Only freshly spawned rows cross the
 *      bus. Any GPU failure hands the emitter to the CPU for good; its live GPU
 *      particles are dropped.
 *   2. CPU simulation, GPU billboards — vertex storage without indirect draws:
 *      {@see GpuParticleBaker::tryBillboardStep()} builds the matrices.
 *   3. CPU simulation, CPU billboards — the canonical path below.
 *
 * All tiers spawn through {@see spawnRows()}, so they consume `mt_rand()` in
 * the same order and the same seed yields the same particles.
 *
 * CPU path per frame:
 *   1. Integrates position and age. Storage is nested arrays - PHP's
 *      fastest path at this size class according to
 *      benchmarks/micro/System/ParticleStorageBench.php.
 *   2. Spawns new particles up to the emitter rate (with the spawn
 *      accumulator carrying fractional ticks).
 *   3. Builds a flat float[N*16] instance-matrix buffer and emits a
 *      single DrawMeshInstanced::flat() per emitter. The flat buffer
 *      is the source of the measured 4.5x render speed-up - the
 *      Mat4 / Quaternion / Vec3 allocations the previous Mat4-mode
 *      implementation paid per particle were the dominant cost, not
 *      the storage layout.
 *
 * The orientation of each particle ({@see ParticleEmitter::$renderMode}:
 * billboard, velocity-stretched streak, world-aligned) is computed by
 * {@see ParticleOrientation::write()} (no Quaternion intermediate) straight
 * into the same flat buffer; the GPU kernels port the same math.
 *
 * Spawning: `$velocity` + per-axis `$velocityJitter`, plus optional radial
 * shapes (`$radialSpeed`, `$radialSpokes`, `$spawnRadius`, `$spawnRadiusJitter` – one more
 * `mt_rand()` for the azimuth, one for the radius jitter, only when used).
 * An emitter with `$activeDistance` spawns only while the camera of the last
 * rendered frame is within that distance; its live particles still run out.
 *
 * Off screen: an emitter that has stood still for a particle lifetime and whose
 * whole reach (see reach()) lies outside the camera frustum costs no GPU work.
 * Its GPU simulation pauses - update() keeps spawning (same `mt_rand()` order),
 * the rows and the time wait in the ledger - and the first frame back in view
 * integrates the whole pause in one step that reproduces steps of the last
 * regular frame ({@see GpuParticleLedger::takePending()}, kernel `hstep`), so
 * the particles stand where frame-by-frame stepping would have left them. A
 * CPU emitter keeps simulating and only skips its draw, unless its particles
 * are opaque enough to cast a shadow into the view.
 *
 * Per-instance colour interpolation is **not** supported - the renderer
 * would need a parallel colour stream attribute. The per-emitter
 * material colour drives the visible tint.
 */
class ParticleSystem extends AbstractSystem
{
    /** @var \WeakMap<ParticleEmitter, GpuParticleState> GPU simulations by emitter (tier 1) */
    private \WeakMap $gpuStates;

    /** @var \WeakMap<ParticleEmitter, true> emitters whose GPU simulation failed — CPU from then on */
    private \WeakMap $gpuFailed;

    private ?bool $indirectDraw = null;

    /** Camera of the last rendered frame — the reference of `activeDistance`. */
    private ?Vec3 $lastCamera = null;

    /** Simulated seconds (sum of update() steps) — the clock of {@see $stillSince}. */
    private float $clock = 0.0;

    /** @var \WeakMap<ParticleEmitter, array{0: float, 1: float, 2: float, 3: float}> emitter origin and the clock since it stands there */
    private \WeakMap $stillSince;

    /** @var array<string, array{0: int, 1: float}> meshId => [registry version, radius] */
    private array $meshRadii = [];

    /**
     * @param \VioContext|null $ctx The renderer's live vio context. Enables the
     *        GPU tiers where the backend supports them; null (headless, non-vio)
     *        keeps the canonical CPU path.
     * @param bool $gpuSimulation False keeps every emitter's simulation on the
     *        CPU (tiers 2 and 3) even where tier 1 is available.
     */
    public function __construct(
        private readonly RenderCommandList $commandList,
        private readonly ?\VioContext $ctx = null,
        private readonly bool $gpuSimulation = true,
    ) {
        $this->gpuStates = new \WeakMap();
        $this->gpuFailed = new \WeakMap();
        $this->stillSince = new \WeakMap();
    }

    public function update(World $world, float $dt): void
    {
        if ($dt <= 0.0) return;
        $this->clock += $dt;

        foreach ($world->query(ParticleEmitter::class, Transform3D::class) as $entity) {
            $emitter = $entity->get(ParticleEmitter::class);
            $tx      = $entity->get(Transform3D::class);
            $origin  = $tx->getWorldPosition();
            $active  = $this->isActive($emitter, $origin);

            $state = $this->gpuState($emitter);
            if ($state !== null) {
                $ledger = $state->ledger;
                $ledger->advance($dt);
                $rows = $active ? $this->spawnRows($emitter, $origin, $dt, $ledger->count()) : [];
                if ($rows !== []) {
                    $ledger->queue($rows, $emitter->lifetime);
                }
                continue;
            }

            $this->integrate($emitter, $dt);
            if (!$active) {
                continue;
            }
            foreach ($this->spawnRows($emitter, $origin, $dt, count($emitter->particles)) as $row) {
                $emitter->particles[] = $row;
            }
        }
    }

    public function render(World $world): void
    {
        $camera = $this->commandList->lastOfType(SetCamera::class);
        $cameraPos = $camera?->viewMatrix->inverse()->getTranslation();
        if ($cameraPos !== null) {
            $this->lastCamera = $cameraPos;
        }

        // Off-screen emitters (see offscreen()): GPU simulations pause, CPU
        // emitters whose particles cast no shadow skip building their draw.
        /** @var \WeakMap<ParticleEmitter, true> $offscreen */
        $offscreen = new \WeakMap();
        if ($camera !== null && getenv('PHPOLYGON_NO_CULL') !== '1') {
            $planes = Frustum::planes($camera->projectionMatrix, $camera->viewMatrix);
            foreach ($world->query(ParticleEmitter::class, Transform3D::class) as $entity) {
                $emitter = $entity->get(ParticleEmitter::class);
                if ($this->offscreen($emitter, $entity->get(Transform3D::class)->getWorldPosition(), $planes)) {
                    $offscreen[$emitter] = true;
                }
            }
        }

        $spawnFailed = $this->ctx !== null ? $this->injectPendingSpawns($world, $this->ctx, $offscreen) : null;

        foreach ($world->query(ParticleEmitter::class) as $entity) {
            $emitter = $entity->get(ParticleEmitter::class);

            $state = $this->gpuStates[$emitter] ?? null;
            if ($state !== null && $this->ctx !== null) {
                if (!$state->ledger->isCurrent($emitter->maxParticles, $emitter->generation)) {
                    // Cleared or re-capped since the last update: the ring is
                    // stale and the next update replaces it. Nothing to draw.
                    continue;
                }
                if (isset($offscreen[$emitter])) {
                    // Paused: spawned rows and simulated time pile up in the
                    // ledger and are caught up when the emitter is back in view.
                    // A storage-buffer draw casts no shadow, so nothing visible
                    // depends on this frame's step.
                    $state->paused = true;
                    continue;
                }
                if (!isset($spawnFailed[$emitter]) && $this->renderGpu($this->ctx, $state, $emitter, $cameraPos)) {
                    continue;
                }
                $this->abandonGpu($emitter);
            }

            $count = count($emitter->particles);
            if ($count === 0) continue;
            if (isset($offscreen[$emitter]) && !$this->castsShadow($emitter)) {
                // CPU simulation runs in update(); only the draw is skipped.
                continue;
            }

            // Readback-free path (Path B): offload the per-particle billboard
            // build to the GPU and bind the result straight as the instance
            // source — no float[N*16] built in PHP, no readback. Falls through
            // to the CPU loop below when unavailable (headless, non-vio, older
            // vio, or any GPU error → tryBillboardStep returns null).
            if ($this->ctx !== null) {
                $outBuf = GpuParticleBaker::tryBillboardStep($this->ctx, $emitter, $cameraPos);
                if ($outBuf !== null) {
                    $this->commandList->add(DrawMeshInstanced::fromStorageBuffer(
                        meshId: $emitter->meshId,
                        materialId: $emitter->materialId,
                        storageBuffer: $outBuf,
                        instanceCount: $count,
                    ));
                    continue;
                }
            }

            // Pre-size the instance buffer once via array_fill so PHPStan
            // can infer the array<int, float> shape and the underlying
            // PHP HashTable gets pre-allocated. Single allocation per
            // emitter per frame, no per-particle Mat4 objects.
            /** @var array<int, float> $matrices */
            $matrices = array_fill(0, $count * 16, 0.0);

            $startSize = $emitter->startSize;
            $endSize   = $emitter->endSize;
            $mode      = $emitter->renderMode->code();
            $stretch   = $emitter->stretch;
            $hasCam = $cameraPos !== null;
            $cx = $hasCam ? $cameraPos->x : 0.0;
            $cy = $hasCam ? $cameraPos->y : 0.0;
            $cz = $hasCam ? $cameraPos->z : 0.0;

            $i = 0;
            foreach ($emitter->particles as $p) {
                $life = $p[7] > 1e-4 ? $p[7] : 1e-4;
                $t = $p[6] / $life;
                $size = $startSize + ($endSize - $startSize) * $t;

                ParticleOrientation::write(
                    $matrices,
                    $i * 16,
                    $mode,
                    $p[0], $p[1], $p[2],
                    $p[3], $p[4], $p[5],
                    $size,
                    $stretch,
                    $cx, $cy, $cz,
                    $hasCam,
                );
                $i++;
            }

            $this->commandList->add(DrawMeshInstanced::flat(
                meshId: $emitter->meshId,
                materialId: $emitter->materialId,
                flatMatrices: $matrices,
                instanceCount: $count,
            ));
        }
    }

    /**
     * Tier 1 render: write the rows spawned since the last render into the GPU
     * ring, advance the simulation over the time elapsed since then and emit
     * the indirect draw. False on any GPU failure — the caller then moves the
     * emitter to the CPU.
     */
    protected function renderGpu(\VioContext $ctx, GpuParticleState $state, ParticleEmitter $emitter, ?Vec3 $cameraPos): bool
    {
        $args = $state->argsBuf;
        if ($args === null) {
            return false;
        }
        $ledger = $state->ledger;
        // Back from an off-screen pause: one step over the whole pause that
        // reproduces steps of the last regular frame (see GpuParticleLedger::
        // takePending() and the kernel's `hstep`).
        $catchUp = $state->paused ? $state->lastStep : 0.0;
        $state->paused = false;

        $pending = $ledger->pendingCount();
        if ($pending > 0) {
            $g = $emitter->gravity;
            if (!GpuParticleBaker::inject($ctx, $state, $ledger->takePending($g->x, $g->y, $g->z, $catchUp), $pending)) {
                return false;
            }
        }

        if ($ledger->count() === 0) {
            // Every slot has been released: all particles are dead and stay
            // dead without stepping. Nothing to draw.
            $ledger->takeDt();
            return true;
        }

        $dt = $ledger->takeDt();
        if (!GpuParticleBaker::stepIndirect($ctx, $state, $emitter, $dt, $cameraPos, $catchUp)) {
            return false;
        }
        if (!($catchUp > 0.0 && $catchUp < $dt) && $dt > 0.0) {
            $state->lastStep = $dt;
        }

        $this->commandList->add(DrawMeshInstanced::fromStorageBuffer(
            meshId: $emitter->meshId,
            materialId: $emitter->materialId,
            storageBuffer: $state->outBuf,
            instanceCount: $ledger->count(),
            indirectArgs: $args,
        ));
        return true;
    }

    /**
     * Write the rows every tier-1 emitter spawned since the last render into
     * its GPU ring with ONE buffer upload for all of them
     * ({@see GpuParticleBaker::injectAll()}) - before {@see renderGpu()} steps
     * them, which then finds nothing pending. One upload per emitter and frame
     * was the dominant cost of a few live GPU emitters.
     *
     * Paused (off-screen) emitters keep their rows for the catch-up.
     *
     * @param \WeakMap<ParticleEmitter, true> $offscreen
     * @return \WeakMap<ParticleEmitter, true> emitters whose spawn upload failed
     */
    private function injectPendingSpawns(World $world, \VioContext $ctx, \WeakMap $offscreen): \WeakMap
    {
        /** @var \WeakMap<ParticleEmitter, true> $failed */
        $failed = new \WeakMap();
        $batches = [];
        $owners = [];
        foreach ($world->query(ParticleEmitter::class) as $entity) {
            $emitter = $entity->get(ParticleEmitter::class);
            $state = $this->gpuStates[$emitter] ?? null;
            if ($state === null
                || $state->argsBuf === null
                || isset($offscreen[$emitter])
                || !$state->ledger->isCurrent($emitter->maxParticles, $emitter->generation)
            ) {
                continue;
            }
            $pending = $state->ledger->pendingCount();
            if ($pending === 0) {
                continue;
            }
            $g = $emitter->gravity;
            // Same catch-up step renderGpu() integrates with (it clears $paused).
            $catchUp = $state->paused ? $state->lastStep : 0.0;
            $batches[] = [$state, $state->ledger->takePending($g->x, $g->y, $g->z, $catchUp), $pending];
            $owners[] = $emitter;
        }
        if ($batches === []) {
            return $failed;
        }
        foreach (GpuParticleBaker::injectAll($ctx, $batches) as $i => $ok) {
            if (!$ok) {
                $failed[$owners[$i]] = true;
            }
        }
        return $failed;
    }

    /**
     * The GPU simulation of an emitter that runs on tier 1 — created, or
     * replaced after clear() / a new cap / a new mesh — or null when the
     * emitter is simulated on the CPU.
     */
    private function gpuState(ParticleEmitter $emitter): ?GpuParticleState
    {
        $state = $this->gpuStates[$emitter] ?? null;
        $ctx = $this->ctx;
        $indexCount = $this->gpuIndexCount($emitter);
        if ($ctx === null || $indexCount === 0) {
            if ($state !== null) {
                // Opted out (or the mesh lost its indices): the live GPU
                // particles are not carried over to the CPU.
                unset($this->gpuStates[$emitter]);
                $emitter->gpuLedger = null;
            }
            return null;
        }
        if ($state !== null
            && $state->indexCount === $indexCount
            && $state->ledger->isCurrent($emitter->maxParticles, $emitter->generation)
        ) {
            return $state;
        }

        // Rows the CPU simulated so far seed the ring (always none when the
        // emitter already ran on the GPU), so moving to the GPU keeps them.
        $created = GpuParticleBaker::createState(
            $ctx,
            array_values($emitter->particles),
            $emitter->maxParticles,
            $indexCount,
            $emitter->generation,
        );
        if ($created === null || $created->argsBuf === null) {
            $this->abandonGpu($emitter);
            return null;
        }
        $this->gpuStates[$emitter] = $created;
        $emitter->particles = [];
        $emitter->gpuLedger = $created->ledger;
        return $created;
    }

    /** Index count of the emitter's mesh when the emitter qualifies for tier 1, else 0. */
    private function gpuIndexCount(ParticleEmitter $emitter): int
    {
        if ($this->ctx === null
            || !$this->gpuSimulation
            || $emitter->simulation !== ParticleSimulation::Auto
            || $emitter->maxParticles <= 0
            || isset($this->gpuFailed[$emitter])
        ) {
            return 0;
        }
        $this->indirectDraw ??= GpuParticleBaker::isIndirectDraw($this->ctx);
        if (!$this->indirectDraw) {
            return 0;
        }
        $mesh = MeshRegistry::get($emitter->meshId);
        return $mesh === null ? 0 : count($mesh->indices);
    }

    private function abandonGpu(ParticleEmitter $emitter): void
    {
        unset($this->gpuStates[$emitter]);
        $this->gpuFailed[$emitter] = true;
        $emitter->gpuLedger = null;
    }

    /**
     * True when nothing the emitter has emitted can be on screen: the sphere
     * every one of its particles stays inside ({@see reach()}) lies outside the
     * view frustum of the frame's camera (the last SetCamera; ParticleSystem
     * MUST render after the camera system that publishes it).
     *
     * The sphere is centred on the emitter, so it only covers particles spawned
     * where the emitter stands now: an emitter counts as off screen only after
     * it has stood still for a full particle lifetime (simulated time).
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $planes
     */
    private function offscreen(ParticleEmitter $emitter, Vec3 $origin, array $planes): bool
    {
        $still = $this->stillSince[$emitter] ?? null;
        if ($still === null || $still[0] !== $origin->x || $still[1] !== $origin->y || $still[2] !== $origin->z) {
            $this->stillSince[$emitter] = [$origin->x, $origin->y, $origin->z, $this->clock];
            return false;
        }
        if ($this->clock - $still[3] < $emitter->lifetime) {
            return false;
        }
        return Frustum::sphereOutside($planes, $origin->x, $origin->y, $origin->z, $this->reach($emitter));
    }

    /**
     * Radius around the emitter that contains every particle it can have alive,
     * mesh extent included: spawn offset + the farthest a particle travels in
     * its lifetime (fastest spawn speed, gravity) + the largest particle (size,
     * stretched trail). Generous on purpose - a too small sphere would pause
     * particles that are on screen.
     */
    private function reach(ParticleEmitter $emitter): float
    {
        $life = max(0.0, $emitter->lifetime);
        $v = $emitter->velocity;
        $j = $emitter->velocityJitter;
        $g = $emitter->gravity;
        $speed = sqrt($v->x * $v->x + $v->y * $v->y + $v->z * $v->z)
            + sqrt($j->x * $j->x + $j->y * $j->y + $j->z * $j->z)
            + abs($emitter->radialSpeed);
        $gravity = sqrt($g->x * $g->x + $g->y * $g->y + $g->z * $g->z);
        // Semi-implicit Euler overshoots the exact parabola by ½·g·t·h; one
        // extra second of h covers any frame step.
        $travel = $speed * $life + $gravity * $life * ($life + 1.0);
        $spawn = abs($emitter->spawnRadius) + abs($emitter->spawnRadiusJitter);
        $size = max(abs($emitter->startSize), abs($emitter->endSize))
            + ($speed + $gravity * $life) * abs($emitter->stretch);

        return ($spawn + $travel + $size * $this->meshRadius($emitter->meshId)) * 1.25 + 1.0;
    }

    /** Largest vertex distance from the mesh origin (1 when unknown). */
    private function meshRadius(string $meshId): float
    {
        $version = MeshRegistry::version($meshId);
        $cached = $this->meshRadii[$meshId] ?? null;
        if ($cached !== null && $cached[0] === $version) {
            return $cached[1];
        }
        $mesh = MeshRegistry::get($meshId);
        if ($mesh === null) {
            return 1.0;
        }
        $r2 = 0.0;
        $verts = $mesh->vertices;
        for ($i = 0, $n = count($verts) - 2; $i < $n; $i += 3) {
            $d = $verts[$i] * $verts[$i] + $verts[$i + 1] * $verts[$i + 1] + $verts[$i + 2] * $verts[$i + 2];
            if ($d > $r2) {
                $r2 = $d;
            }
        }
        $radius = max(sqrt($r2), 1e-3);
        $this->meshRadii[$meshId] = [MeshRegistry::version($meshId), $radius];
        return $radius;
    }

    /** Whether the emitter's particles go into the shadow pass (opaque enough). */
    private function castsShadow(ParticleEmitter $emitter): bool
    {
        $material = MaterialRegistry::get($emitter->materialId);
        // The renderers' shadow collection skips materials below this alpha.
        return $material === null || $material->alpha >= 0.9;
    }

    /**
     * False while the camera of the last rendered frame is further than the
     * emitter's `activeDistance` from it — then nothing new is spawned.
     */
    private function isActive(ParticleEmitter $emitter, Vec3 $origin): bool
    {
        $limit = $emitter->activeDistance;
        $cam = $this->lastCamera;
        if ($limit <= 0.0 || $cam === null) {
            return true;
        }
        $dx = $cam->x - $origin->x;
        $dy = $cam->y - $origin->y;
        $dz = $cam->z - $origin->z;
        return $dx * $dx + $dy * $dy + $dz * $dz <= $limit * $limit;
    }

    /**
     * Nested-array integrate. Walks the live particle list and rebuilds
     * it skipping anyone whose age has exceeded their lifetime.
     *
     * Why nested rather than SoA: PHP's nested array path is measurably
     * faster at this size class - parallel float arrays cost 8 hash-
     * table lookups per particle, and stride-8 single arrays add index
     * math without saving any. Confirmed in benchmarks/micro/System.
     */
    private function integrate(ParticleEmitter $emitter, float $dt): void
    {
        $gx = $emitter->gravity->x;
        $gy = $emitter->gravity->y;
        $gz = $emitter->gravity->z;
        $alive = [];
        foreach ($emitter->particles as $p) {
            $newAge = $p[6] + $dt;
            if ($newAge >= $p[7]) continue;
            $vx = $p[3] + $gx * $dt;
            $vy = $p[4] + $gy * $dt;
            $vz = $p[5] + $gz * $dt;
            $alive[] = [
                $p[0] + $vx * $dt, $p[1] + $vy * $dt, $p[2] + $vz * $dt,
                $vx, $vy, $vz,
                $newAge, $p[7],
            ];
        }
        $emitter->particles = $alive;
    }

    /**
     * Advance the spawn accumulator and build this step's new particle rows
     * (age 0), capped so the live count never exceeds maxParticles. Shared by
     * every tier: the `mt_rand()` draws happen here and only here, three per
     * row in x, y, z order, so a seed reproduces the same rows on any tier.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}>
     */
    private function spawnRows(ParticleEmitter $emitter, Vec3 $position, float $dt, int $liveCount): array
    {
        $emitter->spawnAccumulator += $emitter->rate * $dt;
        $toSpawn = (int) floor($emitter->spawnAccumulator);
        if ($toSpawn <= 0) return [];
        $emitter->spawnAccumulator -= $toSpawn;

        $room = $emitter->maxParticles - $liveCount;
        $toSpawn = min($toSpawn, max(0, $room));
        if ($toSpawn === 0) return [];

        $jx = $emitter->velocityJitter->x;
        $jy = $emitter->velocityJitter->y;
        $jz = $emitter->velocityJitter->z;
        $vbx = $emitter->velocity->x;
        $vby = $emitter->velocity->y;
        $vbz = $emitter->velocity->z;
        $life = $emitter->lifetime;
        $rngMax = mt_getrandmax();
        $radialSpeed = $emitter->radialSpeed;
        $spawnRadius = $emitter->spawnRadius;
        $radiusJitter = $emitter->spawnRadiusJitter;
        $spokes = $emitter->radialSpokes;
        $radial = $radialSpeed != 0.0 || $spawnRadius != 0.0 || $radiusJitter != 0.0;

        $rows = [];
        for ($i = 0; $i < $toSpawn; $i++) {
            $row = [
                $position->x, $position->y, $position->z,
                $vbx + (mt_rand() / $rngMax - 0.5) * 2.0 * $jx,
                $vby + (mt_rand() / $rngMax - 0.5) * 2.0 * $jy,
                $vbz + (mt_rand() / $rngMax - 0.5) * 2.0 * $jz,
                0.0, $life,
            ];
            if ($radial) {
                // Radial shapes draw after x, y, z: the azimuth, then (with a
                // jitter) the radius — emitters without them keep the RNG order.
                $u = mt_rand() / $rngMax;
                // Spokes: the same draw picks one of N evenly spaced directions.
                $theta = $spokes > 0 ? floor($u * $spokes) * 2.0 * M_PI / $spokes : $u * 2.0 * M_PI;
                $c = cos($theta);
                $s = sin($theta);
                $r = $spawnRadius;
                if ($radiusJitter != 0.0) {
                    $r += (mt_rand() / $rngMax - 0.5) * 2.0 * $radiusJitter;
                }
                $row[0] += $c * $r;
                $row[2] += $s * $r;
                $row[3] += $c * $radialSpeed;
                $row[5] += $s * $radialSpeed;
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
