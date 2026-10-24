<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Vegetation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Component\FoliageInstances;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\Layout\Zone;
use PHPolygon\Vegetation\GrowthStage;
use PHPolygon\Vegetation\PlantPlacement;

final class PlantPlacementTest extends TestCase
{
    public function testAvenueLinesBothSidesAtTheSpacing(): void
    {
        $path = [new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 0.0, -100.0)];
        $trees = PlantPlacement::avenue($path, 10.0, 4.0);
        $left = array_values(array_filter($trees, static fn (array $p): bool => $p['position']->x > 0.0));
        $right = array_values(array_filter($trees, static fn (array $p): bool => $p['position']->x < 0.0));
        self::assertCount(10, $left);
        self::assertGreaterThanOrEqual(9, count($right));
        foreach ($trees as $t) {
            self::assertEqualsWithDelta(4.0, abs($t['position']->x), 1e-9, 'offset from the path centre');
        }
        self::assertEqualsWithDelta(10.0, abs($left[1]['position']->z - $left[0]['position']->z), 1e-9);
        self::assertEqualsWithDelta(5.0, abs($right[0]['position']->z - $left[0]['position']->z), 1e-9, 'staggered by half a spacing');
        self::assertEquals($trees, PlantPlacement::avenue($path, 10.0, 4.0), 'deterministic');
    }

    public function testRingLeavesItsOpenings(): void
    {
        $ring = PlantPlacement::ring(new Vec3(5.0, 0.0, 5.0), 20.0, 8.0, [[0.0, 0.4], [M_PI, 0.4]]);
        self::assertNotEmpty($ring);
        foreach ($ring as $p) {
            $dx = $p['position']->x - 5.0;
            $dz = $p['position']->z - 5.0;
            self::assertEqualsWithDelta(20.0, sqrt($dx * $dx + $dz * $dz), 1e-9);
            $a = atan2($dz, $dx);
            self::assertGreaterThan(0.4, abs($a), 'no tree in the opening at angle 0');
            self::assertGreaterThan(0.4, M_PI - abs($a), 'no tree in the opening at angle pi');
        }
        $full = PlantPlacement::ring(new Vec3(0.0, 0.0, 0.0), 20.0, 8.0);
        self::assertCount(15, $full);
    }

    public function testClusterKeepsItsDistances(): void
    {
        $cluster = PlantPlacement::cluster(new Vec3(0.0, 0.0, 0.0), 8.0, 5, 3.0, 7);
        self::assertCount(5, $cluster);
        foreach ($cluster as $i => $a) {
            self::assertLessThanOrEqual(8.0 + 1e-9, hypot($a['position']->x, $a['position']->z));
            foreach ($cluster as $j => $b) {
                if ($i < $j) {
                    self::assertGreaterThanOrEqual(3.0, hypot($a['position']->x - $b['position']->x, $a['position']->z - $b['position']->z));
                }
            }
        }
    }

    public function testFilterDropsKeepOutsAndSetsTheFootOnTheGround(): void
    {
        $path = [new Vec3(-50.0, 0.0, 0.0), new Vec3(50.0, 0.0, 0.0)];
        $trees = PlantPlacement::avenue($path, 5.0, 3.0, bothSides: false);
        $door = Zone::around('door', new Vec3(0.0, 0.0, 3.0), new Vec3(4.0, 2.0, 2.0), 'keepout');
        $ground = static fn (float $x, float $z): ?float => $x > 40.0 ? null : 0.02 * $x;
        $kept = PlantPlacement::filter($trees, [$door], 1.5, $ground, 0.15);
        self::assertLessThan(count($trees), count($kept));
        foreach ($kept as $t) {
            $p = $t['position'];
            self::assertFalse($door->expanded(1.5)->containsXZ($p), 'clear of the door approach');
            self::assertLessThanOrEqual(40.0, $p->x, 'no ground (water) means no tree');
            self::assertEqualsWithDelta(0.02 * $p->x - 0.15, $p->y, 1e-9, 'foot sunk into the sampled ground');
        }
        $f = new FoliageInstances('x');
        PlantPlacement::addTo($f, $kept, GrowthStage::Sprouting);
        self::assertSame(count($kept), $f->count());
        self::assertSame(GrowthStage::Sprouting, $f->stage(0));
    }
}
