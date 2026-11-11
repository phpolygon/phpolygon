<?php

declare(strict_types=1);

namespace PHPolygon\Visibility;

use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\MeshRendererChangeQueue;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\ComponentInterface;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Frustum;
use PHPolygon\Rendering\MeshBounds;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\SunCasterVolume;
use PHPolygon\Rendering\ViewVolume;
use PHPolygon\Runtime\PerfProfiler;
use PHPolygon\System\Transform3DSystem;

/**
 * The engine's single answer to "what is in the view this frame" - built for
 * the plan "die Engine prueft selbst, was im Sichtfeld ist" (2026-11-11).
 * Owns a {@see SpatialIndex} of every MeshRenderer+Transform3D pair in a
 * World and classifies it against the current camera once per frame via
 * {@see beginFrame()} (or {@see beginFrameFrom()}, which derives the camera/
 * fog/light inputs from a World + RenderCommandList itself).
 *
 * One instance per World, resolved through {@see forWorld()} rather than
 * constructed directly - Engine (`$engine->visibility`), Renderer3DSystem
 * (which many tests construct standalone, without an Engine) and every other
 * render-phase consumer resolve the SAME instance for a given World this
 * way, with no required constructor wiring on any side.
 *
 * Phase 3 of the visibility plan made EVERY render-phase system that used to
 * compute its own frustum (Camera3DSystem's consumers: GrassSystem,
 * FoliageInstanceSystem, LightConeSystem, ParticleSystem, Renderer3DSystem's
 * point/spot/rect lights) call {@see beginFrameFrom()} as the first thing
 * their own render() does, then query testSphere()/sees()/visibleEntities().
 * Registration order no longer matters: beginFrame() memoizes on its own
 * inputs (camera matrices by identity, fog far, light direction, the
 * SpatialIndex's version counter) so the FIRST caller each frame does the
 * real work and every later caller with the same inputs is a cheap no-op -
 * see $viewMemoHits/$casterMemoHits and the 'visibility.begin_frame'
 * profiler span.
 *
 * Maintenance is event-driven, never a per-frame full scan:
 *  - World::observe() on Transform3D and MeshRenderer attach/detach keeps
 *    the index in step with the entity population (see bindToWorld());
 *  - Transform3DSystem::drainMovedSinceLastDrain(), if a Transform3DSystem
 *    is registered on the World, refreshes moved entries;
 *  - MeshRenderer's $visible/$meshId set hooks (via MeshRendererChangeQueue)
 *    refresh/remove an entry when a mesh is swapped or blinked.
 */
final class VisibilityService
{
    /** @var \WeakMap<World, self>|null */
    private static ?\WeakMap $instances = null;

    public static function forWorld(World $world): self
    {
        self::$instances ??= new \WeakMap();
        $service = self::$instances[$world] ??= new self();
        $service->bindToWorld($world);
        return $service;
    }

    private SpatialIndex $index;

    private MeshRendererChangeQueue $meshChangeQueue;

    private bool $bound = false;

    private ?World $boundWorld = null;

    /** The current frame's camera, or null when beginFrame() ran without one (or PHPOLYGON_NO_CULL=1). */
    private ?ViewVolume $currentVolume = null;

    /**
     * Handles visible this frame, or null when $currentVolume is null -
     * meaning every registered, non-dormant entry is visible (there is
     * nothing to cull against).
     *
     * @var array<int, true>|null
     */
    private ?array $visibleSet = null;

    /**
     * Cell keys that are INSIDE or INTERSECTED this frame - backs tierAt()'s stub.
     *
     * @var array<string, true>
     */
    private array $visibleCellKeys = [];

    /**
     * Entity-kind handles that cast a shadow but are not this frame's
     * $visibleSet - what Renderer3DSystem adds as extra DrawMesh(shadowOnly:
     * true) commands so VioRenderer3D's collectShadowDraws() (which reads
     * the full, unfiltered command list) finds them even though they never
     * reach the camera's own passes. Empty whenever there is no directional
     * light or nothing is being culled (every registered entry is already
     * "visible" - see classifyCasters()).
     *
     * @var array<int, true>
     */
    private array $shadowOnlySet = [];

    /** Below this bounding-sphere radius (world metres) a caster is not worth a shadow draw; 0 = never sieved by size. See setMinShadowCasterRadius(). */
    private float $minShadowCasterRadius = 0.0;

