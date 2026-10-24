<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

use PHPolygon\Component\FoliageInstances;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\Layout\Zone;

/**
 * Placement helpers that make plants frame spaces instead of carpeting them:
 * an avenue along a path, a ring around a square with openings, a loose
 * cluster - then {@see filter()} drops everything inside keep-out zones (walk
 * zones, door approaches, sight lines, building footprints) and sets each
 * foot on the ground the game samples.
 *
 * Output is a list of placements `{position, yaw, scale}`; deterministic for
 * the same inputs and seed. Nothing here knows a game: zones, the ground
 * function and every distance come from the caller.
 *
 * @phpstan-type Placement array{position: Vec3, yaw: float, scale: float}
 */
final class PlantPlacement
{
    /**
     * Trees along a polyline, `$offset` metres to the side, every `$spacing`
     * metres. With both sides the second row is shifted by `$stagger` of a
     * spacing (0.5 = alternating, 0 = paired).
     *
     * @param list<Vec3> $path
     * @return list<Placement>
     */
    public static function avenue(
        array $path,
        float $spacing,
        float $offset,
        bool $bothSides = true,
        float $stagger = 0.5,
        int $seed = 1,
        float $scaleJitter = 0.08,
    ): array {
        if (count($path) < 2 || $spacing <= 0.0) {
            return [];
        }
        $out = [];
        $sides = $bothSides ? [1.0, -1.0] : [1.0];
        foreach ($sides as $k => $side) {
            $start = $k === 0 ? $spacing * 0.5 : $spacing * (0.5 + $stagger);
            foreach (self::alongPath($path, $spacing, $start) as [$p, $dx, $dz]) {
                // Left normal of the direction (dx, dz) is (-dz, dx).
                $pos = new Vec3($p->x - $dz * $offset * $side, $p->y, $p->z + $dx * $offset * $side);
                $out[] = self::placement($pos, $seed, count($out), $scaleJitter);
            }
        }
        return $out;
    }

    /**
     * Trees on a circle (or an arc) around `$center`, leaving gaps at the
     * given openings (angle in radians around +Y measured from +X toward +Z,
     * and the half-width of the gap in radians).
     *
     * @param list<array{0: float, 1: float}> $openings [angle, halfWidth]
     * @return list<Placement>
     */
    public static function ring(
        Vec3 $center,
        float $radius,
        float $spacing,
        array $openings = [],
        float $startAngle = 0.0,
        float $arc = 2.0 * M_PI,
        int $seed = 1,
        float $scaleJitter = 0.08,
    ): array {
        if ($radius <= 0.0 || $spacing <= 0.0) {
            return [];
        }
        $n = max(1, (int) floor($radius * $arc / $spacing));
        $step = $arc / $n;
        $out = [];
        $closed = abs($arc - 2.0 * M_PI) < 1e-6;
        $count = $closed ? $n : $n + 1;
        for ($i = 0; $i < $count; $i++) {
            $a = $startAngle + $i * $step;
            foreach ($openings as [$oa, $half]) {
                $d = atan2(sin($a - $oa), cos($a - $oa));
                if (abs($d) < $half) {
                    continue 2;
                }
            }
            $pos = new Vec3($center->x + cos($a) * $radius, $center->y, $center->z + sin($a) * $radius);
            $out[] = self::placement($pos, $seed, $i, $scaleJitter);
        }
        return $out;
    }

