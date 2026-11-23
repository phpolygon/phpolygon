<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\ConformingSurfaceSet;
use PHPolygon\Geometry\GroundMemo;
use PHPolygon\Geometry\RegionShape;
use PHPUnit\Framework\TestCase;

final class GroundMemoTest extends TestCase
{
    public function testAnswersARepeatedPointFromTheMemo(): void
    {
        $calls = 0;
        $memo = new GroundMemo(static function (float $x, float $z) use (&$calls): float {
            $calls++;

            return $x * 2.0 + $z;
        });
        self::assertSame(5.0, $memo(1.0, 3.0));
        self::assertSame(5.0, $memo(1.0, 3.0));
        self::assertSame(5.0, $memo(1.00001, 3.00001), 'closer than 0.1 mm is the same point');
        self::assertNotSame(5.0, $memo(1.001, 3.0));
        self::assertSame(1, $calls - 1, 'three of the four queries were memo hits');
        self::assertSame(4, $memo->queries());
        self::assertSame(2, $memo->misses());
        self::assertSame(2, $memo->size());
    }

    public function testNegativeAndMixedCoordinatesDoNotCollide(): void
    {
        $memo = new GroundMemo(static fn (float $x, float $z): float => $x * 1000.0 + $z);
        $seen = [];
        foreach ([[-1.0, 2.0], [2.0, -1.0], [-1.0, -2.0], [1.0, 2.0], [0.0, 0.0], [-0.0001, 0.0001]] as [$x, $z]) {
            $seen[(string) $memo($x, $z)] = true;
        }
        self::assertCount(6, $seen);
        self::assertSame(6, $memo->misses());
    }

    public function testASharedMemoSavesGroundQueriesAcrossSets(): void
    {
        $calls = 0;
        $ground = static function (float $x, float $z) use (&$calls): float {
            $calls++;

            return 0.05 * $x + 0.02 * $z;
        };
        $build = static function (callable $g): array {
            $set = new ConformingSurfaceSet($g, cell: 0.5);
            $set->add('p', RegionShape::polyline([[-8.0, 0.0], [8.0, 3.0]], 1.2))->border('p', 0.3, 'kerb');

            return $set->build();
        };

        $calls = 0;
        $a = $build($ground);
        $build($ground);
        $alone = $calls;

        $calls = 0;
        $shared = new GroundMemo($ground);
        $b = $build($shared);
        $build($shared);
        self::assertLessThan($alone * 0.6, $calls, 'the second set found every sample in the memo');
        self::assertSame(array_keys($a), array_keys($b));
        self::assertSame($a['p']->indices, $b['p']->indices);
        self::assertEqualsWithDelta($a['p']->vertices, $b['p']->vertices, 1e-12);
    }

    public function testASetBuiltOnAPlainCallableBehavesAsBefore(): void
    {
        $set = new ConformingSurfaceSet(static fn (float $x, float $z): float => 0.1 * $x);
        self::assertEqualsWithDelta(0.3, ($set->ground())(3.0, 1.0), 1e-12);
    }
}