    private ?OcclusionSource $occlusion = null;

    // --- View memo: skip reclassification when nothing that feeds it changed ---
    private bool $hasViewMemo = false;
    private ?Mat4 $memoView = null;
    private ?Mat4 $memoProjection = null;
    private bool $memoNoCull = false;
    private ?float $memoFogFar = null;
    private int $memoViewIndexVersion = -1;
    private int $viewMemoHits = 0;
    private int $viewMemoMisses = 0;

    // --- Caster memo: additionally invalidated whenever the view memo missed, since caster eligibility reads $visibleSet ---
    private bool $hasCasterMemo = false;
    private ?float $memoLightX = null;
    private ?float $memoLightY = null;
    private ?float $memoLightZ = null;
    private float $memoCasterOrthoSize = 0.0;
    private int $memoCasterResolution = 0;
    private int $memoCasterIndexVersion = -1;
    private int $casterMemoHits = 0;
    private int $casterMemoMisses = 0;

    public function __construct()
    {
        $this->index = new SpatialIndex();
        $this->meshChangeQueue = new MeshRendererChangeQueue();
    }

    /**
     * Classifies the index against this frame's camera (and, with $lightDir,
     * the sun's shadow-caster volume). Pass null for $view/$projection when
     * no camera is active (or PHPOLYGON_NO_CULL=1 is set) - nothing is
     * culled then, matching the engine's existing diagnostic convention.
     *
     * $fogFar caps the visible set at that surface distance from the camera
     * (never applied to shadow casters - a caster beyond the fog still
     * shadows what is in front of it); null (no active SetFog) means no cap.
     * $casterOrthoSize/$casterResolution size the caster prism exactly like
     * VioRenderer3D's largest cascade - override them to match a renderer
     * whose shadow settings differ from {@see SunCasterVolume}'s defaults.
     *
     * Idempotent per set of inputs within the same SpatialIndex state - see
     * the class docblock. Most callers should use {@see beginFrameFrom()}
     * instead, which derives these from a World + RenderCommandList.
     */
    public function beginFrame(
        ?Mat4 $view,
        ?Mat4 $projection,
        ?Vec3 $lightDir = null,
        ?float $fogFar = null,
        float $casterOrthoSize = SunCasterVolume::DEFAULT_ORTHO_SIZE,
        int $casterResolution = SunCasterVolume::DEFAULT_RESOLUTION,
    ): void {
        PerfProfiler::begin('visibility.begin_frame');
        try {
            $this->syncFromWorld();
            $indexVersion = $this->index->version();
            $noCull = getenv('PHPOLYGON_NO_CULL') === '1';

            $viewUnchanged = $this->hasViewMemo
                && $this->memoNoCull === $noCull
                && $this->memoView === $view
                && $this->memoProjection === $projection
                && $this->memoFogFar === $fogFar
                && $this->memoViewIndexVersion === $indexVersion;

            if ($viewUnchanged) {
                $this->viewMemoHits++;
            } else {
                $this->viewMemoMisses++;
                $this->classifyView($view, $projection, $noCull, $fogFar);
                $this->hasViewMemo = true;
                $this->memoView = $view;
                $this->memoProjection = $projection;
                $this->memoNoCull = $noCull;
                $this->memoFogFar = $fogFar;
                $this->memoViewIndexVersion = $indexVersion;
            }

            $lightX = $lightDir?->x;
            $lightY = $lightDir?->y;
            $lightZ = $lightDir?->z;
            $casterUnchanged = $viewUnchanged
                && $this->hasCasterMemo
                && $this->memoLightX === $lightX
                && $this->memoLightY === $lightY
                && $this->memoLightZ === $lightZ
                && $this->memoCasterOrthoSize === $casterOrthoSize
                && $this->memoCasterResolution === $casterResolution
                && $this->memoCasterIndexVersion === $indexVersion;

            if ($casterUnchanged) {
                $this->casterMemoHits++;
            } else {
                $this->casterMemoMisses++;
                $this->classifyCasters($lightDir, $casterOrthoSize, $casterResolution);
                $this->hasCasterMemo = true;
                $this->memoLightX = $lightX;
                $this->memoLightY = $lightY;
                $this->memoLightZ = $lightZ;
                $this->memoCasterOrthoSize = $casterOrthoSize;
                $this->memoCasterResolution = $casterResolution;
                $this->memoCasterIndexVersion = $indexVersion;
            }
        } finally {
            PerfProfiler::end();
        }
    }

