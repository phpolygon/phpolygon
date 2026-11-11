<?php

declare(strict_types=1);

namespace PHPolygon\Visibility;

use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\MeshRendererChangeQueue;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\ComponentInterface;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Frustum;
use PHPolygon\Rendering\MeshBounds;
use PHPolygon\Rendering\ViewVolume;
use PHPolygon\System\Transform3DSystem;

/**
 * The engine's single answer to "what is in the view this frame" - built for
 * the plan "die Engine prueft selbst, was im Sichtfeld ist" (2026-11-11).
 * Owns a {@see SpatialIndex} of every MeshRenderer+Transform3D pair in a
 * World and classifies it against the current camera once per frame via
 * {@see beginFrame()}.
 *
 * One instance per World, resolved through {@see forWorld()} rather than
 * constructed directly - both Engine (`$engine->visibility`) and
 * Renderer3DSystem (which many tests construct standalone, without an
 * Engine) resolve the SAME instance for a given World this way, with no
 * required constructor wiring on either side.
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

    public function __construct()
    {
        $this->index = new SpatialIndex();
        $this->meshChangeQueue = new MeshRendererChangeQueue();
    }

    /**
     * Classifies the index against this frame's camera. Pass null for
     * $view/$projection when no camera is active (or PHPOLYGON_NO_CULL=1 is
     * set) - nothing is culled then, matching the engine's existing
     * diagnostic convention.
     *
     * $lightDir is accepted but unused by Phase 2 - Phase 3 will use it to
     * classify shadow casters outside the view volume (the largest cascade's
     * own frustum, not the camera's).
     */
    public function beginFrame(?Mat4 $view, ?Mat4 $projection, ?Vec3 $lightDir = null): void
    {
        $this->syncFromWorld();

        if ($view === null || $projection === null || getenv('PHPOLYGON_NO_CULL') === '1') {
            $this->currentVolume = null;
            $this->visibleSet = null;
            $this->visibleCellKeys = [];
            return;
        }

        $planes = Frustum::planes($projection, $view);
        $this->currentVolume = new ViewVolume($view, $projection, $planes, $view->inverse()->getTranslation());

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
                $visible[$handle] = true;
            }
        }
        foreach ($intersectedCells as $cellKey => $_) {
            foreach ($this->index->handlesInCell($cellKey) as $handle) {
                $entry = $this->index->entry($handle);
                if ($entry !== null
                    && $this->currentVolume->containsSphere($entry['x'], $entry['y'], $entry['z'], $entry['radius'])
                ) {
                    $visible[$handle] = true;
                }
            }
        }
        $this->visibleSet = $visible;
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
}
