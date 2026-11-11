<?php

declare(strict_types=1);

namespace PHPolygon\Geometry;

use PHPolygon\Math\Vec2;

/**
 * Small library of 2D profiles (closed CCW polygons, first point not
 * repeated) for PolygonExtrude and SweepMesh::generate, plus helpers to
 * soften a polygon. Bevelled and rounded trims are a profile from here swept
 * along a path (SweepMesh) or extruded straight (PolygonExtrude).
 *
 * Unless noted, profiles are centred on the origin. Pure functions.
 */
final class Profile
{
    /** @return list<Vec2> */
    public static function rect(float $width, float $height): array
    {
        $hw = $width / 2.0;
        $hh = $height / 2.0;
        return [new Vec2(-$hw, -$hh), new Vec2($hw, -$hh), new Vec2($hw, $hh), new Vec2(-$hw, $hh)];
    }

    /**
     * Rectangle with the four corners cut off by $chamfer (clamped to half the short side).
     *
     * @return list<Vec2>
     */
    public static function chamferedRect(float $width, float $height, float $chamfer): array
    {
        $hw = $width / 2.0;
        $hh = $height / 2.0;
        $c = max(0.0, min($chamfer, $hw, $hh));
        if ($c <= 0.0) {
            return self::rect($width, $height);
        }
        return [
            new Vec2(-$hw + $c, -$hh), new Vec2($hw - $c, -$hh),
            new Vec2($hw, -$hh + $c), new Vec2($hw, $hh - $c),
            new Vec2($hw - $c, $hh), new Vec2(-$hw + $c, $hh),
            new Vec2(-$hw, $hh - $c), new Vec2(-$hw, -$hh + $c),
        ];
    }

    /**
     * Rectangle with round corners, $segments per quarter circle.
     *
     * @return list<Vec2>
     */
    public static function roundedRect(float $width, float $height, float $radius, int $segments = 4): array
    {
        $hw = $width / 2.0;
        $hh = $height / 2.0;
        $r = max(0.0, min($radius, $hw, $hh));
        if ($r <= 0.0) {
            return self::rect($width, $height);
        }
        $segments = max(1, $segments);
        $out = [];
        // Corner centres in CCW order starting bottom-right, with the start angle of each arc.
        $corners = [
            [$hw - $r, -$hh + $r, -M_PI / 2.0],
            [$hw - $r, $hh - $r, 0.0],
            [-$hw + $r, $hh - $r, M_PI / 2.0],
            [-$hw + $r, -$hh + $r, M_PI],
        ];
        foreach ($corners as [$cx, $cy, $a0]) {
            for ($i = 0; $i <= $segments; $i++) {
                $a = $a0 + (M_PI / 2.0) * $i / $segments;
                $out[] = new Vec2($cx + cos($a) * $r, $cy + sin($a) * $r);
            }
        }
        return $out;
    }

    /**
     * @return list<Vec2>
     */
    public static function circle(float $radius, int $sides = 16): array
    {
        $sides = max(3, $sides);
        $out = [];
        for ($i = 0; $i < $sides; $i++) {
            $a = 2.0 * M_PI * $i / $sides;
            $out[] = new Vec2(cos($a) * $radius, sin($a) * $radius);
        }
        return $out;
    }

    /**
     * Opening shape with its lower-left corner at the origin: a rectangle of
     * $width x $height whose top is a circular arc of height $rise (0 = flat
     * top, width/2 = semicircle). $rise is clamped to [0, min($height, width/2)];
     * the arc passes through both top corners of the straight part.
     *
     * @return list<Vec2>
     */
    public static function archedRect(float $width, float $height, float $rise = 0.0, int $segments = 12): array
    {
        $rise = max(0.0, min($rise, $height, $width / 2.0));
        $pts = [new Vec2(0.0, 0.0), new Vec2($width, 0.0)];
        $spring = $height - $rise;
        if ($rise < 1e-9) {
            $pts[] = new Vec2($width, $height);
            $pts[] = new Vec2(0.0, $height);
            return $pts;
        }
        $pts[] = new Vec2($width, $spring);
        $segments = max(2, $segments);
        $half = $width / 2.0;
        // Circle through (0, spring), (width, spring) and the crown (half, height).
        $radius = ($half * $half + $rise * $rise) / (2.0 * $rise);
        $cy = $height - $radius;
        $aStart = atan2($spring - $cy, $half);
        $aEnd = atan2($spring - $cy, -$half);
        for ($i = 1; $i < $segments; $i++) {
            $a = $aStart + ($aEnd - $aStart) * $i / $segments;
            $pts[] = new Vec2($half + cos($a) * $radius, $cy + sin($a) * $radius);
        }
        $pts[] = new Vec2(0.0, $spring);
        return $pts;
    }

    /**
     * Cut every corner of a closed polygon by $distance along both adjacent
     * edges (chamfer). Corners whose edges are shorter than 2 x $distance are
     * cut at half the shorter edge.
     *
     * @param list<Vec2> $ring
     * @return list<Vec2>
     */
    public static function chamfer(array $ring, float $distance): array
    {
        $n = count($ring);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $prev = $ring[($i + $n - 1) % $n];
            $cur = $ring[$i];
            $next = $ring[($i + 1) % $n];
            $lp = sqrt(($prev->x - $cur->x) ** 2 + ($prev->y - $cur->y) ** 2);
            $ln = sqrt(($next->x - $cur->x) ** 2 + ($next->y - $cur->y) ** 2);
            $d = min($distance, $lp / 2.0, $ln / 2.0);
            if ($d <= 1e-12 || $lp < 1e-12 || $ln < 1e-12) {
                $out[] = $cur;
                continue;
            }
            $out[] = new Vec2($cur->x + ($prev->x - $cur->x) / $lp * $d, $cur->y + ($prev->y - $cur->y) / $lp * $d);
            $out[] = new Vec2($cur->x + ($next->x - $cur->x) / $ln * $d, $cur->y + ($next->y - $cur->y) / $ln * $d);
        }
        return $out;
    }

    /**
     * Round every corner of a closed polygon with a quadratic arc between the
     * chamfer points (see chamfer()), $segments per corner.
     *
     * @param list<Vec2> $ring
     * @return list<Vec2>
     */
    public static function round(array $ring, float $distance, int $segments = 4): array
    {
        $n = count($ring);
        $segments = max(1, $segments);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $prev = $ring[($i + $n - 1) % $n];
            $cur = $ring[$i];
            $next = $ring[($i + 1) % $n];
            $lp = sqrt(($prev->x - $cur->x) ** 2 + ($prev->y - $cur->y) ** 2);
            $ln = sqrt(($next->x - $cur->x) ** 2 + ($next->y - $cur->y) ** 2);
            $d = min($distance, $lp / 2.0, $ln / 2.0);
            if ($d <= 1e-12 || $lp < 1e-12 || $ln < 1e-12) {
                $out[] = $cur;
                continue;
            }
            $ax = $cur->x + ($prev->x - $cur->x) / $lp * $d;
            $ay = $cur->y + ($prev->y - $cur->y) / $lp * $d;
            $bx = $cur->x + ($next->x - $cur->x) / $ln * $d;
            $by = $cur->y + ($next->y - $cur->y) / $ln * $d;
            for ($k = 0; $k <= $segments; $k++) {
                $t = $k / $segments;
                $s = 1.0 - $t;
                $out[] = new Vec2(
                    $s * $s * $ax + 2.0 * $s * $t * $cur->x + $t * $t * $bx,
                    $s * $s * $ay + 2.0 * $s * $t * $cur->y + $t * $t * $by,
                );
            }
        }
        return $out;
    }
}