    /**
     * Derives beginFrame()'s inputs from a frame's command list and world:
     * the most recently queued {@see SetCamera} and {@see SetFog}, and the
     * direction of the first lit DirectionalLight (one sun drives shadows,
     * same assumption VioRenderer3D's own shadow pass makes picking "the
     * brightest directional light"). This is the entry point every
     * render-phase system should call first - see the class docblock on why
     * registration order then stops mattering.
     */
    public function beginFrameFrom(World $world, RenderCommandList $commands): void
    {
        $camera = $commands->lastOfType(SetCamera::class);
        $fog = $commands->lastOfType(SetFog::class);
        $lightDir = null;
        foreach ($world->componentPool(DirectionalLight::class) as $light) {
            if ($light instanceof DirectionalLight && $light->intensity > 0.0) {
                $lightDir = $light->direction;
                break;
            }
        }
        $this->beginFrame($camera?->viewMatrix, $camera?->projectionMatrix, $lightDir, $fog?->far);
    }

    /**
     * Below this bounding-sphere radius (world metres) a caster is excluded
     * from {@see shadowOnlyEntities()} - the plan's "Texel-Schwelle für
     * kleine Werfer". This is an absolute-size approximation, not a true
     * projected-texel measure (that needs the renderer's actual cascade size
     * and shadow-map resolution, which this engine-level service does not
     * own) - 0.0 (the default) never sieves by size. A game with its own
     * sourced threshold (e.g. a minimum caster height) sets it here instead
     * of the engine guessing one.
     */
    public function setMinShadowCasterRadius(float $metres): void
    {
        $this->minShadowCasterRadius = max(0.0, $metres);
    }

    /** Plugs in an optional occluder - see {@see OcclusionSource}. Null (default) occludes nothing. */
    public function setOcclusionSource(?OcclusionSource $source): void
    {
        $this->occlusion = $source;
    }

    /**
     * Every visible Entity-kind handle this frame, dormant ones excluded -
     * what Renderer3DSystem iterates instead of its former full MeshRenderer
     * pool scan.
     *
     * @return list<int>
     */
    public function visibleEntities(): array
    {
        $world = $this->boundWorld;
        $candidates = $this->visibleSet === null ? $this->index->allHandles() : array_keys($this->visibleSet);

        $out = [];
        foreach ($candidates as $handle) {
            $entry = $this->index->entry($handle);
            if ($entry === null || $entry['kind'] !== EntryKind::Entity) {
                continue;
            }
            if ($world !== null && $world->isDormant($handle)) {
                // setEntityDormant() fires no attach/detach event, so dormancy
                // can only be checked here, at read time - see World::setEntityDormant().
                continue;
            }
            $out[] = $handle;
        }
        return $out;
    }

    /**
     * Entity-kind handles outside $visibleEntities() (dormant excluded too)
     * that should still cast a sun shadow this frame - see the plan's
     * "Zwei Mengen: visible und shadowCasters" and the class property
     * docblock on $shadowOnlySet. Renderer3DSystem emits these as extra
     * DrawMesh(shadowOnly: true) commands.
     *
     * @return list<int>
     */
    public function shadowOnlyEntities(): array
    {
        // Dormancy is filtered fresh here, not baked into $shadowOnlySet at
        // classification time - World::setEntityDormant() fires no event and
        // does not bump SpatialIndex::version(), so a dormancy change alone
        // would not invalidate the memo (see invalidate() and the Phase 4
        // report for why this bug would otherwise surface once something
        // starts toggling dormancy during live play - visibleEntities()
        // already does this fresh filter, this method did not).
        $world = $this->boundWorld;
        if ($world === null) {
            return array_keys($this->shadowOnlySet);
        }
        $out = [];
        foreach ($this->shadowOnlySet as $handle => $_) {
            if (!$world->isDormant($handle)) {
                $out[] = $handle;
            }
        }
        return $out;
    }

    public function isVisible(int $handle): bool
    {
        if ($this->boundWorld !== null && $this->boundWorld->isDormant($handle)) {
            return false;
        }
        if ($this->visibleSet === null) {
            return $this->index->has($handle);
        }
        return isset($this->visibleSet[$handle]);
    }

