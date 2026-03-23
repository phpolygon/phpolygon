<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Math;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat3;
use PHPolygon\Math\Vec2;

class Mat3Test extends TestCase
{
    public function testIdentityTransform(): void
    {
        $m = Mat3::identity();
        $p = new Vec2(5.0, 3.0);
        $result = $m->transformPoint($p);
        $this->assertTrue($result->equals($p));
    }

    public function testTranslation(): void
    {
        $m = Mat3::translation(10.0, 20.0);
        $p = new Vec2(1.0, 2.0);
        $result = $m->transformPoint($p);
        $this->assertTrue($result->equals(new Vec2(11.0, 22.0)));
    }

    public function testScaling(): void
    {
        $m = Mat3::scaling(2.0, 3.0);
        $p = new Vec2(4.0, 5.0);
        $result = $m->transformPoint($p);
        $this->assertTrue($result->equals(new Vec2(8.0, 15.0)));
    }

    public function testRotation90(): void
    {
        $m = Mat3::rotation(M_PI / 2);
        $p = new Vec2(1.0, 0.0);
        $result = $m->transformPoint($p);
        $this->assertEqualsWithDelta(0.0, $result->x, 1e-6);
        $this->assertEqualsWithDelta(1.0, $result->y, 1e-6);
    }

    public function testTRS(): void
    {
        $m = Mat3::trs(new Vec2(10.0, 20.0), 0.0, Vec2::one());
        $p = Vec2::zero();
        $result = $m->transformPoint($p);
        $this->assertTrue($result->equals(new Vec2(10.0, 20.0)));
    }

    public function testMultiply(): void
    {
        $t = Mat3::translation(5.0, 0.0);
        $s = Mat3::scaling(2.0, 2.0);
        // Scale then translate: point (1,0) -> scale -> (2,0) -> translate -> (7,0)
        $m = $t->multiply($s);
        $result = $m->transformPoint(new Vec2(1.0, 0.0));
        $this->assertTrue($result->equals(new Vec2(7.0, 0.0)));
    }

    public function testInverse(): void
    {
        $m = Mat3::trs(new Vec2(10.0, 20.0), 0.5, new Vec2(2.0, 3.0));
        $inv = $m->inverse();
        $identity = $m->multiply($inv);

        // Should be close to identity
        $p = new Vec2(7.0, 3.0);
        $result = $identity->transformPoint($p);
        $this->assertEqualsWithDelta($p->x, $result->x, 1e-5);
        $this->assertEqualsWithDelta($p->y, $result->y, 1e-5);
    }
}
