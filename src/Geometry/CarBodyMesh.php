<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Math\Vec3;
use PHPolygon\Runtime\PerfProfiler;

/**
 * Generates a procedural car body by extruding a side-view silhouette along
 * the Z axis. The silhouette is an 8-point convex polygon describing front
 * bumper -> hood -> windshield -> cabin roof -> rear window -> trunk lid ->
 * rear bumper, traced counterclockwise when viewed from +Z.
 *
 * Coordinate convention:
 *   +X = forward (front bumper sits at x = +length/2)
 *   +Y = up      (silhouette starts at y = 0, peaks at cabin height)
 *   +Z = left    (body extends from z = -width/2 to z = +width/2)
 *
 * The polygon is convex by construction so the side-cap triangle fans
 * remain valid for every parameter combination.
 */
class CarBodyMesh
{
    /**
     * @param float       $hoodLengthFrac  fraction of $length consumed by the hood (front lower section)
     * @param float       $hoodHeightFrac  hood top height as fraction of $bodyHeight
     * @param float       $cabinLengthFrac fraction of $length consumed by the cabin roof
     * @param float       $cabinHeightFrac cabin roof height as fraction of $bodyHeight (use < 1 for convertibles)
     * @param float       $trunkHeightFrac trunk lid height as fraction of $bodyHeight
     * @param float       $windshieldSlope absolute X offset between hood-rear and cabin-front-top
     * @param float       $rearWindowSlope absolute X offset between cabin-rear-top and trunk-front
     * @param list<int>   $skipSidePanels  silhouette-edge indices (0..7) to omit from the
     *                                     extruded side. Index 2 is the windshield slope,
     *                                     index 4 is the rear-window slope. Used by the
     *                                     Car prefab so glass panels can fill those gaps
     *                                     without z-fighting.
     */
    public static function generate(
        float $length,
        float $width,
        float $bodyHeight,
        float $hoodLengthFrac  = 0.30,
        float $hoodHeightFrac  = 0.50,
        float $cabinLengthFrac = 0.32,
        float $cabinHeightFrac = 1.00,
        float $trunkHeightFrac = 0.62,
        float $windshieldSlope = 0.30,
        float $rearWindowSlope = 0.25,
        array $skipSidePanels = [],
    ): MeshData {
        return PerfProfiler::section('mesh.generate.car_body', static fn(): MeshData
            => self::generateImpl(
                $length, $width, $bodyHeight,
                $hoodLengthFrac, $hoodHeightFrac,
                $cabinLengthFrac, $cabinHeightFrac,
                $trunkHeightFrac,
                $windshieldSlope, $rearWindowSlope,
                $skipSidePanels,
            ));
    }

    /** @param list<int> $skipSidePanels */
    private static function generateImpl(
        float $length, float $width, float $bodyHeight,
        float $hoodLengthFrac, float $hoodHeightFrac,
        float $cabinLengthFrac, float $cabinHeightFrac,
        float $trunkHeightFrac,
        float $windshieldSlope, float $rearWindowSlope,
        array $skipSidePanels,
    ): MeshData {
        $halfL = $length / 2.0;
        $hoodHeight  = $bodyHeight * $hoodHeightFrac;
        $cabinHeight = $bodyHeight * max($cabinHeightFrac, $hoodHeightFrac + 0.05);
        $trunkHeight = $bodyHeight * $trunkHeightFrac;

        $hoodRearX      =  $halfL - $length * $hoodLengthFrac;
        $cabinFrontTopX = $hoodRearX - $windshieldSlope;
        $cabinRearTopX  = $cabinFrontTopX - $length * $cabinLengthFrac;
        $trunkFrontX    = max($cabinRearTopX - $rearWindowSlope, -$halfL);

        // 8-point silhouette, CCW when viewed from +Z (left side of the car).
        $silhouette = [
            [ $halfL,           0.0],          // p0 front bumper bottom
            [ $halfL,           $hoodHeight],  // p1 front bumper top / hood front
            [ $hoodRearX,       $hoodHeight],  // p2 hood rear / windshield base
            [ $cabinFrontTopX,  $cabinHeight], // p3 cabin roof front
            [ $cabinRearTopX,   $cabinHeight], // p4 cabin roof rear
            [ $trunkFrontX,     $trunkHeight], // p5 trunk lid front
            [-$halfL,           $trunkHeight], // p6 rear bumper top
            [-$halfL,           0.0],          // p7 rear bumper bottom
        ];

        return self::extrude($silhouette, $width, $skipSidePanels);
    }