    /**
     * `$count` plants scattered in a disc with at least `$minDistance` between
     * them (dart throwing with a fixed number of tries; may return fewer).
     *
     * @return list<Placement>
     */
    public static function cluster(Vec3 $center, float $radius, int $count, float $minDistance, int $seed = 1, float $scaleJitter = 0.12): array
    {
        $out = [];
        $min2 = $minDistance * $minDistance;
        for ($try = 0; $try < $count * 30 && count($out) < $count; $try++) {
            $a = PlantGenerator::rnd($seed, 9000 + $try * 2) * 2.0 * M_PI;
            $r = sqrt(PlantGenerator::rnd($seed, 9001 + $try * 2)) * $radius;
            $p = new Vec3($center->x + cos($a) * $r, $center->y, $center->z + sin($a) * $r);
            foreach ($out as $o) {
                $dx = $o['position']->x - $p->x;
                $dz = $o['position']->z - $p->z;
                if ($dx * $dx + $dz * $dz < $min2) {
                    continue 2;
                }
            }
            $out[] = self::placement($p, $seed, $try, $scaleJitter);
        }
        return $out;
    }

    /**
     * Drop placements whose footprint (radius `$clearance`) touches a keep-out
     * zone in XZ, and set the rest on the ground: `$ground(x, z)` returns the
     * ground height or null (no ground - e.g. water - drops the plant); the
     * foot is sunk `$sink` metres so the root flare meets the ground.
     *
     * @param list<Placement>                    $placements
     * @param list<Zone>                         $keepOuts
     * @param (callable(float, float): ?float)|null $ground
     * @return list<Placement>
     */
    public static function filter(array $placements, array $keepOuts, float $clearance = 0.0, ?callable $ground = null, float $sink = 0.12): array
    {
        $zones = [];
        foreach ($keepOuts as $zone) {
            $zones[] = $clearance > 0.0 ? $zone->expanded($clearance) : $zone;
        }
        $out = [];
        foreach ($placements as $pl) {
            $p = $pl['position'];
            foreach ($zones as $zone) {
                if ($zone->containsXZ($p)) {
                    continue 2;
                }
            }
            if ($ground !== null) {
                $y = $ground($p->x, $p->z);
                if ($y === null) {
                    continue;
                }
                $pl['position'] = new Vec3($p->x, $y - $sink, $p->z);
            }
            $out[] = $pl;
        }
        return $out;
    }

    /**
     * Add placements to a foliage component.
     *
     * @param list<Placement> $placements
     */
    public static function addTo(FoliageInstances $foliage, array $placements, GrowthStage $stage = GrowthStage::Full): void
    {
        foreach ($placements as $pl) {
            $foliage->add($pl['position'], $pl['yaw'], $pl['scale'], $stage);
        }
    }

    /**
     * Points every `$spacing` metres along a polyline, starting `$start` in.
     *
     * @param list<Vec3> $path
     * @return list<array{0: Vec3, 1: float, 2: float}> [point, dirX, dirZ]
     */
    private static function alongPath(array $path, float $spacing, float $start): array
    {
        $out = [];
        $next = $start;
        $travelled = 0.0;
        for ($i = 0, $n = count($path) - 1; $i < $n; $i++) {
            $a = $path[$i];
            $b = $path[$i + 1];
            $dx = $b->x - $a->x;
            $dz = $b->z - $a->z;
            $len = sqrt($dx * $dx + $dz * $dz);
            if ($len < 1e-6) {
                continue;
            }
            $ux = $dx / $len;
            $uz = $dz / $len;
            while ($next <= $travelled + $len + 1e-9) {
                $t = ($next - $travelled) / $len;
                $out[] = [new Vec3($a->x + $dx * $t, $a->y + ($b->y - $a->y) * $t, $a->z + $dz * $t), $ux, $uz];
                $next += $spacing;
            }
            $travelled += $len;
        }
        return $out;
    }

    /** @return Placement */
    private static function placement(Vec3 $p, int $seed, int $i, float $scaleJitter): array
    {
        $h = PlantGenerator::rnd($seed, (int) round($p->x * 7.0 + $p->z * 13.0) + $i * 31);
        return [
            'position' => $p,
            'yaw' => $h * 2.0 * M_PI,
            'scale' => 1.0 + (PlantGenerator::rnd($seed, $i * 17 + 5) - 0.5) * 2.0 * $scaleJitter,
        ];
    }
}
