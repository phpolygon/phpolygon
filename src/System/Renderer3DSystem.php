<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\AmbientLight;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\SpotLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Wind;
use PHPolygon\Component\Weather;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\AddSpotLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetGroundWetness;
use PHPolygon\Rendering\Command\SetSnowCover;
use PHPolygon\Rendering\Command\SetWaveAnimation;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\Renderer3DInterface;
use PHPolygon\Runtime\PerfProfiler;

/**
 * Default 3D renderer system.
 *
 * Collects lights, wave / snow / wetness state and emits {@see DrawMesh}
 * commands for every visible {@see MeshRenderer}. Performs CPU-side
 * frustum culling using the most recent {@see SetCamera} pushed by
 * {@see Camera3DSystem}: a sphere-vs-frustum test rejects entities
 * entirely outside the view volume so the GPU never sees them.
 *
 * Bounding spheres are computed once per mesh id from the raw vertex
 * array and cached in {@see $sphereCache}. The radius is multiplied by
 * the largest absolute scale axis so any rotation of a non-uniformly
 * scaled mesh remains inside the sphere — slight over-rendering, but no
 * false negatives.
 */
class Renderer3DSystem extends AbstractSystem
{
    /**
     * Hard cap on the number of PointLight commands emitted per frame.
     * Backend shaders only honour the first 4-8 anyway, so trimming here
     * avoids wasted command-list allocations and keeps the closest lights.
     */
    public const MAX_POINT_LIGHTS = 8;

    /**
     * Hard cap on the number of SpotLight commands emitted per frame.
     * Mirrors {@see MAX_POINT_LIGHTS}: backend shaders only honour the first
     * few, so trimming to the nearest spots keeps the closest beams.
     */
    public const MAX_SPOT_LIGHTS = 8;

    /** @var array<string, array{cx:float, cy:float, cz:float, radius:float}> */
    private static array $sphereCache = [];

    /**
     * Spatial bins keyed by "x,z" cell index in BIN_SIZE units. Each bin
     * holds the AABB of all entities whose translation falls inside it.
     * Rebuilt every BIN_REBUILD_INTERVAL frames so animated entities and
     * newly-spawned ones get picked up; cheap (one extra full iteration).
     *
     * @var array<string, array{0:float, 1:float, 2:float, 3:float, 4:float, 5:float}>
     */
    private array $binAabbs = [];

    /** @var array<int, string> */
    private array $entityBin = [];

    private const BIN_SIZE = 256.0;
    private const BIN_REBUILD_INTERVAL = 30;

    private int $frameCount = 0;

    private float $wavePhase = 0.0;
    private float $snowCover = 0.0;

    public function __construct(
        private readonly Renderer3DInterface $renderer,
        private readonly RenderCommandList $commandList,
    ) {}

    /**
     * Drop the spatial-bin caches on World::clear(). After clear() the entity
     * ids restart from 0, so the next createEntity() reuses the same ids;
     * without resetting $entityBin / $binAabbs the coarse pre-cull associates
     * fresh meshes with the bin of the previous occupant of that id, producing
     * ~30 frames of wrong cull decisions until the next rebuildBins() boundary.
     */
    public function onWorldClear(World $world): void
    {
        $this->binAabbs = [];
        $this->entityBin = [];
        $this->frameCount = 0;
    }

