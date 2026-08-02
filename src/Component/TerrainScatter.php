<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\ECS\World;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Terrain\RegenerableTerrain;
use PHPolygon\Terrain\ScatterRules;
use PHPolygon\Terrain\TerrainScatterGenerator;

/**
 * Objects scattered over a sibling {@see Terrain} — trees, grass, rocks.
 *
 * Sits on the same entity as the terrain it decorates and drives rendering
 * through {@see InstancedTerrain}: one child entity per scatter set, each
 * holding the generated transforms for a single mesh, which
 * {@see \PHPolygon\System\InstancedTerrainSystem} batches into one instanced
 * draw call per material.
 *
 * What is stored is a painted density map plus rules and a seed, never the
 * placements themselves — see {@see TerrainScatterGenerator} for why that
 * matters. Regeneration is driven by
 * {@see \PHPolygon\System\TerrainRegenerationSystem}, the same way terrain
 * geometry is:
 *
 *   $world->addSystem(new TerrainRegenerationSystem([Terrain::class, TerrainScatter::class]));
 *
 * A set is a plain array so it round-trips through the standard component
 * pipeline, mirroring the editor's authoring format one-to-one:
 *
 *   [
 *     'id' => 'pines', 'meshId' => 'pine', 'materialId' => 'bark',
 *     'seed' => 1337, 'density' => 0.05, 'densityMap' => '<base64 bytes>',
 *     'minHeight' => 0.0, 'maxHeight' => 1.0,
 *     'minSlope' => 0.0, 'maxSlope' => 30.0,
 *     'minScale' => 0.8, 'maxScale' => 1.2,
 *     'alignToNormal' => false, 'randomYaw' => 360.0,
 *   ]
 */
#[Serializable]
#[Category('Terrain')]
class TerrainScatter extends AbstractComponent implements RegenerableTerrain
{
    /** @var list<array<string, mixed>> */
    #[Property]
    public array $sets = [];

    /**
     * Per-set cap on generated instances, so a maxed-out density brush cannot
     * stall the frame. Exceeding it truncates rather than thins, keeping the
     * shortfall visible.
     */
    #[Property]
    public int $instanceLimit = TerrainScatterGenerator::DEFAULT_LIMIT;

    /**
     * Entities holding the generated instances. Runtime-only: they are derived
     * from the density maps and recreated on rebuild, so serialising them would
     * persist generated data into the scene document.
     *
     * @var list<int>
     */
    #[Hidden]
    public array $instanceEntityIds = [];

    public function rebuild(World $world, int $entityId): void
    {
        $terrain = $world->tryGetComponent($entityId, Terrain::class);
        if (! $terrain instanceof Terrain) {
            // Nothing to scatter onto; drop any instances from a previous
            // configuration rather than leaving them floating.
            $this->destroyInstanceEntities($world);

            return;
        }

        $heightmap = $terrain->toHeightmap();
        $generator = new TerrainScatterGenerator;

        $transform = $world->tryGetComponent($entityId, Transform3D::class);
        $origin = $transform instanceof Transform3D ? $transform->getWorldPosition() : Vec3::zero();

        $this->destroyInstanceEntities($world);
        $instanceEntityIds = [];

        foreach ($this->sets as $index => $set) {
            $meshId = is_string($set['meshId'] ?? null) ? $set['meshId'] : '';
            if ($meshId === '') {
                // A set with no mesh yet is a normal authoring state, not an
                // error — it simply renders nothing.
                continue;
            }

            $instances = $generator->generate(
                $heightmap,
                is_string($set['densityMap'] ?? null) ? $set['densityMap'] : '',
                self::rulesFrom($set),
                max(1, $this->instanceLimit),
            );

            if ($instances === []) {
                continue;
            }

            $matrices = [];
            foreach ($instances as $instance) {
                // Instances are generated in terrain-local space, but
                // InstancedTerrainSystem submits these matrices as-is, so the
                // terrain's world position has to be folded in here.
                $matrices[] = Mat4::trs(
                    new Vec3(
                        $origin->x + $instance->position->x,
                        $origin->y + $instance->position->y,
                        $origin->z + $instance->position->z,
                    ),
                    Quaternion::fromEuler(
                        $instance->rotation->x,
                        $instance->rotation->y,
                        $instance->rotation->z,
                    ),
                    new Vec3($instance->scale, $instance->scale, $instance->scale),
                );
            }

            $instanced = new InstancedTerrain();
            $instanced->meshId = $meshId;
            $instanced->matricesByMaterial = [
                (is_string($set['materialId'] ?? null) ? $set['materialId'] : '') => $matrices,
            ];

            $entity = $world->createEntity();
            $entity->attach($instanced);
            $entity->attach(new NameTag(
                'scatter_'.(is_string($set['id'] ?? null) && $set['id'] !== '' ? $set['id'] : (string) $index)
            ));

            $instanceEntityIds[] = $entity->id;
        }

        $this->instanceEntityIds = $instanceEntityIds;
    }

    /** @param array<string, mixed> $set */
    private static function rulesFrom(array $set): ScatterRules
    {
        return new ScatterRules(
            seed: self::intOr($set['seed'] ?? null, 1337),
            densityPerUnit: self::floatOr($set['density'] ?? null, 0.05),
            minHeight: self::floatOr($set['minHeight'] ?? null, 0.0),
            maxHeight: self::floatOr($set['maxHeight'] ?? null, 1.0),
            minSlope: self::floatOr($set['minSlope'] ?? null, 0.0),
            maxSlope: self::floatOr($set['maxSlope'] ?? null, 30.0),
            minScale: self::floatOr($set['minScale'] ?? null, 0.8),
            maxScale: self::floatOr($set['maxScale'] ?? null, 1.2),
            alignToNormal: is_bool($set['alignToNormal'] ?? null) ? $set['alignToNormal'] : false,
            randomYaw: self::floatOr($set['randomYaw'] ?? null, 360.0),
        );
    }

    private function destroyInstanceEntities(World $world): void
    {
        foreach ($this->instanceEntityIds as $id) {
            if ($world->isAlive($id)) {
                $world->destroyEntity($id);
            }
        }
        $this->instanceEntityIds = [];
    }

    private static function intOr(mixed $value, int $default): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    private static function floatOr(mixed $value, float $default): float
    {
        return is_float($value) || is_int($value) ? (float) $value : (is_numeric($value) ? (float) $value : $default);
    }
}