    /**
     * Ad hoc visibility test against the current frame's camera, independent
     * of anything registered in the index: true when $center/$radius
     * intersects the frustum and (if given) lies within $maxDistance of the
     * camera. Always true when no camera is active this frame.
     */
    public function sees(Vec3 $center, float $radius, ?float $maxDistance = null): bool
    {
        if ($this->currentVolume === null) {
            return true;
        }
        if (!$this->currentVolume->containsSphere($center->x, $center->y, $center->z, $radius)) {
            return false;
        }
        if ($maxDistance !== null) {
            $dist = sqrt($this->currentVolume->cameraPosition->distanceSquaredTo($center)) - $radius;
            if ($dist > $maxDistance) {
                return false;
            }
        }
        return true;
    }

    /** Pure frustum test against the current frame's camera; always true when there is none. */
    public function testSphere(float $x, float $y, float $z, float $radius): bool
    {
        return $this->currentVolume === null || $this->currentVolume->containsSphere($x, $y, $z, $radius);
    }

    /**
     * Placeholder ahead of Phase 4's real `ActivityTier` enum
     * (Visible/Near/Far/Dormant, per the visibility plan). The only
     * distinction available this phase is whether the cell at (x, z) is part
     * of the current frame's view volume - true for INSIDE or INTERSECTED,
     * false for OUTSIDE or when no camera is active (nothing is excluded,
     * so trivially "in view").
     */
    public function tierAt(float $x, float $z): bool
    {
        if ($this->currentVolume === null) {
            return true;
        }
        return isset($this->visibleCellKeys[SpatialIndex::cellKeyFor($x, $z)]);
    }

    /** The camera this frame renders with, or null without one (or PHPOLYGON_NO_CULL=1). */
    public function frame(): ?ViewVolume
    {
        return $this->currentVolume;
    }

    /**
     * Direct access to the index for a producer that isn't World-driven
     * (future batches/emitters/lights, or a test). Register/update/remove
     * calls made here are reflected by the next beginFrame()/isVisible().
     */
    public function index(): SpatialIndex
    {
        return $this->index;
    }

    /**
     * Forces the next beginFrame()/beginFrameFrom() call to reclassify from
     * scratch, even if its camera/fog/light inputs and SpatialIndex::version()
     * are unchanged from the last call. World::setEntityDormant() (and
     * Phase 4's setEntityTier() moving an entity to/from Dormant) fires no
     * event and does not bump the index version - only the shadow-caster set
     * reads dormancy fresh every call ({@see shadowOnlyEntities()});
     * $visibleSet/$shadowOnlySet's own MEMBERSHIP (which entries were even
     * considered) would otherwise stay stale: a camera held still means the
     * memo would never re-run classifyCasters(), so a caster that just woke
     * from Dormant (never classified while asleep) would never appear.
     * Whoever changes an entity's dormancy/tier should call this if the
     * immediate frame needs to reflect it - {@see \PHPolygon\Activity\ActivitySystem}
     * does. A stale memo self-heals within roughly one SpatialIndex change
     * anyway (any move/attach/detach bumps the version), so this is a
     * correctness fix for a held-still camera, not a general requirement.
     */
    public function invalidate(): void
    {
        $this->hasViewMemo = false;
        $this->hasCasterMemo = false;
    }

    /**
     * Memo hit/miss counters for the view and caster classification passes -
     * diagnostics and tests confirming registration order no longer costs a
     * repeated classification (see the class docblock).
     *
     * @return array{viewHits: int, viewMisses: int, casterHits: int, casterMisses: int}
     */
    public function memoStats(): array
    {
        return [
            'viewHits' => $this->viewMemoHits,
            'viewMisses' => $this->viewMemoMisses,
            'casterHits' => $this->casterMemoHits,
            'casterMisses' => $this->casterMemoMisses,
        ];
    }

