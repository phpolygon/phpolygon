<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Fieldtracing;

use PHPolygon\Fieldtracing\Sdf\BoxSdf;
use PHPolygon\Fieldtracing\Sdf\PlaneSdf;
use PHPolygon\Fieldtracing\Sdf\SdfComposite;
use PHPolygon\Fieldtracing\Sdf\SphereSdf;
use PHPolygon\Math\Vec3;
use PHPUnit\Framework\TestCase;

class SdfCompositeTest extends TestCase
{
    private const EPS = 1e-6;

    public function testUnionIsMin(): void
    {
        $a = new SphereSdf(1.0, new Vec3(-2, 0, 0));
        $b = new SphereSdf(1.0, new Vec3(2, 0, 0));
        $u = SdfComposite::union($a, $b);

        // At origin: distance to each sphere surface is 2-1 = 1; min = 1.
        $this->assertEqualsWithDelta(1.0, $u->distance(new Vec3(0, 0, 0)), self::EPS);
        // Inside sphere b.
        $this->assertEqualsWithDelta(-1.0, $u->distance(new Vec3(2, 0, 0)), self::EPS);
    }

    public function testIntersectIsMax(): void
    {
        $a = new SphereSdf(2.0, new Vec3(-1, 0, 0));
        $b = new SphereSdf(2.0, new Vec3(1, 0, 0));
        $i = SdfComposite::intersect($a, $b);

        // Origin is inside both: dist to a-surface = |(-1..0)|... compute:
        // a: length((1,0,0)) - 2 = -1 ; b: length((-1,0,0)) - 2 = -1 ; max = -1.
        $this->assertEqualsWithDelta(-1.0, $i->distance(new Vec3(0, 0, 0)), self::EPS);
    }

    public function testSubtractCarvesAway(): void
    {
        $solid = new BoxSdf(new Vec3(4, 4, 4));        // half-extent 2
        $hole  = new SphereSdf(1.0);                    // radius 1 at origin
        $s = SdfComposite::subtract($solid, $hole);

        // Origin is inside the hole -> outside the result: dist = -(-1) ...
        // subtract = max(box, -sphere); box(0)= -2, -sphere(0)= -(-1)=1 -> max=1 (outside).
        $this->assertEqualsWithDelta(1.0, $s->distance(new Vec3(0, 0, 0)), self::EPS);
        // A point solidly in the box wall, away from the hole, stays inside.
        $this->assertLessThan(0.0, $s->distance(new Vec3(1.5, 1.5, 1.5)));
    }

    public function testSmoothUnionApproachesMinForLargeSeparation(): void
    {
        $a = new SphereSdf(1.0, new Vec3(-5, 0, 0));
        $b = new SphereSdf(1.0, new Vec3(5, 0, 0));
        $sharp = SdfComposite::union($a, $b);
        $smooth = SdfComposite::smoothUnion($a, $b, 0.5);

        // Far from the blend region the smooth union matches the hard min closely.
        $p = new Vec3(-5, 3, 0);
        $this->assertEqualsWithDelta(
            $sharp->distance($p),
            $smooth->distance($p),
            1e-3
        );
    }

    public function testSmoothUnionIsNeverGreaterThanHardUnion(): void
    {
        $a = new SphereSdf(1.0, new Vec3(-0.6, 0, 0));
        $b = new SphereSdf(1.0, new Vec3(0.6, 0, 0));
        $sharp = SdfComposite::union($a, $b);
        $smooth = SdfComposite::smoothUnion($a, $b, 1.0);

        // Smooth-min fills the crease: result <= hard min everywhere it differs.
        foreach ([[0, 0, 0], [0, 1.2, 0], [0.3, 0.3, 0.3]] as $c) {
            $p = new Vec3($c[0], $c[1], $c[2]);
            $this->assertLessThanOrEqual($sharp->distance($p) + self::EPS, $smooth->distance($p));
        }
    }

    public function testUnionAllFoldsPrimitives(): void
    {
        $u = SdfComposite::unionAll(
            new SphereSdf(1.0, new Vec3(-2, 0, 0)),
            new SphereSdf(1.0, new Vec3(0, 0, 0)),
            new SphereSdf(1.0, new Vec3(2, 0, 0)),
        );
        $this->assertEqualsWithDelta(-1.0, $u->distance(new Vec3(0, 0, 0)), self::EPS);
        $this->assertEqualsWithDelta(-1.0, $u->distance(new Vec3(2, 0, 0)), self::EPS);
    }

    public function testUnionBoundsEncloseBothChildren(): void
    {
        $a = new BoxSdf(new Vec3(2, 2, 2), new Vec3(-3, 0, 0));
        $b = new BoxSdf(new Vec3(2, 2, 2), new Vec3(3, 0, 0));
        [$min, $max] = SdfComposite::union($a, $b)->bounds();
        $this->assertEqualsWithDelta(-4.0, $min->x, self::EPS);
        $this->assertEqualsWithDelta(4.0, $max->x, self::EPS);
    }

    public function testUnionWithUnboundedChildIsUnbounded(): void
    {
        $u = SdfComposite::union(new SphereSdf(1.0), new PlaneSdf());
        $this->assertNull($u->bounds());
    }

    public function testSubtractBoundsFollowLeftOperand(): void
    {
        $solid = new BoxSdf(new Vec3(2, 2, 2));
        $hole  = new SphereSdf(5.0); // larger than the box
        [$min, $max] = SdfComposite::subtract($solid, $hole)->bounds();
        // Carving never grows extent: bounds == the box's.
        $this->assertTrue($min->equals(new Vec3(-1, -1, -1)));
        $this->assertTrue($max->equals(new Vec3(1, 1, 1)));
    }
}
