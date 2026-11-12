<?php

declare(strict_types=1);

namespace PHPolygon\Physics;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * Pose of a thin sloped box for a walkable ramp, kerb or wedge: the engine has no
 * wedge shape, a rotated BoxCollider3D (solid {@see OrientedBox}, walked on within
 * the controller's slope limit) is the collider for it. This turns "the top surface
 * runs from this low edge to that high edge" into the entity transform and the
 * collider size, so a game does not hand-roll the pitch/yaw maths.
 *
 * Pure maths, no static state.
 */
final class RampPose
{
    /**
     * @param Vec3  $lowEdge   midpoint of the walking surface's low edge (world/parent space)
     * @param Vec3  $highEdge  midpoint of the walking surface's high edge
     * @param float $width     extent across the slope
     * @param float $thickness box depth below the walking surface
     * @return array{position: Vec3, rotation: Quaternion, size: Vec3}
     *   position/rotation for the entity's transform (scale 1), size for
     *   BoxCollider3D::$size (x = width, y = thickness, z = slope length)
     */
    public static function fromEdges(Vec3 $lowEdge, Vec3 $highEdge, float $width, float $thickness): array
    {
        $dx = $highEdge->x - $lowEdge->x;
        $dy = $highEdge->y - $lowEdge->y;
        $dz = $highEdge->z - $lowEdge->z;
        $length = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
        if ($length < 1e-9) {
            throw new \InvalidArgumentException('Ramp edges coincide');
        }
        $zx = $dx / $length;
        $zy = $dy / $length;
        $zz = $dz / $length;
        // Up axis: world up with its component along the slope removed.
        $ux = -$zy * $zx;
        $uy = 1.0 - $zy * $zy;
        $uz = -$zy * $zz;
        $ul = sqrt($ux * $ux + $uy * $uy + $uz * $uz);
        if ($ul < 1e-6) {
            throw new \InvalidArgumentException('A vertical ramp has no walking surface');
        }
        $ux /= $ul;
        $uy /= $ul;
        $uz /= $ul;
        // Right axis = up x forward.
        $rx = $uy * $zz - $uz * $zy;
        $ry = $uz * $zx - $ux * $zz;
        $rz = $ux * $zy - $uy * $zx;

        $rotation = Quaternion::fromRotationMatrix(new Mat4([
            $rx, $ry, $rz, 0.0,
            $ux, $uy, $uz, 0.0,
            $zx, $zy, $zz, 0.0,
            0.0, 0.0, 0.0, 1.0,
        ]))->normalize();
        $half = $thickness / 2.0;
        $position = new Vec3(
            ($lowEdge->x + $highEdge->x) / 2.0 - $ux * $half,
            ($lowEdge->y + $highEdge->y) / 2.0 - $uy * $half,
            ($lowEdge->z + $highEdge->z) / 2.0 - $uz * $half,
        );
        return ['position' => $position, 'rotation' => $rotation, 'size' => new Vec3($width, $thickness, $length)];
    }
}
