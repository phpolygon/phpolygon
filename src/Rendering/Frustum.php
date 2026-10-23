<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat4;

/**
 * The six planes of a camera's view volume, for CPU-side visibility tests.
 *
 * Planes are [nx, ny, nz, d], normalised and pointing inward: a point p is
 * inside a plane when nx*px + ny*py + nz*pz + d >= 0. Extracted from
 * projection * view (Gribb-Hartmann).
 */
final class Frustum
{
    private function __construct() {}

    /**
     * @return list<array{0: float, 1: float, 2: float, 3: float}> left, right, bottom, top, near, far
     */
    public static function planes(Mat4 $projection, Mat4 $view): array
    {
        // viewProj = projection * view (column-major Mat4::multiply applies
        // right-to-left when transforming a column vector).
        $vp = $projection->multiply($view);

        $r0 = [$vp->get(0, 0), $vp->get(0, 1), $vp->get(0, 2), $vp->get(0, 3)];
        $r1 = [$vp->get(1, 0), $vp->get(1, 1), $vp->get(1, 2), $vp->get(1, 3)];
        $r2 = [$vp->get(2, 0), $vp->get(2, 1), $vp->get(2, 2), $vp->get(2, 3)];
        $r3 = [$vp->get(3, 0), $vp->get(3, 1), $vp->get(3, 2), $vp->get(3, 3)];

        $planes = [
            [$r3[0] + $r0[0], $r3[1] + $r0[1], $r3[2] + $r0[2], $r3[3] + $r0[3]], // left
            [$r3[0] - $r0[0], $r3[1] - $r0[1], $r3[2] - $r0[2], $r3[3] - $r0[3]], // right
            [$r3[0] + $r1[0], $r3[1] + $r1[1], $r3[2] + $r1[2], $r3[3] + $r1[3]], // bottom
            [$r3[0] - $r1[0], $r3[1] - $r1[1], $r3[2] - $r1[2], $r3[3] - $r1[3]], // top
            [$r3[0] + $r2[0], $r3[1] + $r2[1], $r3[2] + $r2[2], $r3[3] + $r2[3]], // near
            [$r3[0] - $r2[0], $r3[1] - $r2[1], $r3[2] - $r2[2], $r3[3] - $r2[3]], // far
        ];

        foreach ($planes as $i => $p) {
            $len = sqrt($p[0] * $p[0] + $p[1] * $p[1] + $p[2] * $p[2]);
            if ($len > 1e-6) {
                $planes[$i] = [$p[0] / $len, $p[1] / $len, $p[2] / $len, $p[3] / $len];
            }
        }

        return $planes;
    }

    /**
     * True when the sphere lies entirely outside at least one plane - nothing
     * of it can be on screen. Conservative: a sphere near a frustum corner may
     * be reported inside although it is not.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $planes
     */
    public static function sphereOutside(array $planes, float $x, float $y, float $z, float $radius): bool
    {
        foreach ($planes as $p) {
            if ($p[0] * $x + $p[1] * $y + $p[2] * $z + $p[3] < -$radius) {
                return true;
            }
        }
        return false;
    }
}
