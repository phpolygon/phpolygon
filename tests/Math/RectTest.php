<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Math;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;

class RectTest extends TestCase
{
    public function testContains(): void
    {
        $r = new Rect(0, 0, 10, 10);
        $this->assertTrue($r->contains(new Vec2(5, 5)));
        $this->assertTrue($r->contains(new Vec2(0, 0)));
        $this->assertTrue($r->contains(new Vec2(10, 10)));
        $this->assertFalse($r->contains(new Vec2(11, 5)));
        $this->assertFalse($r->contains(new Vec2(-1, 5)));
    }

    public function testIntersects(): void
    {
        $a = new Rect(0, 0, 10, 10);
        $b = new Rect(5, 5, 10, 10);
        $c = new Rect(20, 20, 5, 5);
        $this->assertTrue($a->intersects($b));
        $this->assertFalse($a->intersects($c));
    }

    public function testCenter(): void
    {
        $r = new Rect(10, 20, 30, 40);
        $center = $r->center();
        $this->assertTrue($center->equals(new Vec2(25, 40)));
    }

    public function testIntersection(): void
    {
        $a = new Rect(0, 0, 10, 10);
        $b = new Rect(5, 5, 10, 10);
        $intersection = $a->intersection($b);
        $this->assertNotNull($intersection);
        $this->assertTrue($intersection->equals(new Rect(5, 5, 5, 5)));
    }

    public function testNoIntersection(): void
    {
        $a = new Rect(0, 0, 5, 5);
        $b = new Rect(10, 10, 5, 5);
        $this->assertNull($a->intersection($b));
    }
}