    /**
     * Extrude a 2D CCW polygon (in XY) along Z to produce a closed mesh.
     *
     * @param list<array{0: float, 1: float}> $silhouette
     * @param list<int>                       $skipSidePanels edge indices to omit
     */
    public static function extrude(array $silhouette, float $width, array $skipSidePanels = []): MeshData
    {
        $half = $width / 2.0;
        $n = count($silhouette);

        $vertices = [];
        $normals  = [];
        $uvs      = [];
        $indices  = [];

        $skipMap = array_flip($skipSidePanels);

        // -- Side panels (one quad per silhouette edge) --
        for ($i = 0; $i < $n; $i++) {
            if (isset($skipMap[$i])) {
                continue;
            }
            [$ax, $ay] = $silhouette[$i];
            [$bx, $by] = $silhouette[($i + 1) % $n];

            $ex = $bx - $ax;
            $ey = $by - $ay;
            // CCW polygon -> outward normal is (ey, -ex) rotated -90°.
            $nx =  $ey;
            $ny = -$ex;
            $len = sqrt($nx * $nx + $ny * $ny);
            if ($len < 1e-9) {
                continue;
            }
            $nx /= $len;
            $ny /= $len;

            $base = (int)(count($vertices) / 3);
            // Quad (CCW from outside): (a,+half), (a,-half), (b,-half), (b,+half)
            $vertices[] = $ax; $vertices[] = $ay; $vertices[] =  $half;
            $vertices[] = $ax; $vertices[] = $ay; $vertices[] = -$half;
            $vertices[] = $bx; $vertices[] = $by; $vertices[] = -$half;
            $vertices[] = $bx; $vertices[] = $by; $vertices[] =  $half;
            for ($k = 0; $k < 4; $k++) {
                $normals[] = $nx; $normals[] = $ny; $normals[] = 0.0;
            }
            $uvs[] = 0.0; $uvs[] = 0.0;
            $uvs[] = 1.0; $uvs[] = 0.0;
            $uvs[] = 1.0; $uvs[] = 1.0;
            $uvs[] = 0.0; $uvs[] = 1.0;

            $indices[] = $base + 0; $indices[] = $base + 1; $indices[] = $base + 2;
            $indices[] = $base + 0; $indices[] = $base + 2; $indices[] = $base + 3;
        }

        // -- Left cap (+Z) - normal is +Z, fan from vertex 0 --
        $base = (int)(count($vertices) / 3);
        foreach ($silhouette as [$x, $y]) {
            $vertices[] = $x; $vertices[] = $y; $vertices[] = $half;
            $normals[]  = 0.0; $normals[]  = 0.0; $normals[]  = 1.0;
            $uvs[]      = $x; $uvs[]      = $y;
        }
        for ($i = 1; $i < $n - 1; $i++) {
            $indices[] = $base + 0;
            $indices[] = $base + $i;
            $indices[] = $base + $i + 1;
        }

        // -- Right cap (-Z) - normal is -Z, reversed winding --
        $base = (int)(count($vertices) / 3);
        foreach ($silhouette as [$x, $y]) {
            $vertices[] = $x; $vertices[] = $y; $vertices[] = -$half;
            $normals[]  = 0.0; $normals[]  = 0.0; $normals[]  = -1.0;
            $uvs[]      = $x; $uvs[]      = $y;
        }
        for ($i = 1; $i < $n - 1; $i++) {
            $indices[] = $base + 0;
            $indices[] = $base + $i + 1;
            $indices[] = $base + $i;
        }

        return new MeshData(
            vertices: $vertices,
            normals:  $normals,
            uvs:      $uvs,
            indices:  $indices,
        );
    }

    /**
     * Build a single planar quad from four corners listed CCW when viewed
     * from the side the surface should be visible from. The shared face
     * normal is computed from (b - a) × (d - a); the four corners must be
     * coplanar for the result to look correct.
     */
    public static function quad(Vec3 $a, Vec3 $b, Vec3 $c, Vec3 $d): MeshData
    {
        $ab = $b->sub($a);
        $ad = $d->sub($a);
        $normal = $ab->cross($ad)->normalize();

        $vertices = [];
        $normals  = [];
        foreach ([$a, $b, $c, $d] as $p) {
            $vertices[] = $p->x; $vertices[] = $p->y; $vertices[] = $p->z;
            $normals[]  = $normal->x; $normals[] = $normal->y; $normals[] = $normal->z;
        }

        return new MeshData(
            vertices: $vertices,
            normals:  $normals,
            uvs:      [0.0, 0.0,  1.0, 0.0,  1.0, 1.0,  0.0, 1.0],
            indices:  [0, 1, 2, 0, 2, 3],
        );
    }
}
