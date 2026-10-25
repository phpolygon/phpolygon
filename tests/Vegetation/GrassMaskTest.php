<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Vegetation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\Layout\Zone;
use PHPolygon\Vegetation\GrassMask;

final class GrassMaskTest extends TestCase
{
    public function testOpenMaskGrowsEverywhere(): void
    {
        $mask = new GrassMask();
        self::assertTrue($mask->isOpen());
        self::assertSame(1.0, $mask->factor(12.3, -40.0));
    }

    public function testPathHasNoGrassAndASoftEdge(): void
    {
        $mask = (new GrassMask())->excludePath([[0.0, 0.0], [10.0, 0.0], [10.0, 10.0]], 1.0, 0.5);
        self::assertSame(0.0, $mask->factor(5.0, 0.0), 'on the path');
        self::assertSame(0.0, $mask->factor(5.0, 0.99), 'inside the half width');
        self::assertSame(0.0, $mask->factor(10.5, 6.0), 'second segment');
        $edge = $mask->factor(5.0, 1.25);
        self::assertGreaterThan(0.0, $edge);
        self::assertLessThan(1.0, $edge, 'the feather thins the edge');
        self::assertSame(1.0, $mask->factor(5.0, 1.6), 'beyond the feather');
        self::assertSame(1.0, $mask->factor(-5.0, 5.0));
    }

    public function testRectCircleZoneAndPolygonExclude(): void
    {
        $mask = (new GrassMask())
            ->excludeRect(0.0, 0.0, 4.0, 2.0, 0.0)
            ->excludeCircle(20.0, 20.0, 3.0, 1.0)
            ->excludeZone(new Zone('house', new Vec3(-10.0, 0.0, -10.0), new Vec3(-6.0, 5.0, -6.0)), 0.0)
            ->excludePolygon([[30.0, 0.0], [40.0, 0.0], [35.0, 8.0]], 0.0);
        self::assertSame(0.0, $mask->factor(2.0, 1.0));
        self::assertSame(1.0, $mask->factor(4.5, 1.0));
        self::assertSame(0.0, $mask->factor(21.0, 21.0));
        self::assertGreaterThan(0.0, $mask->factor(23.5, 20.0));
        self::assertLessThan(1.0, $mask->factor(23.5, 20.0));
        self::assertSame(0.0, $mask->factor(-8.0, -8.0));
        self::assertSame(0.0, $mask->factor(35.0, 3.0));
        self::assertSame(1.0, $mask->factor(31.0, 6.0), 'outside the triangle');
    }

    public function testIncludeAreasLimitTheGrassAndFadeInward(): void
    {
        $mask = (new GrassMask())->includePolygon([[0.0, 0.0], [20.0, 0.0], [20.0, 20.0], [0.0, 20.0]], 2.0);
        self::assertSame(0.0, $mask->factor(-1.0, 5.0));
        self::assertSame(1.0, $mask->factor(10.0, 10.0));
        $edge = $mask->factor(1.0, 10.0);
        self::assertGreaterThan(0.0, $edge);
        self::assertLessThan(1.0, $edge);
        $mask->includeCircle(40.0, 40.0, 5.0);
        self::assertSame(1.0, $mask->factor(40.0, 41.0), 'any include area counts');
    }

    public function testCallablesMultiplyAndVersionBumps(): void
    {
        $mask = new GrassMask();
        $v = $mask->version;
        $mask->multiply(static fn(float $x, float $z): float => $x > 0.0 ? 0.25 : 3.0);
        self::assertGreaterThan($v, $mask->version);
        self::assertSame(0.25, $mask->factor(1.0, 0.0));
        self::assertSame(1.0, $mask->factor(-1.0, 0.0), 'clamped to 1');
    }
}
