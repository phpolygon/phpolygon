<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Math;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec2;

class Vec2Test extends TestCase
{
    public function testZero(): void
    {
        $v = Vec2::zero();
        $this->assertEquals(0.0, $v->x);
        $this->assertEquals(0.0, $v->y);
    }

    public function testAdd(): void
    {
        $a = new Vec2(1.0, 2.0);
        $b = new Vec2(3.0, 4.0);
        $result = $a->add($b);
        $this->assertTrue($result->equals(new Vec2(4.0, 6.0)));
    }

    public function testSub(): void
    {
        $a = new Vec2(5.0, 3.0);
        $b = new Vec2(2.0, 1.0);
        $result = $a->sub($b);
        $this->assertTrue($result->equals(new Vec2(3.0, 2.0)));
    }

    public function testMul(): void
    {
        $v = new Vec2(3.0, 4.0);
        $result = $v->mul(2.0);
        $this->assertTrue($result->equals(new Vec2(6.0, 8.0)));
    }

    public function testLength(): void
    {
        $v = new Vec2(3.0, 4.0);
        $this->assertEqualsWithDelta(5.0, $v->length(), 1e-6);
    }

    public function testNormalize(): void
    {
        $v = new Vec2(3.0, 4.0);
        $n = $v->normalize();
        $this->assertEqualsWithDelta(1.0, $n->length(), 1e-6);
        $this->assertEqualsWithDelta(0.6, $n->x, 1e-6);
        $this->assertEqualsWithDelta(0.8, $n->y, 1e-6);
    }

    public function testNormalizeZero(): void
    {
        $v = Vec2::zero();
        $n = $v->normalize();
        $this->assertTrue($n->equals(Vec2::zero()));
    }

    public function testDot(): void
    {
        $a = new Vec2(1.0, 0.0);
        $b = new Vec2(0.0, 1.0);
        $this->assertEqualsWithDelta(0.0, $a->dot($b), 1e-6);
    }

    public function testLerp(): void
    {
        $a = new Vec2(0.0, 0.0);
        $b = new Vec2(10.0, 10.0);
        $result = $a->lerp($b, 0.5);
        $this->assertTrue($result->equals(new Vec2(5.0, 5.0)));
    }

    public function testDistance(): void
    {
        $a = new Vec2(0.0, 0.0);
        $b = new Vec2(3.0, 4.0);
        $this->assertEqualsWithDelta(5.0, $a->distance($b), 1e-6);
    }

    public function testRotate(): void
    {
        $v = new Vec2(1.0, 0.0);
        $rotated = $v->rotate(M_PI / 2); // 90 degrees
        $this->assertEqualsWithDelta(0.0, $rotated->x, 1e-6);
        $this->assertEqualsWithDelta(1.0, $rotated->y, 1e-6);
    }

    public function testToArrayFromArray(): void
    {
        $v = new Vec2(1.5, 2.5);
        $arr = $v->toArray();
        $restored = Vec2::fromArray($arr);
        $this->assertTrue($v->equals($restored));
    }
}
