<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Vec3;
use PHPolygon\Terrain\HeightmapData;
use PHPolygon\Terrain\RegenerableTerrain;
use PHPolygon\Terrain\TerrainMeshBuilder;

/**
 * A heightmap terrain: a grid of height samples that rebuilds itself into
 * chunked meshes and a matching collider.
 *
 * The heightmap lives *in the component* as a base64 payload rather than in an
 * external file, so it serialises through the standard component pipeline and
 * round-trips inside a scene or prefab document with no new asset-loading path.
 * That follows the same choice {@see RawMesh} makes for baked geometry, and
 * keeps the engine free of a runtime terrain file format.
 *
 * Rendering is delegated to child entities — one per chunk, each carrying a
 * MeshRenderer pointing at a registry id owned by this terrain. They are
 * created by {@see rebuild()} and are deliberately *not* serialised: they are
 * derived data, regenerated on load from the heightmap.
 *
 * Live editing works through {@see \PHPolygon\System\TerrainRegenerationSystem},
 * which watches this component's `#[Property]` values and calls
 * {@see rebuild()} once they settle:
 *
 *   $world->addSystem(new TerrainRegenerationSystem([Terrain::class]));
 */
#[Serializable]
#[Category('Terrain')]
class Terrain extends AbstractComponent implements RegenerableTerrain
{
    /** Sample columns along X. Conventionally 2^n + 1 so chunks tile evenly. */
    #[Property]
    public int $gridWidth = 129;

    /** Sample rows along Z. Conventionally 2^n + 1 so chunks tile evenly. */
    #[Property]
    public int $gridDepth = 129;

    /** World-space extent along X; the grid is centred on the entity origin. */
    #[Property]
    public float $sizeX = 256.0;

    /** World-space extent along Z; the grid is centred on the entity origin. */
    #[Property]
    public float $sizeZ = 256.0;

    /** World Y a normalised sample of 0 maps to. */
    #[Property]
    public float $minHeight = 0.0;

    /**
     * World Y a normalised sample of 1 maps to. Editing this rescales the
     * terrain without resampling, because the payload is stored normalised.
     */
    #[Property]
    public float $maxHeight = 50.0;

    /**
     * Base64 of little-endian uint16 normalised height samples, row-major with
     * Z as the outer axis. Empty means flat.
     */
    #[Property(editorHint: 'heightmap')]
    public string $heights = '';

    /** Quads per chunk edge. Smaller chunks cull better but cost more draw calls. */
    #[Property]
    public int $chunkSize = 32;

    /** MeshRegistry id prefix; each chunk publishes as "<prefix>_c<x>_<z>". */
    #[Property]
    public string $meshIdPrefix = 'terrain';

    /** Material applied to every chunk. */
    #[Property(editorHint: 'asset:material')]
    public string $materialId = '';

    /** Whether chunk meshes cast shadows. */
    #[Property]
    public bool $castShadows = true;

    /**
     * Keep a sibling {@see HeightmapCollider3D} in sync on every rebuild. The
     * collider's own height data is not serialised, so this is also what
     * restores it after a scene load.
     */
    #[Property]
    public bool $generateCollider = true;

    /**
     * Chunk entities this terrain owns. Runtime-only: they are derived from the
     * heightmap and recreated by {@see rebuild()}, so serialising them would
     * persist duplicates of generated data into the scene document.
     *
     * @var list<int>
     */
    #[Hidden]
    public array $chunkEntityIds = [];

    /** Decode the stored payload into a usable heightmap. */
    public function toHeightmap(): HeightmapData
    {
        return HeightmapData::decode(
            $this->heights,
            $this->gridWidth,
            $this->gridDepth,
            $this->sizeX,
            $this->sizeZ,
            $this->minHeight,
            $this->maxHeight,
        );
    }

    /** Replace the heightmap, adopting its grid and world mapping. */
    public function setHeightmap(HeightmapData $heightmap): void
    {
        $this->gridWidth = $heightmap->gridWidth;
        $this->gridDepth = $heightmap->gridDepth;
        $this->sizeX = $heightmap->sizeX;
        $this->sizeZ = $heightmap->sizeZ;
        $this->minHeight = $heightmap->minHeight;
        $this->maxHeight = $heightmap->maxHeight;
        $this->heights = $heightmap->encode();
    }

