<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * A single blade of reed, grass or kelp: a narrow strip from its foot (origin, y = 0)
 * up to its tip at y = $height, tapering to a point and curving forward (+Z) by
 * $bend · height at the tip. Both faces carry their own vertices and outward normals,
 * so it lights from either side without a two-sided material. uv.x runs across the
 * blade (0..1), uv.y from the foot (0) to the tip (1).
 *
 * The origin at the foot is what vertex motion needs: BodyWave::sway() (or foliage
 * wind) bends a blade from a fixed foot with its own phase per instance, so a bed of
 * instanced blades never sways in lockstep and no foot slides.
 */
final class ReedBladeMesh
{
    /**
     * @param float $width    width at the foot (m)
     * @param float $bend     forward offset of the tip as a share of the height
     * @param float $tipWidth width at the tip as a share of $width
     * @param bool  $crossed  add a second strip across the first (in the plane of the
     *                       bend), so a stalk seen edge-on never thins to a line
     */
    public static function generate(float $height, float $width, int $segments = 4, float $bend = 0.12, float $tipWidth = 0.15, bool $crossed = false): MeshData
    {
        if ($height <= 0.0 || $width <= 0.0) {
            throw new \InvalidArgumentException('ReedBladeMesh: height and width must be positive');
        }
        $segments = max(1, $segments);
        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];

        foreach ([1.0, -1.0] as $face => $side) {
            $base = intdiv(count($vertices), 3);
            for ($i = 0; $i <= $segments; $i++) {
                $t = (float) $i / $segments;
                $y = $t * $height;
                $z = $bend * $height * $t * $t;
                $hw = 0.5 * $width * (1.0 - (1.0 - $tipWidth) * $t);
                // Tangent along the blade (0, h, 2·bend·h·t); the face normal is it turned
                // a quarter toward +Z (front) or -Z (back).
                $ty = $height;
                $tz = 2.0 * $bend * $height * $t;
                $len = sqrt($ty * $ty + $tz * $tz);
                $ny = -$tz / $len * $side;
                $nz = $ty / $len * $side;
                foreach ([-$hw, $hw] as $k => $x) {
                    array_push($vertices, $x, $y, $z);
                    array_push($normals, 0.0, $ny, $nz);
                    array_push($uvs, (float) $k, $t);
                }
            }
            for ($i = 0; $i < $segments; $i++) {
                $a = $base + $i * 2;
                $b = $a + 1;
                $c = $a + 2;
                $d = $a + 3;
                if ($face === 0) {
                    array_push($indices, $a, $b, $d, $a, $d, $c);   // front: CCW seen from +Z
                } else {
                    array_push($indices, $a, $d, $b, $a, $c, $d);   // back: CCW seen from -Z
                }
            }
        }

        if ($crossed) {
            // The cross strip follows the same curve: its width runs along Z around the
            // bent centre line, its faces look +X and -X.
            foreach ([1.0, -1.0] as $face => $side) {
                $base = intdiv(count($vertices), 3);
                for ($i = 0; $i <= $segments; $i++) {
                    $t = (float) $i / $segments;
                    $y = $t * $height;
                    $z = $bend * $height * $t * $t;
                    $hw = 0.5 * $width * (1.0 - (1.0 - $tipWidth) * $t);
                    foreach ([$hw, -$hw] as $k => $dz) {
                        array_push($vertices, 0.0, $y, $z + $dz);
                        array_push($normals, $side, 0.0, 0.0);
                        array_push($uvs, (float) $k, $t);
                    }
                }
                for ($i = 0; $i < $segments; $i++) {
                    $a = $base + $i * 2;
                    $b = $a + 1;
                    $c = $a + 2;
                    $d = $a + 3;
                    if ($face === 0) {
                        array_push($indices, $a, $b, $d, $a, $d, $c);   // CCW seen from +X
                    } else {
                        array_push($indices, $a, $d, $b, $a, $c, $d);   // CCW seen from -X
                    }
                }
            }
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
