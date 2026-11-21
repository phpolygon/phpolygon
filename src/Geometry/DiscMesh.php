<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

/**
 * A thick regular disc (a plate): top and bottom fans from the centre, one rim quad per segment, optionally a
 * square hole through it (a {@see PrismClip}: the disc is cut outside the prism and the hole gets four inward walls).
 * The first rim vertex points towards -Z, vertices run towards +X.
 */
final class DiscMesh
{
    public static function generate(float $centreX, float $centreZ, float $radius, int $segments, float $top, float $bottom, ?PrismClip $hole = null): MeshData
    {
        if ($segments < 3 || $radius <= 0.0) {
            throw new \InvalidArgumentException('DiscMesh needs segments >= 3 and a positive radius.');
        }
        $m = new MeshAccumulator();
        $ring = [];
        for ($k = 0; $k < $segments; $k++) {
            $a = 2.0 * M_PI * $k / $segments;
            $ring[] = [$centreX + $radius * sin($a), $centreZ - $radius * cos($a)];
        }
        for ($k = 0; $k < $segments; $k++) {
            [$x0, $z0] = $ring[$k];
            [$x1, $z1] = $ring[($k + 1) % $segments];
            foreach ([[$top, 1.0], [$bottom, -1.0]] as [$y, $dir]) {
                $poly = [[$centreX, $y, $centreZ], [$x0, $y, $z0], [$x1, $y, $z1]];
                foreach ($hole === null ? [$poly] : $hole->outside($poly) as $piece) {
                    $m->fan($piece, [0.0, $dir, 0.0]);
                }
            }
            $mx = ($x0 + $x1) * 0.5 - $centreX;
            $mz = ($z0 + $z1) * 0.5 - $centreZ;
            $m->quad([$x0, $bottom, $z0], [$x1, $bottom, $z1], [$x1, $top, $z1], [$x0, $top, $z0], [$mx, 0.0, $mz]);
        }
        if ($hole !== null) {
            $m->walls($hole->frame, -$hole->half, $hole->half, -$hole->half, $hole->half, $bottom, $top, true);
        }

        return $m->build();
    }
}
