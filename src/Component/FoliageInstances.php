<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Range;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;
use PHPolygon\Vegetation\GrowthStage;

/**
 * Many plants of one {@see \PHPolygon\Vegetation\PlantVariant}, drawn
 * instanced by {@see \PHPolygon\System\FoliageInstanceSystem}: per frame a
 * few draws per (growth stage, LOD, foliage tint, shadow) group instead of one
 * entity per plant.
 *
 * Every instance has a world position (trunk foot), a yaw, a uniform scale, a
 * {@see GrowthStage} and a leaf scale (0..1+, scales the foliage around the
 * crown centre - tween it after a stage change so the crown grows visibly).
 * Change instances through the methods: they bump {@see $version}, which is
 * how the system notices edits.
 *
 * Distances are camera distances in metres. Defaults: LOD0 up to 40 m, LOD1 up
 * to 120 m, LOD2 up to 250 m, crossed-plane impostor beyond; shadows from
 * LOD0/LOD1 only and only up to 80 m.
 */
#[Serializable]
#[Category('Rendering')]
class FoliageInstances extends AbstractComponent
{
    /** Id of the variant in {@see \PHPolygon\Vegetation\PlantLibrary}. */
    #[Property]
    public string $variant;

    /**
     * One row per plant: [x, y, z, yaw, scale, stage, leafScale].
     *
     * @var list<array{0: float, 1: float, 2: float, 3: float, 4: float, 5: int, 6: float}>
     */
    #[Property]
    public array $instances = [];

    #[Property]
    public float $lod1Distance = 40.0;

    #[Property]
    public float $lod2Distance = 120.0;

    #[Property]
    public float $impostorDistance = 250.0;

    /** Plants farther than this are not drawn (0 = no limit). */
    #[Property]
    public float $maxDistance = 0.0;

    /** Plants within this distance (and at LOD0/LOD1) cast shadows. */
    #[Property]
    public float $shadowDistance = 80.0;

    #[Property]
    public bool $castShadows = true;

    /**
     * Glow of the accent part (glowing blossoms), 0..1: scales the variant's
     * accent emission. The game drives it (night, power).
     */
    #[Property]
    #[Range(0.0, 1.0)]
    public float $glow = 0.0;

    /** Bumped by every edit method; the system rebuilds its batches when it changes. */
    #[Hidden]
    public int $version = 0;

    public function __construct(string $variant = '')
    {
        $this->variant = $variant;
    }

    /** Add a plant; returns its index. */
    public function add(Vec3 $position, float $yaw = 0.0, float $scale = 1.0, GrowthStage $stage = GrowthStage::Full, float $leafScale = 1.0): int
    {
        $this->instances[] = [$position->x, $position->y, $position->z, $yaw, $scale, $stage->value, $leafScale];
        $this->version++;
        return count($this->instances) - 1;
    }

    public function count(): int
    {
        return count($this->instances);
    }

    public function position(int $index): Vec3
    {
        $row = $this->row($index);
        return new Vec3($row[0], $row[1], $row[2]);
    }

    public function stage(int $index): GrowthStage
    {
        return GrowthStage::from($this->row($index)[5]);
    }

    public function setStage(int $index, GrowthStage $stage): void
    {
        $this->row($index);
        if ($this->instances[$index][5] !== $stage->value) {
            $this->instances[$index][5] = $stage->value;
            $this->version++;
        }
    }

    /** Set every plant to one stage (e.g. a whole area's rebuild level). */
    public function setStageAll(GrowthStage $stage): void
    {
        $changed = false;
        foreach ($this->instances as $i => $row) {
            if ($row[5] !== $stage->value) {
                $this->instances[$i][5] = $stage->value;
                $changed = true;
            }
        }
        if ($changed) {
            $this->version++;
        }
    }

    /** Foliage scale around the crown centre (tween 0.6 -> 1 after a stage change). */
    public function setLeafScale(int $index, float $leafScale): void
    {
        $this->row($index);
        $leafScale = max(0.0, $leafScale);
        if ($this->instances[$index][6] !== $leafScale) {
            $this->instances[$index][6] = $leafScale;
            $this->version++;
        }
    }

    public function setLeafScaleAll(float $leafScale): void
    {
        $leafScale = max(0.0, $leafScale);
        $changed = false;
        foreach ($this->instances as $i => $row) {
            if ($row[6] !== $leafScale) {
                $this->instances[$i][6] = $leafScale;
                $changed = true;
            }
        }
        if ($changed) {
            $this->version++;
        }
    }

    public function remove(int $index): void
    {
        $this->row($index);
        array_splice($this->instances, $index, 1);
        $this->version++;
    }

    public function clear(): void
    {
        if ($this->instances !== []) {
            $this->instances = [];
            $this->version++;
        }
    }

    /** @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: int, 6: float} */
    private function row(int $index): array
    {
        if (!isset($this->instances[$index])) {
            throw new \OutOfRangeException("FoliageInstances: no instance {$index}");
        }
        return $this->instances[$index];
    }
}