    public function update(World $world, float $dt): void
    {
        $this->wavePhase += $dt;

        // Gradual snow accumulation / melting
        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);
            if ($weather->snowIntensity > 0.1 && $weather->temperature < 2.0) {
                $this->snowCover = min(1.0, $this->snowCover + $weather->snowIntensity * 0.008 * $dt);
            } else {
                $meltRate = $weather->temperature > 5.0 ? 0.006 : 0.003;
                $this->snowCover = max(0.0, $this->snowCover - $meltRate * $dt);
            }
            break;
        }
    }

    public function render(World $world): void
    {
        // Build the draw command list (ECS iteration + frustum cull) ...
        PerfProfiler::begin('render3d.build_commands');
        try {
            $this->renderCommands($world);
        } finally {
            PerfProfiler::end();
        }
        // ... then submit it to the GPU. Splitting these two spans separates
        // the CPU command-build cost from the per-draw submit/shadow-pass cost
        // (which previously hid inside 'build_commands' and masked the real
        // hot path — the per-surviving-draw uniform uploads in VioRenderer3D).
        PerfProfiler::begin('render3d.vio_submit');
        try {
            $this->renderer->render($this->commandList);
        } finally {
            $this->commandList->clear();
            PerfProfiler::end();
        }
    }

    private function renderCommands(World $world): void
    {
        // Lights — ambient (global), then directional + point, in sync with draws.
        foreach ($world->query(AmbientLight::class) as $entity) {
            $light = $entity->get(AmbientLight::class);
            $this->commandList->add(new SetAmbientLight($light->color, $light->intensity));
        }
        foreach ($world->query(DirectionalLight::class, Transform3D::class) as $entity) {
            $light = $entity->get(DirectionalLight::class);
            $this->commandList->add(new SetDirectionalLight(
                $light->direction,
                $light->color,
                $light->intensity,
            ));
        }
        // Pick the closest MAX_POINT_LIGHTS to the active camera. Backend
        // shaders cap at 4-8 active lights and pick whichever they receive
        // first, so without sorting a faraway torch can outrank the sun
        // beacon next to the player. Distance is squared to skip the sqrt.
        $cameraPos = null;
        foreach ($world->query(Camera3DComponent::class, Transform3D::class) as $entity) {
            $cameraPos = $entity->get(Transform3D::class)->getWorldPosition();
            break;
        }

        $candidates = [];
        foreach ($world->query(PointLight::class, Transform3D::class) as $entity) {
            $light = $entity->get(PointLight::class);
            // Skip lights dimmed to (near) nothing — e.g. decorative lights
            // switched off in daylight. Keeps them from consuming a slot in the
            // per-frame point-light budget where they'd contribute no visible
            // light anyway.
            if ($light->intensity <= 0.001) {
                continue;
            }
            $pos = $entity->get(Transform3D::class)->getWorldPosition();
            if ($cameraPos !== null) {
                $dx = $pos->x - $cameraPos->x;
                $dy = $pos->y - $cameraPos->y;
                $dz = $pos->z - $cameraPos->z;
                $distSq = $dx * $dx + $dy * $dy + $dz * $dz;
                // NOTE: distance-to-camera cull temporarily REMOVED to verify it
                // was suppressing the cargo-plane interior lights. distSq is still
                // computed below to keep the closest-MAX_POINT_LIGHTS selection.
            } else {
                $distSq = 0.0;
            }
            $candidates[] = [$distSq, $pos, $light];
        }

        if (count($candidates) > self::MAX_POINT_LIGHTS) {
            usort($candidates, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            $candidates = array_slice($candidates, 0, self::MAX_POINT_LIGHTS);
        }

        foreach ($candidates as [$distSq, $pos, $light]) {
            $this->commandList->add(new AddPointLight(
                $pos,
                $light->color,
                $light->intensity,
                $light->radius,
            ));
        }

        if (\getenv('PHPOLYGON_LIGHT_DEBUG') === '1') {
            static $lightDebugFrame = 0;
            if ($lightDebugFrame++ % 60 === 0) {
                $msg = '[LIGHTS] emitted=' . count($candidates);
                foreach ($candidates as [$d, $p, $l]) {
                    $msg .= \sprintf(' (%.0f,%.0f,%.0f i=%.1f r=%.0f)', $p->x, $p->y, $p->z, $l->intensity, $l->radius);
                }
                \fwrite(\STDERR, $msg . "\n");
            }
        }

        // Spot lights — mirror the point-light pipeline exactly: skip dimmed
        // lights, cull by range against the camera, keep the closest
        // MAX_SPOT_LIGHTS. Position comes from the entity's Transform3D; the
        // beam direction comes from the SpotLight component itself.
        $spotCandidates = [];
        foreach ($world->query(SpotLight::class, Transform3D::class) as $entity) {
            $light = $entity->get(SpotLight::class);
            if ($light->intensity <= 0.001) {
                continue;
            }
            $pos = $entity->get(Transform3D::class)->getWorldPosition();
            if ($cameraPos !== null) {
                $dx = $pos->x - $cameraPos->x;
                $dy = $pos->y - $cameraPos->y;
                $dz = $pos->z - $cameraPos->z;
                $distSq = $dx * $dx + $dy * $dy + $dz * $dz;
                // Same ×4 cushion as point lights so beams stay lit just out
                // of immediate range when the player turns toward them.
                if ($distSq > $light->range * $light->range * 4.0) {
                    continue;
                }
            } else {
                $distSq = 0.0;
            }
            $spotCandidates[] = [$distSq, $pos, $light];
        }

        if (count($spotCandidates) > self::MAX_SPOT_LIGHTS) {
            usort($spotCandidates, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            $spotCandidates = array_slice($spotCandidates, 0, self::MAX_SPOT_LIGHTS);
        }

        foreach ($spotCandidates as [$distSq, $pos, $light]) {
            $this->commandList->add(new AddSpotLight(
                $pos,
                $light->direction,
                $light->color,
                $light->intensity,
                $light->range,
                $light->angle,
                $light->penumbra,
            ));
        }

        // Wave animation driven by wind + weather; ground wetness from rain.
        $windIntensity = 0.5;
        $stormIntensity = 0.0;
        $rainWetness = 0.0;
        foreach ($world->query(Wind::class) as $entity) {
            $windIntensity = $entity->get(Wind::class)->intensity;
            break;
        }
        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);
            $stormIntensity = $weather->stormIntensity;
            // Rain soaks the ground faster than it evaporates. A bit of
            // surface moisture lingers after storms so sand doesn't snap dry.
            $rainWetness = min(1.0, $weather->rainIntensity * 1.2 + $weather->stormIntensity * 0.4);
            break;
        }
        $waveAmp  = 0.1 + $windIntensity * 0.25 + $stormIntensity * 0.35;
        $waveFreq = 0.4 + $windIntensity * 0.15 + $stormIntensity * 0.15;
        $this->commandList->add(new SetSnowCover($this->snowCover));
        $this->commandList->add(new SetGroundWetness($rainWetness));
        $this->commandList->add(new SetWaveAnimation(
            enabled: true,
            amplitude: $waveAmp,
            frequency: $waveFreq,
            phase: $this->wavePhase,
        ));

        // Camera3DSystem already pushed a SetCamera with the active view +
        // projection matrices. Pull the most recent one and derive 6 planes.
        $planes = $this->extractFrustumPlanes();

        // Coarse spatial bin culling. We bin entities into a 2D X-Z grid and
        // test each bin's AABB against the frustum once per frame; entities
        // in a rejected bin skip the per-entity sphere test entirely. Bins
        // are rebuilt every BIN_REBUILD_INTERVAL frames so animation /
        // spawning still gets picked up.
        if ($planes !== null) {
            $this->frameCount++;
            if ($this->binAabbs === [] || $this->frameCount % self::BIN_REBUILD_INTERVAL === 0) {
                $this->rebuildBins($world);
            }
            $visibleBins = $this->cullBins($planes);
        } else {
            $visibleBins = null;
        }

        // Transparent draws are buffered, sorted back-to-front, and emitted
        // after the opaque set so the backend's alpha-blend pass produces
        // correct over/under blending. Without this, two overlapping glass
        // panels render in iteration order, which depending on camera angle
        // shows nondeterministic edge artefacts.
        //
        // Scope: only DrawMesh draws produced by this system are sorted.
        // DrawMeshInstanced batches generated by other systems (e.g.
        // InstancedTerrainSystem) are NOT depth-sorted - per-instance
        // sorting would defeat batching. InstancedTerrainSystem rejects
        // transparent materials with a warning instead.
        /** @var list<array{0: float, 1: DrawMesh}> $transparentDraws */
        $transparentDraws = [];

        foreach ($world->query(MeshRenderer::class, Transform3D::class) as $entity) {
            $mesh = $entity->get(MeshRenderer::class);
            $transform = $entity->get(Transform3D::class);

            if (!$mesh->visible) {
                continue;
            }

            // Transform3DSystem refreshed worldMatrix earlier in the frame;
            // reusing it here saves two redundant Mat4::trs() rebuilds per
            // entity per frame — the dominant CPU win at scenes with
            // thousands of static entities.
            $matrix = $transform->worldMatrix;

            // Coarse pre-cull: if the entity's bin lies fully outside the
            // frustum, skip it without ever doing the per-entity sphere
            // test. Falls through when no bin is known yet (first frame
            // after a new entity is spawned).
            if ($visibleBins !== null) {
                $binKey = $this->entityBin[$entity->id] ?? null;
                if ($binKey !== null && !isset($visibleBins[$binKey])) {
                    continue;
                }
            }

            if ($planes !== null) {
                $sphere = self::getMeshSphere($mesh->meshId);
                if ($sphere !== null) {
                    // Fast path: most procedural meshes (Box/Sphere/Cylinder)
                    // are centred at local origin, so the world centre is
                    // just the matrix translation — skips a full
                    // Mat4::transformPoint allocation.
                    if ($sphere['cx'] === 0.0 && $sphere['cy'] === 0.0 && $sphere['cz'] === 0.0) {
                        $tr = $matrix->getTranslation();
                        $tx = $tr->x;
                        $ty = $tr->y;
                        $tz = $tr->z;
                    } else {
                        $worldCenter = $matrix->transformPoint(
                            new Vec3($sphere['cx'], $sphere['cy'], $sphere['cz']),
                        );
                        $tx = $worldCenter->x;
                        $ty = $worldCenter->y;
                        $tz = $worldCenter->z;
                    }
                    $sx = abs($transform->scale->x);
                    $sy = abs($transform->scale->y);
                    $sz = abs($transform->scale->z);
                    $maxScale = $sx > $sy ? ($sx > $sz ? $sx : $sz) : ($sy > $sz ? $sy : $sz);
                    $worldRadius = $sphere['radius'] * $maxScale;

                    // Inlined sphere-vs-frustum test. Saves a function call
                    // per entity, which adds up at 1k+ entities × 60 fps.
                    $cull = false;
                    foreach ($planes as $p) {
                        if ($p[0] * $tx + $p[1] * $ty + $p[2] * $tz + $p[3] < -$worldRadius) {
                            $cull = true;
                            break;
                        }
                    }
                    if ($cull) {
                        continue;
                    }
                }
            }

            $material = MaterialRegistry::get($mesh->materialId);
            $isTransparent = $material !== null && $material->alpha < 1.0;
            $draw = new DrawMesh($mesh->meshId, $mesh->materialId, $matrix);

            if ($isTransparent) {
                // Use the mesh's bounding-sphere centre (transformed into
                // world space) instead of just the matrix translation, so
                // off-pivot meshes (e.g. a glass panel pivoted at its
                // corner) sort by their actual centre. Falls back to the
                // translation when the sphere is unknown.
                $distSq = self::distanceSqToCameraForMesh($matrix, $mesh->meshId, $cameraPos);
                $transparentDraws[] = [$distSq, $draw];
            } else {
                $this->commandList->add($draw);
            }
        }

        if ($transparentDraws !== []) {
            // Sort descending: farthest first, so back-to-front blending is
            // applied by the backend's alpha pass.
            usort($transparentDraws, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
            foreach ($transparentDraws as [, $draw]) {
                $this->commandList->add($draw);
            }
        }
        // Submission + clear moved to render() so the GPU-submit cost is timed
        // under its own 'render3d.vio_submit' span, separate from this build pass.
    }

    /**
     * Distance² from camera to the world-space centre of the mesh's
     * bounding sphere. Off-pivot meshes (e.g. a glass panel anchored at a
     * corner) sort by their actual centre rather than their pivot, which
     * is what the alpha-blend pass needs to avoid sort glitches between
     * overlapping translucent geometry.
     *
     * Falls through to the matrix translation when the sphere is missing
     * or when its local centre is at the origin (the common case for the
     * procedural Box/Sphere/Cylinder primitives).
     */
    private static function distanceSqToCameraForMesh(Mat4 $modelMatrix, string $meshId, ?Vec3 $cameraPos): float
    {
        if ($cameraPos === null) {
            return 0.0;
        }
        $sphere = self::getMeshSphere($meshId);
        if ($sphere !== null && ($sphere['cx'] !== 0.0 || $sphere['cy'] !== 0.0 || $sphere['cz'] !== 0.0)) {
            $worldCenter = $modelMatrix->transformPoint(
                new Vec3($sphere['cx'], $sphere['cy'], $sphere['cz']),
            );
            $dx = $worldCenter->x - $cameraPos->x;
            $dy = $worldCenter->y - $cameraPos->y;
            $dz = $worldCenter->z - $cameraPos->z;
            return $dx * $dx + $dy * $dy + $dz * $dz;
        }
        return self::distanceSqToCamera($modelMatrix, $cameraPos);
    }

    private static function distanceSqToCamera(Mat4 $modelMatrix, ?Vec3 $cameraPos): float
    {
        if ($cameraPos === null) {
            return 0.0;
        }
        $tr = $modelMatrix->getTranslation();
        $dx = $tr->x - $cameraPos->x;
        $dy = $tr->y - $cameraPos->y;
        $dz = $tr->z - $cameraPos->z;
        return $dx * $dx + $dy * $dy + $dz * $dz;
    }

    /**
     * Re-bin every renderable entity into a 2D X-Z spatial grid and refresh
     * the per-bin AABBs. Called periodically rather than every frame because
     * almost all renderables in a typical scene are static.
     */
    private function rebuildBins(World $world): void
    {
        $this->binAabbs = [];
        $this->entityBin = [];

        foreach ($world->query(MeshRenderer::class, Transform3D::class) as $entity) {
            $mesh = $entity->get(MeshRenderer::class);
            if (!$mesh->visible) {
                continue; // hidden meshes must not inflate a bin AABB
            }
            $transform = $entity->get(Transform3D::class);
            $tr = $transform->worldMatrix->getTranslation();
            $bx = (int) floor($tr->x / self::BIN_SIZE);
            $bz = (int) floor($tr->z / self::BIN_SIZE);
            $key = $bx . ',' . $bz;
            $this->entityBin[$entity->id] = $key;

            // Expand the bin AABB by the entity's actual world-space extent.
            // A 3 km water plane sitting at one bin centre would otherwise
            // make the whole bin look ~256 m wide, and the bin would get
            // culled the moment the camera looked at the far end of the
            // plane — making the water "pop out" mid-screen.
            $sphere = self::getMeshSphere($mesh->meshId);
            if ($sphere !== null) {
                $sx = abs($transform->scale->x);
                $sy = abs($transform->scale->y);
                $sz = abs($transform->scale->z);
                $maxScale = $sx > $sy ? ($sx > $sz ? $sx : $sz) : ($sy > $sz ? $sy : $sz);
                $r = $sphere['radius'] * $maxScale;
            } else {
                // Unknown mesh size — fall back to a half-bin pad so we
                // never under-estimate the extent.
                $r = self::BIN_SIZE * 0.5;
            }

            $loX = $tr->x - $r; $hiX = $tr->x + $r;
            $loY = $tr->y - $r; $hiY = $tr->y + $r;
            $loZ = $tr->z - $r; $hiZ = $tr->z + $r;

            if (!isset($this->binAabbs[$key])) {
                $this->binAabbs[$key] = [$loX, $loY, $loZ, $hiX, $hiY, $hiZ];
            } else {
                $a = &$this->binAabbs[$key];
                if ($loX < $a[0]) { $a[0] = $loX; }
                if ($loY < $a[1]) { $a[1] = $loY; }
                if ($loZ < $a[2]) { $a[2] = $loZ; }
                if ($hiX > $a[3]) { $a[3] = $hiX; }
                if ($hiY > $a[4]) { $a[4] = $hiY; }
                if ($hiZ > $a[5]) { $a[5] = $hiZ; }
                unset($a);
            }
        }
    }

    /**
     * @param array<int, array{0:float,1:float,2:float,3:float}> $planes
     * @return array<string, true> Set of bin keys that intersect the frustum.
     */
    private function cullBins(array $planes): array
    {
        $visible = [];
        foreach ($this->binAabbs as $key => $a) {
            $outside = false;
            foreach ($planes as $p) {
                // For an AABB, the corner most-positive against the plane
                // normal gives the maximum distance from the plane. If that
                // corner is still on the negative side, the entire box is
                // outside — standard AABB-vs-plane Lengyel test.
                $px = $p[0] >= 0.0 ? $a[3] : $a[0];
                $py = $p[1] >= 0.0 ? $a[4] : $a[1];
                $pz = $p[2] >= 0.0 ? $a[5] : $a[2];
                if ($p[0] * $px + $p[1] * $py + $p[2] * $pz + $p[3] < 0.0) {
                    $outside = true;
                    break;
                }
            }
            if (!$outside) {
                $visible[$key] = true;
            }
        }
        return $visible;
    }

    /**
     * @return array<int, array{0:float,1:float,2:float,3:float}>|null
     *         Six normalised planes [nx, ny, nz, d] pointing inward, or null
     *         if no active camera was found this frame.
     */
    private function extractFrustumPlanes(): ?array
    {
        $cameras = $this->commandList->ofType(SetCamera::class);
        if ($cameras === []) {
            return null;
        }
        /** @var SetCamera $cam */
        $cam = end($cameras);

        // viewProj = projection * view (column-major Mat4::multiply applies
        // right-to-left when transforming a column vector).
        $vp = $cam->projectionMatrix->multiply($cam->viewMatrix);

        $r0 = [$vp->get(0, 0), $vp->get(0, 1), $vp->get(0, 2), $vp->get(0, 3)];
        $r1 = [$vp->get(1, 0), $vp->get(1, 1), $vp->get(1, 2), $vp->get(1, 3)];
        $r2 = [$vp->get(2, 0), $vp->get(2, 1), $vp->get(2, 2), $vp->get(2, 3)];
        $r3 = [$vp->get(3, 0), $vp->get(3, 1), $vp->get(3, 2), $vp->get(3, 3)];

        // Standard Gribb-Hartmann extraction.
        $planes = [
            [$r3[0] + $r0[0], $r3[1] + $r0[1], $r3[2] + $r0[2], $r3[3] + $r0[3]], // left
            [$r3[0] - $r0[0], $r3[1] - $r0[1], $r3[2] - $r0[2], $r3[3] - $r0[3]], // right
            [$r3[0] + $r1[0], $r3[1] + $r1[1], $r3[2] + $r1[2], $r3[3] + $r1[3]], // bottom
            [$r3[0] - $r1[0], $r3[1] - $r1[1], $r3[2] - $r1[2], $r3[3] - $r1[3]], // top
            [$r3[0] + $r2[0], $r3[1] + $r2[1], $r3[2] + $r2[2], $r3[3] + $r2[3]], // near
            [$r3[0] - $r2[0], $r3[1] - $r2[1], $r3[2] - $r2[2], $r3[3] - $r2[3]], // far
        ];

        foreach ($planes as $i => $p) {
            $len = sqrt($p[0] * $p[0] + $p[1] * $p[1] + $p[2] * $p[2]);
            if ($len > 1e-6) {
                $planes[$i] = [$p[0] / $len, $p[1] / $len, $p[2] / $len, $p[3] / $len];
            }
        }

        return $planes;
    }

    /**
     * Compute (and cache) the local-space bounding sphere of a mesh from its
     * raw vertex array. Returns null if the mesh isn't registered yet.
     *
     * @return array{cx:float, cy:float, cz:float, radius:float}|null
     */
    private static function getMeshSphere(string $meshId): ?array
    {
        if (isset(self::$sphereCache[$meshId])) {
            return self::$sphereCache[$meshId];
        }

        if (!MeshRegistry::has($meshId)) {
            return null;
        }
        $mesh = MeshRegistry::get($meshId);
        if ($mesh === null) {
            return null;
        }

        $verts = $mesh->vertices;
        $count = count($verts);
        if ($count < 3) {
            return self::$sphereCache[$meshId] = ['cx' => 0.0, 'cy' => 0.0, 'cz' => 0.0, 'radius' => 0.0];
        }

        // First pass: AABB.
        $minX = $maxX = $verts[0];
        $minY = $maxY = $verts[1];
        $minZ = $maxZ = $verts[2];
        for ($i = 3; $i < $count; $i += 3) {
            $x = $verts[$i];
            $y = $verts[$i + 1];
            $z = $verts[$i + 2];
            if ($x < $minX) { $minX = $x; } elseif ($x > $maxX) { $maxX = $x; }
            if ($y < $minY) { $minY = $y; } elseif ($y > $maxY) { $maxY = $y; }
            if ($z < $minZ) { $minZ = $z; } elseif ($z > $maxZ) { $maxZ = $z; }
        }

        $cx = ($minX + $maxX) * 0.5;
        $cy = ($minY + $maxY) * 0.5;
        $cz = ($minZ + $maxZ) * 0.5;

        // Second pass: tightest sphere around AABB centre.
        $rSq = 0.0;
        for ($i = 0; $i < $count; $i += 3) {
            $dx = $verts[$i]     - $cx;
            $dy = $verts[$i + 1] - $cy;
            $dz = $verts[$i + 2] - $cz;
            $d = $dx * $dx + $dy * $dy + $dz * $dz;
            if ($d > $rSq) {
                $rSq = $d;
            }
        }

        return self::$sphereCache[$meshId] = [
            'cx' => $cx,
            'cy' => $cy,
            'cz' => $cz,
            'radius' => sqrt($rSq),
        ];
    }
}