    /**
     * Binds to a World the first time this instance sees one (forWorld()
     * resolves one instance per World, so in practice this runs once).
     * Mirrors Transform3DSystem::bindToWorld(): observes attach/detach for
     * both Transform3D and MeshRenderer (either order), and bootstraps every
     * already-attached entity.
     */
    private function bindToWorld(World $world): void
    {
        if ($this->bound) {
            return;
        }
        $this->bound = true;
        $this->boundWorld = $world;

        $world->observe(
            Transform3D::class,
            function (int $id, ComponentInterface $component): void {
                $this->tryRegister($id);
            },
            function (int $id, ComponentInterface $component): void {
                $this->index->remove($id);
            },
        );
        $world->observe(
            MeshRenderer::class,
            function (int $id, ComponentInterface $component): void {
                /** @var MeshRenderer $component */
                $component->bindChangeQueue($id, $this->meshChangeQueue);
                $this->tryRegister($id);
            },
            function (int $id, ComponentInterface $component): void {
                /** @var MeshRenderer $component */
                $component->unbindChangeQueue();
                $this->index->remove($id);
            },
        );

        foreach ($world->aliveEntityIds() as $id) {
            $mesh = $world->tryGetComponent($id, MeshRenderer::class);
            if ($mesh instanceof MeshRenderer) {
                $mesh->bindChangeQueue($id, $this->meshChangeQueue);
            }
            $this->tryRegister($id);
        }
    }

    /**
     * Drains the two event sources that can change an already-registered
     * entry without an attach/detach: Transform3DSystem's accumulated moved
     * list (if a Transform3DSystem is registered on this World - see class
     * docblock) and MeshRenderer's visible/meshId change queue. Both just
     * re-derive the entry from current component state via tryRegister(),
     * which also handles an id that stopped qualifying (detached, hidden) by
     * removing it.
     */
    private function syncFromWorld(): void
    {
        $world = $this->boundWorld;
        if ($world === null) {
            return;
        }

        foreach ($world->getSystems() as $system) {
            if ($system instanceof Transform3DSystem) {
                foreach ($system->drainMovedSinceLastDrain() as $id) {
                    $this->tryRegister($id);
                }
                break;
            }
        }

        foreach ($this->meshChangeQueue->drain() as $id => $_) {
            $this->tryRegister($id);
        }
    }

    /**
     * Re-derives (or removes) the SpatialIndex entry for one entity id from
     * its current Transform3D + MeshRenderer state. Used for the initial
     * attach, for every moved/changed id, and is safe to call redundantly
     * (e.g. both components attaching the same tick) - register() replaces
     * any previous entry cleanly.
     */
    private function tryRegister(int $id): void
    {
        $world = $this->boundWorld;
        if ($world === null || !$world->isAlive($id)) {
            $this->index->remove($id);
            return;
        }
        $transform = $world->tryGetComponent($id, Transform3D::class);
        $mesh = $world->tryGetComponent($id, MeshRenderer::class);
        if (!$transform instanceof Transform3D || !$mesh instanceof MeshRenderer || !$mesh->visible) {
            $this->index->remove($id);
            return;
        }

        $sphere = MeshBounds::worldSphere($mesh->meshId, $transform->worldMatrix);
        if ($sphere === null) {
            // Mesh not in MeshRegistry (yet) - unknown extent, never culled.
            // A later meshId change (the hook fires again) re-derives it once
            // the mesh is known.
            $this->index->register($id, 0.0, 0.0, 0.0, 0.0, unbounded: true, castsShadow: $mesh->castShadows);
            return;
        }
        $this->index->register(
            $id,
            $sphere['cx'],
            $sphere['cy'],
            $sphere['cz'],
            $sphere['radius'],
            castsShadow: $mesh->castShadows,
        );
    }

    /**
     * The real work beginFrame() memoizes: cell classification against the
     * camera frustum, the fog-far distance cap, and (gated on $this->occlusion
     * being set - the common case is not) an occluder test. See the class
     * docblock on when this actually runs vs. is skipped as a memo hit.
     */
    private function classifyView(?Mat4 $view, ?Mat4 $projection, bool $noCull, ?float $fogFar): void
    {
        if ($view === null || $projection === null || $noCull) {
            $this->currentVolume = null;
            $this->visibleSet = null;
            $this->visibleCellKeys = [];
            return;
        }

        $planes = Frustum::planes($projection, $view);
        $eye = $view->inverse()->getTranslation();
        $this->currentVolume = new ViewVolume($view, $projection, $planes, $eye);

        [$insideCells, $intersectedCells] = $this->index->classify($planes);
        $this->visibleCellKeys = $insideCells + $intersectedCells;

        $visible = [];
        foreach ($this->index->unboundedHandles() as $handle) {
            $visible[$handle] = true;
        }
        foreach ($this->index->alwaysVisibleHandles() as $handle) {
            $visible[$handle] = true;
        }
        foreach ($insideCells as $cellKey => $_) {
            foreach ($this->index->handlesInCell($cellKey) as $handle) {
                if ($fogFar !== null || $this->occlusion !== null) {
                    $entry = $this->index->entry($handle);
                    if ($entry !== null && $this->isCulledByFogOrOcclusion($entry, $eye, $fogFar)) {
                        continue;
                    }
                }
                $visible[$handle] = true;
            }
        }
        foreach ($intersectedCells as $cellKey => $_) {
            foreach ($this->index->handlesInCell($cellKey) as $handle) {
                $entry = $this->index->entry($handle);
                if ($entry === null || !$this->currentVolume->containsSphere($entry['x'], $entry['y'], $entry['z'], $entry['radius'])) {
                    continue;
                }
                if ($this->isCulledByFogOrOcclusion($entry, $eye, $fogFar)) {
                    continue;
                }
                $visible[$handle] = true;
            }
        }
        $this->visibleSet = $visible;
    }

