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
use PHPolygon\Rendering\Command\SetFieldtracing;
use PHPolygon\Rendering\Command\SetGroundWetness;
use PHPolygon\Rendering\Command\SetSnowCover;
use PHPolygon\Rendering\Command\SetWaveAnimation;
use PHPolygon\Rendering\Command\SetWind;
use PHPolygon\Rendering\Frustum;
use PHPolygon\Rendering\Quality\FieldtracingMode;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\Renderer3DInterface;
use PHPolygon\Rendering\TransparentDrawSorter;
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

    /**
     * Screen-size LOD. Beyond {@see $lodNearDistance}, an entity whose projected
     * size (world bounding radius / distance to camera) falls below
     * {@see $minScreenRatio} is skipped: a tiny far detail costs a full per-draw
     * FFI submit + uniform upload for no visible gain. Within the near distance
     * nothing is size-culled, so there is no pop-in where the player actually
     * stands; only the swarm of distant window / trim / prop / frond details
     * that bloat wide vistas drops out.
     *
     * Both are live-tunable without a rebuild so the FPS/pop sweet spot can be
     * dialled in: PHPOLYGON_LOD_RATIO (default 0.004) and PHPOLYGON_LOD_NEAR
     * (default 45). A larger ratio culls more aggressively (higher FPS, more
     * pop); a smaller near distance starts culling closer to the camera.
     * The defaults match the historical built-in behaviour (no surprise
     * culling) — set the env vars to opt into more aggressive distance LOD.
     */
    private const DEFAULT_LOD_NEAR  = 45.0;
    private const DEFAULT_LOD_RATIO = 0.004;

    private readonly float $lodNearDistance;
    private readonly float $minScreenRatio;

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

    /**
     * The world matrix each entity had when it was binned. Bins (with tight
     * per-entity AABBs) are rebuilt only every BIN_REBUILD_INTERVAL frames, so
     * the coarse pre-cull trusts a "bin culled" verdict only while the entity
     * still has this very matrix. Transform3DSystem replaces worldMatrix only
     * when the transform changed, so an identity check is an allocation-free
     * "has it moved (or turned, or scaled)?" test: a mover falls through to the
     * always-correct per-entity sphere test instead of strobing with its stale,
     * now-behind bin.
     *
     * @var array<int, Mat4>
     */
    private array $entityBinMatrix = [];

    private const BIN_SIZE = 256.0;
    private const BIN_REBUILD_INTERVAL = 30;

    private int $frameCount = 0;

    /**
     * World matrix each entity was DRAWN with last frame (entity id => matrix),
     * rebuilt every frame from the draws that survived culling. A draw whose
     * matrix instance differs gets it as DrawMesh::$prevModelMatrix; identity is
     * enough because Transform3DSystem only replaces worldMatrix when the
     * transform changed. Entities not drawn last frame have no entry, so a mover
     * coming back into view never reports a stale, frames-old motion.
     *
     * @var array<int, Mat4>
     */
    private array $lastDrawnMatrix = [];

    /** Throttle counters for the env-gated [LIGHTS]/[DRAWS] debug dumps. */
    private int $lightDebugFrame = 0;
    private int $drawDebugFrame = 0;

    private float $wavePhase = 0.0;
    private float $snowCover = 0.0;

    /**
     * Fieldtracing tier to emit each frame as a {@see SetFieldtracing} command,
     * or null to emit nothing (the backend then uses its own settings-derived
     * tier). The Engine keeps this in sync with GraphicsSettings::$fieldtracing.
     */
    private ?FieldtracingMode $fieldtracingMode = null;

    /** Set the Fieldtracing tier emitted each frame; null disables emission. */
    public function setFieldtracingMode(?FieldtracingMode $mode): void
    {
        $this->fieldtracingMode = $mode;
    }

    public function __construct(
        private readonly Renderer3DInterface $renderer,
        private readonly RenderCommandList $commandList,
    ) {
        $ratio = getenv('PHPOLYGON_LOD_RATIO');
        $this->minScreenRatio = ($ratio !== false && is_numeric($ratio))
            ? (float) $ratio : self::DEFAULT_LOD_RATIO;
        $near = getenv('PHPOLYGON_LOD_NEAR');
        $this->lodNearDistance = ($near !== false && is_numeric($near))
            ? (float) $near : self::DEFAULT_LOD_NEAR;
    }

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
        $this->entityBinMatrix = [];
        $this->frameCount = 0;
        // Ids restart after clear(): a fresh entity must not inherit the
        // previous occupant's matrix as fake motion.
        $this->lastDrawnMatrix = [];
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
            // One back-to-front order for EVERY transparent draw of the frame -
            // this system's meshes and whatever particles / instanced batches
            // other systems appended - keyed by the camera the list renders with.
            TransparentDrawSorter::sortList($this->commandList);
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
                $light->volumetricScale,
            ));
        }
        // The camera this frame renders with: the SetCamera Camera3DSystem
        // pushed (inverse view - eye offset and render interpolation included).
        // Falls back to the active camera entity when no SetCamera is queued.
        $cameraPos = $this->cameraPosition($world);

        // Camera3DSystem already pushed a SetCamera with the active view +
        // projection matrices. Pull the most recent one and derive 6 planes.
        // PHPOLYGON_NO_CULL=1 disables frustum/bin/LOD/light culling entirely
        // (diagnostic: a constant draw count isolates backend buffer-growth
        // glitches from cull-driven draw-count swings).
        $planes = getenv('PHPOLYGON_NO_CULL') === '1' ? null : $this->extractFrustumPlanes();

        // Point lights by effect, not by camera distance alone: a light whose
        // influence sphere (position, radius) lies completely outside the view
        // frustum lights no visible surface and takes no slot. The rest rank by
        // the distance from the camera to the light's sphere (0 inside it), then
        // by the distance to its centre, and the first MAX_POINT_LIGHTS are
        // emitted (the shaders honour only a few). A lamp lighting the wall the
        // camera looks at keeps its slot even when the camera stands outside its
        // radius - the reason the former camera-radius cull was dropped.
        $candidates = [];
        foreach ($world->query(PointLight::class, Transform3D::class) as $entity) {
            $light = $entity->get(PointLight::class);
            // Skip lights dimmed to (near) nothing - e.g. decorative lights
            // switched off in daylight. Keeps them from consuming a slot in the
            // per-frame point-light budget where they'd contribute no visible
            // light anyway.
            if ($light->intensity <= 0.001) {
                continue;
            }
            $pos = $entity->get(Transform3D::class)->getWorldPosition();
            $rank = self::rankLight($pos, $light->radius, $cameraPos, $planes);
            if ($rank === null) {
                continue;
            }
            $candidates[] = [$rank, $pos, $light];
        }

        if (count($candidates) > self::MAX_POINT_LIGHTS) {
            usort($candidates, static fn(array $a, array $b): int => ($a[0][0] <=> $b[0][0]) ?: ($a[0][1] <=> $b[0][1]));
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
            if ($this->lightDebugFrame++ % 60 === 0) {
                $msg = '[LIGHTS] emitted=' . count($candidates);
                foreach ($candidates as [$d, $p, $l]) {
                    $msg .= \sprintf(' (%.0f,%.0f,%.0f i=%.1f r=%.0f)', $p->x, $p->y, $p->z, $l->intensity, $l->radius);
                }
                \fwrite(\STDERR, $msg . "\n");
            }
        }

        // Spot lights - the same selection as point lights, with the range as
        // the influence radius: skip dimmed lights, drop beams whose range
        // sphere misses the frustum, keep the best MAX_SPOT_LIGHTS. Position
        // comes from the entity's Transform3D; the beam direction comes from
        // the SpotLight component itself.
        $spotCandidates = [];
        foreach ($world->query(SpotLight::class, Transform3D::class) as $entity) {
            $light = $entity->get(SpotLight::class);
            if ($light->intensity <= 0.001) {
                continue;
            }
            $pos = $entity->get(Transform3D::class)->getWorldPosition();
            $rank = self::rankLight($pos, $light->range, $cameraPos, $planes);
            if ($rank === null) {
                continue;
            }
            $spotCandidates[] = [$rank, $pos, $light];
        }

        if (count($spotCandidates) > self::MAX_SPOT_LIGHTS) {
            usort($spotCandidates, static fn(array $a, array $b): int => ($a[0][0] <=> $b[0][0]) ?: ($a[0][1] <=> $b[0][1]));
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
            $wind = $entity->get(Wind::class);
            $windIntensity = $wind->intensity;
            // The scene's wind (gusts and storm from WindSystem) drives cloth and
            // foliage sway in the vertex shader. Without a Wind component the
            // renderer keeps its calm-air default.
            $this->commandList->add(new SetWind(
                direction: $wind->direction,
                intensity: $wind->intensity,
                foliageMotion: $wind->foliageMotion,
                foliageFadeStart: $wind->foliageFadeStart,
                foliageFadeEnd: $wind->foliageFadeEnd,
            ));
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

        // Fieldtracing (SDF global illumination): emit the configured tier so the
        // backend applies it for this frame. Opt-in — only emitted once a mode
        // has been set (the Engine pushes GraphicsSettings::$fieldtracing here on
        // settings change). When unset, no command is emitted and the backend
        // falls back to its own settings-derived tier (renderer is authority).
        if ($this->fieldtracingMode !== null) {
            $this->commandList->add(new SetFieldtracing($this->fieldtracingMode));
        }

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

        // Transparent draws are buffered and emitted after the opaque set; the
        // back-to-front order of ALL transparent draws of the frame (these and
        // other systems' particles / instanced batches) is set afterwards by
        // TransparentDrawSorter in render().
        /** @var list<DrawMesh> $transparentDraws */
        $transparentDraws = [];
        $drawnOpaque = 0;
        $lastDrawn = $this->lastDrawnMatrix;
        $drawnNow = [];

        /** @var array<int, Transform3D> $transforms */
        $transforms = $world->componentPool(Transform3D::class);
        foreach (self::renderables($world, $transforms) as $entityId => $mesh) {
            if (!$mesh->visible) {
                continue;
            }
            $transform = $transforms[$entityId];

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
                $binKey = $this->entityBin[$entityId] ?? null;
                if ($binKey !== null && !isset($visibleBins[$binKey])
                    && ($this->entityBinMatrix[$entityId] ?? null) === $matrix
                ) {
                    // Culled bin, and the entity has not moved since binning.
                    // A mover (different worldMatrix instance) falls through to
                    // the per-entity sphere test - see $entityBinMatrix.
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
                    // World scale (parents included), not the local Transform3D
                    // scale: a child of a scaled parent must not be culled with
                    // its unscaled radius.
                    $worldRadius = $sphere['radius'] * self::maxAxisScale($matrix);

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

                    // Screen-size LOD: drop far entities that project to a
                    // sub-pixel sliver. Reuses the world centre + radius the
                    // frustum test already computed.
                    if ($cameraPos !== null) {
                        $cdx = $tx - $cameraPos->x;
                        $cdy = $ty - $cameraPos->y;
                        $cdz = $tz - $cameraPos->z;
                        $camDistSq = $cdx * $cdx + $cdy * $cdy + $cdz * $cdz;
                        if ($camDistSq > $this->lodNearDistance * $this->lodNearDistance
                            && $worldRadius * $worldRadius
                                < $this->minScreenRatio * $this->minScreenRatio * $camDistSq) {
                            continue;
                        }
                    }
                }
            }

            $material = MaterialRegistry::get($mesh->materialId);
            $isTransparent = $material !== null && $material->alpha < 1.0;
            $prev = $lastDrawn[$entityId] ?? null;
            $drawnNow[$entityId] = $matrix;
            $draw = new DrawMesh(
                $mesh->meshId,
                $mesh->materialId,
                $matrix,
                $mesh->excludeFromGbuffer,
                $prev !== $matrix ? $prev : null,
                $mesh->castShadows,
            );

            if ($isTransparent) {
                $transparentDraws[] = $draw;
            } else {
                $this->commandList->add($draw);
                $drawnOpaque++;
            }
        }

        $this->lastDrawnMatrix = $drawnNow;

        foreach ($transparentDraws as $draw) {
            $this->commandList->add($draw);
        }

        if (\getenv('PHPOLYGON_DRAW_DEBUG') === '1') {
            if ($this->drawDebugFrame++ % 60 === 0) {
                \fwrite(\STDERR, \sprintf(
                    "[DRAWS] mesh=%d (opaque=%d transparent=%d)\n",
                    $drawnOpaque + count($transparentDraws),
                    $drawnOpaque,
                    count($transparentDraws),
                ));
            }
        }
        // Submission + clear moved to render() so the GPU-submit cost is timed
        // under its own 'render3d.vio_submit' span, separate from this build pass.
    }

    /**
     * World position of the camera the list renders with: the inverse view of
     * the last queued SetCamera (eye offset and interpolation included), else
     * the active camera entity (with its eye offset), else the first camera.
     */
    private function cameraPosition(World $world): ?Vec3
    {
        $camera = $this->commandList->lastOfType(SetCamera::class);
        if ($camera !== null) {
            return $camera->viewMatrix->inverse()->getTranslation();
        }
        $fallback = null;
        foreach ($world->query(Camera3DComponent::class, Transform3D::class) as $entity) {
            $position = $entity->get(Transform3D::class)->getWorldPosition();
            $component = $entity->get(Camera3DComponent::class);
            if ($component->active) {
                return $position->add($component->eyeOffset);
            }
            $fallback ??= $position;
        }
        return $fallback;
    }

    /**
     * Rank of a light with influence radius $radius at $pos, or null when its
     * influence sphere lies completely outside the frustum (it lights nothing
     * visible). Rank = [distance from the camera to the sphere (0 inside),
     * distance to the centre]; smaller ranks first.
     *
     * @param array<int, array{0:float,1:float,2:float,3:float}>|null $planes
     * @return array{0: float, 1: float}|null
     */
    private static function rankLight(Vec3 $pos, float $radius, ?Vec3 $cameraPos, ?array $planes): ?array
    {
        $r = max(0.0, $radius);
        if ($planes !== null) {
            foreach ($planes as $p) {
                if ($p[0] * $pos->x + $p[1] * $pos->y + $p[2] * $pos->z + $p[3] < -$r) {
                    return null;
                }
            }
        }
        if ($cameraPos === null) {
            return [0.0, 0.0];
        }
        $dx = $pos->x - $cameraPos->x;
        $dy = $pos->y - $cameraPos->y;
        $dz = $pos->z - $cameraPos->z;
        $dist = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
        return [max(0.0, $dist - $r), $dist];
    }

    /**
     * Largest axis scale of a world matrix (column lengths of its 3x3 part),
     * so a bounding sphere scaled by it contains the mesh under any rotation
     * and any parent scale.
     */
    private static function maxAxisScale(Mat4 $matrix): float
    {
        $m = $matrix->toArray();
        $sx = $m[0] * $m[0] + $m[1] * $m[1] + $m[2] * $m[2];
        $sy = $m[4] * $m[4] + $m[5] * $m[5] + $m[6] * $m[6];
        $sz = $m[8] * $m[8] + $m[9] * $m[9] + $m[10] * $m[10];
        return sqrt($sx > $sy ? ($sx > $sz ? $sx : $sz) : ($sy > $sz ? $sy : $sz));
    }

    /**
     * Every active MeshRenderer that also has a Transform3D, keyed by entity
     * id, in the order `query(MeshRenderer, Transform3D)` yields them (the
     * smaller pool's order) - read straight from the component pools. The
     * query's generator, Entity wrapper and get() calls per entity cost more
     * than the draw loop's own work once a scene holds thousands of hidden
     * renderers (parts drawn through an instanced batch keep their hidden
     * MeshRenderer).
     *
     * @param array<int, Transform3D> $transforms the active Transform3D pool
     * @return array<int, MeshRenderer>
     */
    private static function renderables(World $world, array $transforms): array
    {
        /** @var array<int, MeshRenderer> $meshes */
        $meshes = $world->componentPool(MeshRenderer::class);
        if (count($transforms) < count($meshes)) {
            $ordered = [];
            foreach ($transforms as $id => $_) {
                if (isset($meshes[$id])) {
                    $ordered[$id] = $meshes[$id];
                }
            }
            return $ordered;
        }
        foreach ($meshes as $id => $_) {
            if (!isset($transforms[$id])) {
                unset($meshes[$id]);
            }
        }
        return $meshes;
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
        $this->entityBinMatrix = [];

        /** @var array<int, Transform3D> $transforms */
        $transforms = $world->componentPool(Transform3D::class);
        foreach (self::renderables($world, $transforms) as $entityId => $mesh) {
            if (!$mesh->visible) {
                continue; // hidden meshes must not inflate a bin AABB
            }
            $transform = $transforms[$entityId];
            $tr = $transform->worldMatrix->getTranslation();
            $bx = (int) floor($tr->x / self::BIN_SIZE);
            $bz = (int) floor($tr->z / self::BIN_SIZE);
            $key = $bx . ',' . $bz;
            $this->entityBin[$entityId] = $key;
            $this->entityBinMatrix[$entityId] = $transform->worldMatrix;

            // Expand the bin AABB by the entity's actual world-space extent.
            // A 3 km water plane sitting at one bin centre would otherwise
            // make the whole bin look ~256 m wide, and the bin would get
            // culled the moment the camera looked at the far end of the
            // plane — making the water "pop out" mid-screen.
            $sphere = self::getMeshSphere($mesh->meshId);
            if ($sphere !== null) {
                $r = $sphere['radius'] * self::maxAxisScale($transform->worldMatrix);
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

        return Frustum::planes($cam->projectionMatrix, $cam->viewMatrix);
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
