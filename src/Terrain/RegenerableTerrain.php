<?php

declare(strict_types=1);

namespace PHPolygon\Terrain;

use PHPolygon\ECS\World;

/**
 * Contract for a procedurally-generated terrain component that can rebuild
 * its own meshes and colliders from its current (editable) configuration.
 *
 * {@see \PHPolygon\System\TerrainRegenerationSystem} watches components that
 * implement this interface and calls {@see rebuild()} whenever their editable
 * `#[Property]` values change — so terrain edited in the editor (or from code)
 * updates live, without a scene reload.
 *
 * The engine deliberately owns only the *when* (change detection + timing);
 * the *how* (which meshes, which height function, which colliders) stays in
 * the implementing component, so the system is not tied to any one game or
 * terrain shape.
 */
interface RegenerableTerrain
{
    /**
     * Regenerate this terrain's meshes and colliders from the component's
     * current configuration.
     *
     * Re-registering meshes under their existing ids (via MeshRegistry) is
     * enough for the renderer to pick them up — it re-uploads GPU buffers on
     * a version change. $entityId is the entity carrying this component, so
     * the implementation can locate its own Transform, sibling collider
     * entities, etc. through $world.
     */
    public function rebuild(World $world, int $entityId): void;
}