    public function rebuild(World $world, int $entityId): void
    {
        $heightmap = $this->toHeightmap();

        $this->rebuildChunks($world, $entityId, $heightmap);

        if ($this->generateCollider) {
            $this->rebuildCollider($world, $entityId, $heightmap);
        }
    }

    /**
     * Regenerate chunk meshes and the child entities that render them.
     *
     * Re-registering a mesh under its existing id is enough for the renderer to
     * notice — MeshRegistry bumps a per-id version that invalidates cached GPU
     * buffers — so when the chunk *count* is unchanged (the common case while
     * sculpting) the child entities are reused untouched and only geometry is
     * re-uploaded.
     */
    private function rebuildChunks(World $world, int $entityId, HeightmapData $heightmap): void
    {
        $chunks = (new TerrainMeshBuilder())->buildChunks($heightmap, $this->chunkSize);

        foreach ($chunks as $chunk) {
            MeshRegistry::register($chunk->meshId($this->meshIdPrefix), $chunk->mesh);
        }

        $alive = array_values(array_filter(
            $this->chunkEntityIds,
            static fn (int $id): bool => $world->isAlive($id),
        ));

        if (count($alive) === count($chunks)) {
            // Same layout as last time — only the material/shadow settings can
            // have changed, so refresh those and leave the entities in place.
            foreach ($alive as $index => $chunkEntityId) {
                $renderer = $world->tryGetComponent($chunkEntityId, MeshRenderer::class);
                if ($renderer instanceof MeshRenderer) {
                    $renderer->meshId = $chunks[$index]->meshId($this->meshIdPrefix);
                    $renderer->materialId = $this->materialId;
                    $renderer->castShadows = $this->castShadows;
                }
            }
            $this->chunkEntityIds = $alive;

            return;
        }

        // Chunk layout changed (resolution or chunk size edited): drop the old
        // children and rebuild the set.
        foreach ($alive as $chunkEntityId) {
            $world->destroyEntity($chunkEntityId);
        }

        $parentTransform = $world->tryGetComponent($entityId, Transform3D::class);
        $chunkEntityIds = [];

        foreach ($chunks as $chunk) {
            $entity = $world->createEntity();

            // Chunk vertices are already in the terrain's local space, so the
            // child sits at the parent's origin and inherits its transform.
            $transform = new Transform3D(Vec3::zero(), null, null, $entityId);
            $entity->attach($transform);
            $entity->attach(new NameTag("{$this->meshIdPrefix}_chunk_{$chunk->chunkX}_{$chunk->chunkZ}"));
            $entity->attach(new MeshRenderer(
                $chunk->meshId($this->meshIdPrefix),
                $this->materialId,
                $this->castShadows,
            ));

            if ($parentTransform instanceof Transform3D) {
                $parentTransform->addChild($transform, $entity->id, $entityId);
            }

            $chunkEntityIds[] = $entity->id;
        }

        $this->chunkEntityIds = $chunkEntityIds;
    }

    /**
     * Sync a sibling {@see HeightmapCollider3D} to the current heightmap.
     *
     * The collider works in world space while the heightmap is local to the
     * entity, so the terrain's world position is folded into both the bounds
     * and the sampled height.
     */
    private function rebuildCollider(World $world, int $entityId, HeightmapData $heightmap): void
    {
        $collider = $world->tryGetComponent($entityId, HeightmapCollider3D::class);
        if (! $collider instanceof HeightmapCollider3D) {
            return;
        }

        $transform = $world->tryGetComponent($entityId, Transform3D::class);
        $origin = $transform instanceof Transform3D ? $transform->getWorldPosition() : Vec3::zero();

        $collider->gridWidth = $heightmap->gridWidth;
        $collider->gridDepth = $heightmap->gridDepth;
        $collider->worldMinX = $origin->x - $heightmap->sizeX * 0.5;
        $collider->worldMaxX = $origin->x + $heightmap->sizeX * 0.5;
        $collider->worldMinZ = $origin->z - $heightmap->sizeZ * 0.5;
        $collider->worldMaxZ = $origin->z + $heightmap->sizeZ * 0.5;

        $collider->populateFromFunction(
            static fn (float $wx, float $wz): float => $origin->y + $heightmap->heightAtWorld($wx - $origin->x, $wz - $origin->z),
        );
    }
}
