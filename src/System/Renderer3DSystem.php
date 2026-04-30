<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Wind;
use PHPolygon\Component\Weather;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetGroundWetness;
use PHPolygon\Rendering\Command\SetSnowCover;
use PHPolygon\Rendering\Command\SetWaveAnimation;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\Renderer3DInterface;

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
        // Lights — directional + point, in sync with draws each frame.
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
            $pos = $entity->get(Transform3D::class)->getWorldPosition();
            if ($cameraPos !== null) {
                $dx = $pos->x - $cameraPos->x;
                $dy = $pos->y - $cameraPos->y;
                $dz = $pos->z - $cameraPos->z;
                $distSq = $dx * $dx + $dy * $dy + $dz * $dz;
                // Cull lights whose influence sphere can't reach the camera.
                // Generous cushion (×4) so lights remain visible just out of
                // immediate range when the player turns toward them.
                if ($distSq > $light->radius * $light->radius * 4.0) {
                    continue;
                }
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

        foreach ($world->query(MeshRenderer::class, Transform3D::class) as $entity) {
            $mesh = $entity->get(MeshRenderer::class);
            $transform = $entity->get(Transform3D::class);

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

            $this->commandList->add(new DrawMesh(
                $mesh->meshId,
                $mesh->materialId,
                $matrix,
            ));
        }

        $this->renderer->render($this->commandList);
        $this->commandList->clear();
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
            $tr = $entity->get(Transform3D::class)->worldMatrix->getTranslation();
            $bx = (int) floor($tr->x / self::BIN_SIZE);
            $bz = (int) floor($tr->z / self::BIN_SIZE);
            $key = $bx . ',' . $bz;
            $this->entityBin[$entity->id] = $key;

            if (!isset($this->binAabbs[$key])) {
                $this->binAabbs[$key] = [$tr->x, $tr->y, $tr->z, $tr->x, $tr->y, $tr->z];
            } else {
                $a = &$this->binAabbs[$key];
                if ($tr->x < $a[0]) { $a[0] = $tr->x; } elseif ($tr->x > $a[3]) { $a[3] = $tr->x; }
                if ($tr->y < $a[1]) { $a[1] = $tr->y; } elseif ($tr->y > $a[4]) { $a[4] = $tr->y; }
                if ($tr->z < $a[2]) { $a[2] = $tr->z; } elseif ($tr->z > $a[5]) { $a[5] = $tr->z; }
                unset($a);
            }
        }

        // Pad each bin AABB by half a bin to absorb mesh extents we don't
        // know about at this stage (the entity's translation is the centre,
        // but a tall building extends well beyond it).
        $pad = self::BIN_SIZE * 0.5;
        foreach ($this->binAabbs as $key => $a) {
            $this->binAabbs[$key] = [$a[0] - $pad, $a[1] - $pad, $a[2] - $pad, $a[3] + $pad, $a[4] + $pad, $a[5] + $pad];
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