    /**
     * @param array{x:float,y:float,z:float,radius:float,unbounded:bool} $entry
     */
    private function isCulledByFogOrOcclusion(array $entry, Vec3 $eye, ?float $fogFar): bool
    {
        if ($entry['unbounded']) {
            return false; // unknown extent - never culled by distance or occlusion either
        }
        if ($fogFar !== null) {
            $dx = $entry['x'] - $eye->x;
            $dy = $entry['y'] - $eye->y;
            $dz = $entry['z'] - $eye->z;
            if (sqrt($dx * $dx + $dy * $dy + $dz * $dz) - $entry['radius'] > $fogFar) {
                return true;
            }
        }
        if ($this->occlusion !== null && $this->occlusion->occludes($entry['x'], $entry['y'], $entry['z'], $entry['radius'])) {
            return true;
        }
        return false;
    }

    /**
     * The real work beginFrame() memoizes for shadow casters: classify the
     * index against the sun's caster prism (reusing SpatialIndex::classify()
     * exactly like the camera - the method takes arbitrary planes), then
     * keep only entries not already in $visibleSet, that cast a shadow, and
     * that clear {@see $minShadowCasterRadius}. The fog cap never applies
     * here - a caster beyond the fog still shadows what is in front of it.
     */
    private function classifyCasters(?Vec3 $lightDir, float $casterOrthoSize, int $casterResolution): void
    {
        if ($lightDir === null || $this->visibleSet === null) {
            // No sun this frame, or nothing is being culled anyway (no
            // camera / NO_CULL) - there is no "off screen but shadow-worthy"
            // distinction to make.
            $this->shadowOnlySet = [];
            return;
        }

        $shadowCenter = $this->currentVolume?->cameraPosition;
        $planes = SunCasterVolume::planes($lightDir, $shadowCenter, $casterOrthoSize, $casterResolution);
        if ($planes === null) {
            $this->shadowOnlySet = [];
            return;
        }

        [$insideCells, $intersectedCells] = $this->index->classify($planes);
        $casters = [];
        foreach ($insideCells as $cellKey => $_) {
            foreach ($this->index->handlesInCell($cellKey) as $handle) {
                $this->collectCasterIfEligible($handle, $casters);
            }
        }
        foreach ($intersectedCells as $cellKey => $_) {
            foreach ($this->index->handlesInCell($cellKey) as $handle) {
                $entry = $this->index->entry($handle);
                if ($entry !== null
                    && !Frustum::sphereOutside($planes, $entry['x'], $entry['y'], $entry['z'], $entry['radius'])
                ) {
                    $this->collectCasterIfEligible($handle, $casters);
                }
            }
        }
        $this->shadowOnlySet = $casters;
    }

    /** @param array<int, true> $casters */
    private function collectCasterIfEligible(int $handle, array &$casters): void
    {
        if (isset($this->visibleSet[$handle])) {
            return; // already drawn in the main pass - no extra shadow-only draw needed
        }
        $entry = $this->index->entry($handle);
        if ($entry === null || $entry['kind'] !== EntryKind::Entity || !$entry['castsShadow']) {
            return;
        }
        if (!$entry['unbounded'] && $this->minShadowCasterRadius > 0.0 && $entry['radius'] < $this->minShadowCasterRadius) {
            return;
        }
        if ($this->boundWorld !== null && $this->boundWorld->isDormant($handle)) {
            return;
        }
        $casters[$handle] = true;
    }
}
