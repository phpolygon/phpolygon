<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\RegionShape;
use PHPUnit\Framework\TestCase;

final class RegionShapeTest extends TestCase
{
    public function testCircleIsASignedDistance(): void
    {
        $c = RegionShape::circle(2.0, -1.0, 3.0);
        self::assertEqualsWithDelta(-3.0, $c->distance(2.0, -1.0), 1e-12);
        self::assertEqualsWithDelta(0.0, $c->distance(5.0, -1.0), 1e-12);
        self::assertEqualsWithDelta(2.0, $c->distance(2.0, 4.0), 1e-12);
        self::assertSame([-1.0, -4.0, 5.0, 2.0], $c->bounds());
    }

    public function testRectTurnedByAQuarterSwapsItsExtent(): void
    {
        $r = RegionShape::rect(0.0, 0.0, 4.0, 2.0);
        self::assertEqualsWithDelta(-1.0, $r->distance(0.0, 0.0), 1e-12);
        self::assertEqualsWithDelta(1.0, $r->distance(3.0, 0.0), 1e-12);
        self::assertEqualsWithDelta(sqrt(2.0), $r->distance(3.0, 2.0), 1e-12, 'corner distance is Euclidean');

        $t = RegionShape::rect(0.0, 0.0, 4.0, 2.0, M_PI / 2.0);
        self::assertEqualsWithDelta(1.0, $t->distance(0.0, 3.0), 1e-9);
        self::assertEqualsWithDelta(0.0, $t->distance(1.0, 0.0), 1e-9);
        [$x0, $z0, $x1, $z1] = $t->bounds();
        self::assertEqualsWithDelta(-1.0, $x0, 1e-9);
        self::assertEqualsWithDelta(-2.0, $z0, 1e-9);
        self::assertEqualsWithDelta(1.0, $x1, 1e-9);
        self::assertEqualsWithDelta(2.0, $z1, 1e-9);
    }

    public function testPolygonSignDoesNotDependOnWinding(): void
    {
        $square = [[0.0, 0.0], [2.0, 0.0], [2.0, 2.0], [0.0, 2.0]];
        foreach ([$square, array_reverse($square)] as $pts) {
            $p = RegionShape::polygon($pts);
            self::assertEqualsWithDelta(-1.0, $p->distance(1.0, 1.0), 1e-12);
            self::assertEqualsWithDelta(0.5, $p->distance(2.5, 1.0), 1e-12);
        }
    }

    public function testPolylineHasRoundCapsAndStampsPerSegment(): void
    {
        $l = RegionShape::polyline([[0.0, 0.0], [10.0, 0.0], [10.0, 10.0]], 1.0);
        self::assertEqualsWithDelta(-1.0, $l->distance(5.0, 0.0), 1e-12);
        self::assertEqualsWithDelta(0.0, $l->distance(-1.0, 0.0), 1e-12);
        self::assertEqualsWithDelta(sqrt(2.0) - 1.0, $l->distance(-1.0, -1.0), 1e-12);
        self::assertEqualsWithDelta(-1.0, $l->distance(10.0, 5.0), 1e-12);
        $stamps = $l->stamps(0.5);
        self::assertCount(2, $stamps);
        [$x0, $z0, $x1, $z1, $fn] = $stamps[0];
        self::assertSame([-1.5, -1.5, 11.5, 1.5], [$x0, $z0, $x1, $z1]);
        self::assertEqualsWithDelta(-1.0, $fn(5.0, 0.0), 1e-12);
    }

    public function testHalfDiscKeepsTheOpenSide(): void
    {
        $h = RegionShape::halfDisc(0.0, 0.0, 2.0, 0.0, 1.0);
        self::assertLessThan(0.0, $h->distance(0.0, 1.0));
        self::assertGreaterThan(0.0, $h->distance(0.0, -1.0));
        self::assertEqualsWithDelta(0.0, $h->distance(1.0, 0.0), 1e-12);
    }

    public function testFieldWrapsAnyDistance(): void
    {
        $f = RegionShape::field(-1.0, -1.0, 1.0, 1.0, static fn (float $x, float $z): float => max(abs($x), abs($z)) - 1.0);
        self::assertEqualsWithDelta(-1.0, $f->distance(0.0, 0.0), 1e-12);
        self::assertSame([-1.0, -1.0, 1.0, 1.0], $f->bounds());
    }

    public function testInvalidShapesThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RegionShape::polyline([[0.0, 0.0]], 1.0);
    }
}
