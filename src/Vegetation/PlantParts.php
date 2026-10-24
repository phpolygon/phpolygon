<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Math\Vec3;

/**
 * One generated plant at one growth stage and level of detail: up to three
 * meshes that are drawn with three materials, plus the measurements that
 * placement, colliders and footprints need.
 *
 * - `wood`:   trunk, branches, twigs, palm trunk, stake (bark material)
 * - `leaf`:   foliage clumps, palm fronds, needles, hedge (foliage material)
 * - `accent`: blossoms, fruit, glowing flowers (accent material); empty unless
 *             the stage is {@see GrowthStage::Bloom}
 *
 * Every mesh is in the plant's local space: trunk foot at the origin, +Y up,
 * counter-clockwise winding seen from outside with normals agreeing.
 */
final readonly class PlantParts
{
    public function __construct(
        public MeshData $wood,
        public MeshData $leaf,
        public MeshData $accent,
        /** Highest point of the plant (local y). */
        public float $height,
        /** Centre of the foliage (local); the trunk foot when there is none. */
        public Vec3 $crownCenter,
        /** Horizontal radius of the foliage around the trunk axis. */
        public float $crownRadius,
        /** Trunk radius above the root flare (0 for bushes and hedges). */
        public float $trunkRadius,
        /** Height of the solid trunk a collider should cover (0 = no trunk). */
        public float $trunkHeight,
    ) {}

    public function triangleCount(): int
    {
        return $this->wood->triangleCount() + $this->leaf->triangleCount() + $this->accent->triangleCount();
    }

    public function part(PlantPart $part): MeshData
    {
        return match ($part) {
            PlantPart::Wood => $this->wood,
            PlantPart::Leaf => $this->leaf,
            PlantPart::Accent => $this->accent,
        };
    }
}
