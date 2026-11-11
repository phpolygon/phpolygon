<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\AmbientLight;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\RectAreaLight;
use PHPolygon\Component\SpotLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Wind;
use PHPolygon\Component\Weather;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\AddRectLight;
use PHPolygon\Rendering\Command\AddSpotLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetFieldtracing;
use PHPolygon\Rendering\Command\SetGroundWetness;
use PHPolygon\Rendering\Command\SetSnowCover;
use PHPolygon\Rendering\Command\SetWaveAnimation;
use PHPolygon\Rendering\Command\SetWind;
use PHPolygon\Rendering\BatchBounds;
use PHPolygon\Rendering\Quality\FieldtracingMode;
use PHPolygon\Rendering\InstanceParams;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\MeshBounds;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\Renderer3DInterface;
use PHPolygon\Rendering\TransparentDrawSorter;
use PHPolygon\Runtime\PerfProfiler;
use PHPolygon\Visibility\VisibilityService;

/**
 * Default 3D renderer system.
 *
 * Collects lights, wave / snow / wetness state and emits {@see DrawMesh}
 * commands for every visible {@see MeshRenderer}. Which entities are
 * visible this frame comes from {@see \PHPolygon\Visibility\VisibilityService}
 * (resolved for $world via its forWorld() factory): beginFrameFrom() there
 * classifies its SpatialIndex against the current camera (deriving it, the
 * active fog and the primary directional light from this system's own
 * RenderCommandList and $world), and visibleEntities() replaces what used to
 * be a full MeshRenderer pool scan with a coarse spatial bin pre-cull
 * (removed together with that scan - see the visibility plan, Phase 2).
 * Only the screen-size LOD drop and exact bounding-sphere lookup for it stay
 * local to this system.
 *
 * Point/spot/rect light culling (Phase 3) and the per-entity shadow-only
 * casters this system adds beyond its own visible draws (see the
 * DrawMesh::$shadowOnly loop in renderCommands()) go through the SAME
 * VisibilityService instance - one cull spot, not six (see the visibility
 * plan's "Ist-Zustand": Renderer3DSystem, ParticleSystem, LightConeSystem,
 * FoliageInstanceSystem, GrassSystem and MotionView each used to compute
 * their own frustum).
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

    /** Rectangular area lights the mesh shader evaluates (the nearest ones). */
    public const MAX_RECT_LIGHTS = 4;

    /** Seconds the ground takes to dry from soaked once the rain stops. */
    public const float DRY_SECONDS = 150.0;


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
     * Drop the per-entity draw-matrix cache on World::clear(). After clear()
     * the entity id counter restarts from 0, so the next createEntity()
     * reuses the same ids; without resetting $lastDrawnMatrix a fresh entity
     * would inherit the previous occupant's matrix as fake motion. The
     * VisibilityService's own SpatialIndex is cleaned up independently, via
     * the Transform3D/MeshRenderer detach notifications World::clear()
     * fires for every entity it destroys - see VisibilityService::bindToWorld().
     */
    public function onWorldClear(World $world): void
    {
        $this->lastDrawnMatrix = [];
    }

    public function update(World $world, float $dt): void
    {
        $this->wavePhase += $dt;

        // Ground wetness: soaks at once with the rain, dries over DRY_SECONDS after it.
        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);
            $target = min(1.0, $weather->rainIntensity * 1.2 + $weather->stormIntensity * 0.4);
            $weather->groundWetness = $target >= $weather->groundWetness
                ? $target
                : max($target, $weather->groundWetness - $dt / self::DRY_SECONDS);
            break;
        }

        // Gradual snow accumulation / melting
        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);
            if ($weather->snowIntensity > 0.1 && $weather->temperature < 2.0) {
                $weather->snowCover = min(1.0, $weather->snowCover + $weather->snowIntensity * 0.008 * $dt);
            } else {
                $meltRate = $weather->temperature > 5.0 ? 0.006 : 0.003;
                $weather->snowCover = max(0.0, $weather->snowCover - $meltRate * $dt);
            }
            $this->snowCover = $weather->snowCover;
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

        // The single answer to "what is visible this frame" - see the class
        // docblock. Derives the camera (and fog/directional light for the
        // shadow-caster set below) from $this->commandList itself, so it no
        // longer matters whether Camera3DSystem, another Phase-3 consumer
        // (GrassSystem, FoliageInstanceSystem, LightConeSystem, ParticleSystem)
        // or this system calls it first this frame - repeating the same
        // inputs is a cheap memo hit, not a second classification.
        PerfProfiler::begin('render3d.visibility');
        $visibility = VisibilityService::forWorld($world);
        try {
            $visibility->beginFrameFrom($world, $this->commandList);
        } finally {
            PerfProfiler::end();
        }

        // Point lights by effect, not by camera distance alone: a light whose
        // influence sphere (position, radius) lies completely outside the view
        // frustum lights no visible surface and takes no slot. The rest rank by
        // the distance from the camera to the light's sphere (0 inside it), then
        // by the distance to its centre, and the first MAX_POINT_LIGHTS are
        // emitted (the shaders honour only a few). A lamp lighting the wall the
        // camera looks at keeps its slot even when the camera stands outside its
        // radius - the reason the former camera-radius cull was dropped. The
        // frustum test itself now goes through VisibilityService::testSphere()
        // instead of this system's own Frustum::planes() - one cull spot, and
        // it already honours PHPOLYGON_NO_CULL.
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
            $rank = self::rankLight($pos, $light->radius, $cameraPos, $visibility);
            if ($rank === null) {
                continue;
            }
            $candidates[] = [$rank, $pos, $light];
        }

        // A renderer with the world light grid reads every light per cell and takes
        // up to its own limit; the others honour MAX_POINT_LIGHTS.
        $rendererLimit = method_exists($this->renderer, 'maxPointLights') ? $this->renderer->maxPointLights() : null;
        $limit = is_int($rendererLimit) ? max(self::MAX_POINT_LIGHTS, $rendererLimit) : self::MAX_POINT_LIGHTS;
        if (count($candidates) > $limit) {
            usort($candidates, static fn(array $a, array $b): int => ($a[0][0] <=> $b[0][0]) ?: ($a[0][1] <=> $b[0][1]));
            $candidates = array_slice($candidates, 0, $limit);
        }

        foreach ($candidates as [$distSq, $pos, $light]) {
            $this->commandList->add(new AddPointLight(
                $pos,
                $light->color,
                $light->intensity,
                $light->radius,
                $light->castsShadow,
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
            $rank = self::rankLight($pos, $light->range, $cameraPos, $visibility);
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
                $light->castsShadow,
            ));
        }

        // Rectangular area lights: the rectangle in the local XZ plane, emitting
        // along local -Y, turned by the world matrix; the nearest MAX_RECT_LIGHTS.
        $rectCandidates = [];
        foreach ($world->query(RectAreaLight::class, Transform3D::class) as $entity) {
            $light = $entity->get(RectAreaLight::class);
            if ($light->intensity <= 0.001 || $light->width <= 0.0 || $light->depth <= 0.0) {
                continue;
            }
            $m = $entity->get(Transform3D::class)->getWorldMatrix();
            $pos = $m->getTranslation();
            $rank = self::rankLight($pos, $light->range, $cameraPos, $visibility);
            if ($rank === null) {
                continue;
            }
            $rectCandidates[] = [$rank, $pos, $m, $light];
        }
        if (count($rectCandidates) > self::MAX_RECT_LIGHTS) {
            usort($rectCandidates, static fn(array $a, array $b): int => ($a[0][0] <=> $b[0][0]) ?: ($a[0][1] <=> $b[0][1]));
            $rectCandidates = array_slice($rectCandidates, 0, self::MAX_RECT_LIGHTS);
        }
        foreach ($rectCandidates as [, $pos, $m, $light]) {
            $this->commandList->add(new AddRectLight(
                $pos,
                $m->transformDirection(new Vec3(1.0, 0.0, 0.0))->normalize()->mul($light->width * 0.5),
                $m->transformDirection(new Vec3(0.0, 0.0, 1.0))->normalize()->mul($light->depth * 0.5),
                $light->color,
                $light->intensity,
                $light->range,
            ));
        }

        // Wave animation driven by wind + weather; ground wetness from rain.
        $windIntensity = 0.5;
        $stormIntensity = 0.0;
        $rainWetness = 0.0;
        $raining = 0.0;
        $dryBelowY = null;
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
            $rainWetness = max($weather->groundWetness, min(1.0, $weather->rainIntensity * 1.2 + $weather->stormIntensity * 0.4));
            $raining = $weather->rainIntensity;
            $dryBelowY = $weather->dryBelowY;
            break;
        }
        $waveAmp  = 0.1 + $windIntensity * 0.25 + $stormIntensity * 0.35;
        $waveFreq = 0.4 + $windIntensity * 0.15 + $stormIntensity * 0.15;
        foreach ($world->query(\PHPolygon\Component\Season::class) as $entity) {
            $season = $entity->get(\PHPolygon\Component\Season::class);
            if ($season->gradeTint !== null) {
                $this->commandList->add(new \PHPolygon\Rendering\Command\SetSeasonGrade($season->gradeTint, $season->gradeDesaturation));
            }
            break;
        }
        $this->commandList->add(new SetSnowCover($this->snowCover));
        $this->commandList->add(new SetGroundWetness($rainWetness, $raining, $dryBelowY));
        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);
            $windDir = new Vec3(1.0, 0.0, 0.0);
            foreach ($world->query(Wind::class) as $windEntity) {
                $windDir = $windEntity->get(Wind::class)->direction;
                break;
            }
            $this->commandList->add(new \PHPolygon\Rendering\Command\SetSandstorm(
                $weather->sandstormIntensity,
                $weather->sandstormColor ?? new \PHPolygon\Rendering\Color(0.62, 0.48, 0.30),
                $windDir,
            ));
            break;
        }
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

        // Transparent draws are buffered and emitted after the opaque set; the
        // back-to-front order of ALL transparent draws of the frame (these and
        // other systems' particles / instanced batches) is set afterwards by
        // TransparentDrawSorter in render().
        /** @var list<DrawMesh> $transparentDraws */
        $transparentDraws = [];
        /** @var array<string, array{0: MeshRenderer, 1: list<Mat4>}> $batches */
        $batches = [];
        $drawnOpaque = 0;
        $lastDrawn = $this->lastDrawnMatrix;
        $drawnNow = [];

        /** @var array<int, Transform3D> $transforms */
        $transforms = $world->componentPool(Transform3D::class);
        /** @var array<int, MeshRenderer> $meshes */
        $meshes = $world->componentPool(MeshRenderer::class);
        foreach ($visibility->visibleEntities() as $entityId) {
            $mesh = $meshes[$entityId] ?? null;
            $transform = $transforms[$entityId] ?? null;
            if ($mesh === null || $transform === null) {
                // Detached between beginFrame() and here - shouldn't happen
                // within one synchronous render() call, guarded anyway.
                continue;
            }

            // Transform3DSystem refreshed worldMatrix earlier in the frame;
            // reusing it here saves two redundant Mat4::trs() rebuilds per
            // entity per frame — the dominant CPU win at scenes with
            // thousands of static entities.
            $matrix = $transform->worldMatrix;

            // VisibilityService already decided frustum membership. Screen-size
            // LOD is a separate concern it does not apply: a tiny distant
            // detail can still be inside the frustum and need dropping here.
            // frame() is null exactly when there is no camera this frame or
            // PHPOLYGON_NO_CULL=1 is set - the same two cases the former
            // local $planes variable was null for.
            if ($visibility->frame() !== null && $cameraPos !== null) {
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

                    // Screen-size LOD: drop far entities that project to a
                    // sub-pixel sliver.
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

            $material = MaterialRegistry::get($mesh->materialId);
            $isTransparent = $material !== null && $material->alpha < 1.0;
            $prev = $lastDrawn[$entityId] ?? null;
            $drawnNow[$entityId] = $matrix;

            // Batchable draws wait until culling is through: what survived of
            // one mesh + material becomes one draw below. A mover needs its own
            // draw for the motion vector.
            if ($mesh->batchable && !$isTransparent && !$mesh->excludeFromGbuffer && ($prev === null || $prev === $matrix)) {
                $key = $mesh->meshId . '|' . $mesh->materialId . '|' . ($mesh->castShadows ? '1' : '0');
                $batches[$key][0] = $mesh;
                $batches[$key][1][] = $matrix;
                if ($mesh->shaderParams !== null) {
                    $batches[$key][2][count($batches[$key][1]) - 1] = $mesh->shaderParams;
                }
                continue;
            }

            $draw = new DrawMesh(
                $mesh->meshId,
                $mesh->materialId,
                $matrix,
                $mesh->excludeFromGbuffer,
                $prev !== $matrix ? $prev : null,
                $mesh->castShadows,
                $mesh->shaderParams,
            );

            if ($isTransparent) {
                $transparentDraws[] = $draw;
            } else {
                $this->commandList->add($draw);
                $drawnOpaque++;
            }
        }

        $this->lastDrawnMatrix = $drawnNow;

        // Shadow casters outside the view but inside the sun's caster volume
        // (VisibilityService::shadowOnlyEntities() - Phase 3's shadow-
        // correctness fix: these entities never got a draw command at all
        // before, so VioRenderer3D's collectShadowDraws() - which reads the
        // full, unfiltered command list, not the camera-culled one - could
        // never find them). Always single, unbatched draws: this set is
        // usually small, and never merging it with $batches means a
        // shadow-only instance can never end up inside a visible batch's
        // DrawMeshInstanced (which has no shadowOnly flag of its own). Not
        // tracked in $drawnNow/$lastDrawnMatrix: the shadow pass reads only
        // modelMatrix, never prevModelMatrix, so there is nothing for the
        // next frame's motion-vector bookkeeping to reuse here.
        foreach ($visibility->shadowOnlyEntities() as $entityId) {
            $mesh = $meshes[$entityId] ?? null;
            $transform = $transforms[$entityId] ?? null;
            if ($mesh === null || $transform === null) {
                continue;
            }
            $this->commandList->add(new DrawMesh(
                $mesh->meshId,
                $mesh->materialId,
                $transform->worldMatrix,
                castShadows: true,
                shadowOnly: true,
            ));
        }

        foreach ($batches as $batch) {
            [$mesh, $matrices] = $batch;
            $params = $batch[2] ?? [];
            if ($params !== [] && count($matrices) > 1) {
                foreach ($params as $i => $p) {
                    $matrices[$i] = InstanceParams::matrix($matrices[$i], $p[0], $p[1], $p[2]);
                }
            }
            $this->commandList->add(count($matrices) === 1
                ? new DrawMesh($mesh->meshId, $mesh->materialId, $matrices[0], castShadows: $mesh->castShadows, params: $params[0] ?? null)
                // Every instance just passed the per-entity test above: the
                // renderer has nothing to derive or cull for this batch.
                : new DrawMeshInstanced($mesh->meshId, $mesh->materialId, $matrices, castShadows: $mesh->castShadows, bounds: BatchBounds::unbounded()));
            $drawnOpaque += count($matrices);
        }

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
     * influence sphere lies completely outside the view (it lights nothing
     * visible) - via VisibilityService::testSphere(), the same cull spot
     * Renderer3DSystem's own entity draws use, instead of a locally held
     * plane array (also honours PHPOLYGON_NO_CULL for free). Rank = [distance
     * from the camera to the sphere (0 inside), distance to the centre];
     * smaller ranks first.
     *
     * @return array{0: float, 1: float}|null
     */
    private static function rankLight(Vec3 $pos, float $radius, ?Vec3 $cameraPos, VisibilityService $visibility): ?array
    {
        $r = max(0.0, $radius);
        if (!$visibility->testSphere($pos->x, $pos->y, $pos->z, $r)) {
            return null;
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
     * Local-space bounding sphere of a mesh, or null if it isn't registered
     * yet. Delegates to {@see MeshBounds}, shared with VisibilityService's
     * SpatialIndex entries so both derive the same sphere from one place.
     *
     * @return array{cx:float, cy:float, cz:float, radius:float}|null
     */
    private static function getMeshSphere(string $meshId): ?array
    {
        return MeshBounds::localSphere($meshId);
    }
}
