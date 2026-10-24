<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\FoliageInstances;
use PHPolygon\Component\Transform3D;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\SceneBuilder;

/**
 * Colliders for instanced plants: one static box entity per plant whose
 * variant collides ({@see PlantVariant::collider()}) - a slim box the width of
 * the trunk for trees and palms, a full-height box for blocking hedges,
 * nothing for bushes, crowns, fronds and leaves. Build them once after
 * placement; they follow the instances' stage at that moment.
 */
final class FoliageColliders
{
    /**
     * Declare the colliders of every plant in `$foliage` on a scene builder.
     * Returns the number of collider entities declared.
     */
    public static function declare(SceneBuilder $builder, string $prefix, FoliageInstances $foliage): int
    {
        $variant = PlantLibrary::get($foliage->variant);
        if ($variant === null) {
            return 0;
        }
        $n = 0;
        foreach ($foliage->instances as $i => $row) {
            $box = self::box($variant, $row);
            if ($box === null) {
                continue;
            }
            $builder->entity("{$prefix}_Collider_{$i}")
                ->with(new Transform3D(position: $box['position'], rotation: $box['rotation']))
                ->with(new BoxCollider3D(size: $box['size'], isStatic: true));
            $n++;
        }
        return $n;
    }

    /**
     * World-space collider of one instance row, or null.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: int, 6: float} $row
     * @return array{position: Vec3, rotation: Quaternion, size: Vec3}|null
     */
    public static function box(PlantVariant $variant, array $row): ?array
    {
        [$x, $y, $z, $yaw, $scale, $stage] = $row;
        $local = $variant->collider(GrowthStage::tryFrom($stage) ?? GrowthStage::Full);
        if ($local === null) {
            return null;
        }
        $rotation = Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), $yaw);
        $offset = $rotation->rotateVec3($local['center']->mul($scale));
        return [
            'position' => new Vec3($x + $offset->x, $y + $offset->y, $z + $offset->z),
            'rotation' => $variant->spec->form === PlantForm::Hedge ? $rotation : Quaternion::identity(),
            'size' => $local['size']->mul($scale),
        ];
    }
}
