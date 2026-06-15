<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Fieldtracing;

use PHPolygon\Fieldtracing\Sdf\BoxSdf;
use PHPolygon\Fieldtracing\Sdf\CylinderSdf;
use PHPolygon\Fieldtracing\Sdf\PlaneSdf;
use PHPolygon\Fieldtracing\Sdf\SphereSdf;
use PHPolygon\Math\Vec3;
use PHPUnit\Framework\TestCase;

/**
 * Analytic SDF primitives verified against hand-computed distances. This is the
 * highest-value, GPU-free test layer for Fieldtracing (PHPOLYGON_FIELDTRACING.md
 * §10): a known point has a known distance to a known surface.
 */
class SdfPrimitiveTest extends TestCase
{
    private const EPS = 1e-6;

    public function testSphereDistances(): void
    {
        $s = new SphereSdf(2.0); // radius 2 at origin

        $this->assertEqualsWithDelta(-2.0, $s->distance(new Vec3(0, 0, 0)), self::EPS, 'centre is -radius');
        $this->assertEqualsWithDelta(0.0, $s->distance(new Vec3(2, 0, 0)), self::EPS, 'on surface');
        $this->assertEqualsWithDelta(3.0, $s->distance(new Vec3(5, 0, 0)), self::EPS, 'outside along +x');
        $this->assertEqualsWithDelta(-1.0, $s->distance(new Vec3(1, 0, 0)), self::EPS, 'inside');
    }

    public function testSphereOffCenter(): void
    {
        $s = new SphereSdf(1.0, new Vec3(10, 0, 0));
        $this->assertEqualsWithDelta(4.0, $s->distance(new Vec3(5, 0, 0)), self::EPS);
    }

    public function testBoxDistances(): void
    {
        $b = new BoxSdf(new Vec3(2, 2, 2)); // half-extent 1 each axis

        $this->assertEqualsWithDelta(-1.0, $b->distance(new Vec3(0, 0, 0)), self::EPS, 'centre');
        $this->assertEqualsWithDelta(0.0, $b->distance(new Vec3(1, 0, 0)), self::EPS, 'face centre');
        $this->assertEqualsWithDelta(2.0, $b->distance(new Vec3(3, 0, 0)), self::EPS, 'outside along face normal');

        // Corner-diagonal point: nearest corner is (1,1,1), offset (1,1,1).
        $this->assertEqualsWithDelta(sqrt(3.0), $b->distance(new Vec3(2, 2, 2)), self::EPS, 'corner diagonal');
    }

    public function testCylinderDistances(): void
    {
        $c = new CylinderSdf(2.0, 4.0); // radius 2, full height 4 (half-height 2), Y axis

        $this->assertEqualsWithDelta(-2.0, $c->distance(new Vec3(0, 0, 0)), self::EPS, 'axis centre = -min(r,hh)');
        $this->assertEqualsWithDelta(0.0, $c->distance(new Vec3(2, 0, 0)), self::EPS, 'on radial surface');
        $this->assertEqualsWithDelta(0.0, $c->distance(new Vec3(0, 2, 0)), self::EPS, 'on cap');
        $this->assertEqualsWithDelta(3.0, $c->distance(new Vec3(5, 0, 0)), self::EPS, 'radial outside');
        $this->assertEqualsWithDelta(1.0, $c->distance(new Vec3(0, 3, 0)), self::EPS, 'above cap');
    }

    public function testPlaneDistances(): void
    {
        $p = new PlaneSdf(new Vec3(0, 1, 0), 0.0); // ground plane y=0

        $this->assertEqualsWithDelta(5.0, $p->distance(new Vec3(0, 5, 0)), self::EPS, 'above');
        $this->assertEqualsWithDelta(-3.0, $p->distance(new Vec3(0, -3, 0)), self::EPS, 'below');
        $this->assertEqualsWithDelta(0.0, $p->distance(new Vec3(100, 0, 100)), self::EPS, 'on plane anywhere');
        $this->assertNull($p->bounds(), 'plane is unbounded');
    }

    public function testPlaneNormalisesNonUnitNormal(): void
    {
        $p = new PlaneSdf(new Vec3(0, 5, 0), 0.0); // non-unit normal
        $this->assertEqualsWithDelta(2.0, $p->distance(new Vec3(0, 2, 0)), self::EPS);
    }

    public function testBoundsAreCorrect(): void
    {
        $b = new BoxSdf(new Vec3(2, 4, 6), new Vec3(1, 1, 1));
        [$min, $max] = $b->bounds();
        $this->assertTrue($min->equals(new Vec3(0, -1, -2)));
        $this->assertTrue($max->equals(new Vec3(2, 3, 4)));
    }
}
