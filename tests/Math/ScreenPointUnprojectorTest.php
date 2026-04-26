<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Math;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\ScreenPointUnprojector;
use PHPolygon\Math\Vec3;

class ScreenPointUnprojectorTest extends TestCase
{
    private function createTestUnprojector(): ScreenPointUnprojector
    {
        $view = Mat4::lookAt(
            new Vec3(0.0, 10.0, 10.0),
            Vec3::zero(),
            new Vec3(0.0, 1.0, 0.0),
        );
        $proj = Mat4::perspective(deg2rad(60.0), 800.0 / 600.0, 0.1, 100.0);

        return new ScreenPointUnprojector($view, $proj, 800, 600);
    }

    public function testScreenCenterCreatesRay(): void
    {
        $unprojector = $this->createTestUnprojector();
        $ray = $unprojector->screenToRay(400.0, 300.0);

        // Ray direction should be normalized
        $this->assertEqualsWithDelta(1.0, $ray->direction->length(), 1e-4);
    }

    public function testScreenToGroundCenter(): void
    {
        $unprojector = $this->createTestUnprojector();
        $point = $unprojector->screenToGround(400.0, 300.0);

        // Looking from (0,10,10) toward origin - center should hit ground near origin
        $this->assertNotNull($point);
        $this->assertEqualsWithDelta(0.0, $point->y, 1e-2);
    }

    public function testScreenToGridCell(): void
    {
        $view = Mat4::lookAt(
            new Vec3(0.0, 50.0, 0.01),
            Vec3::zero(),
            new Vec3(0.0, 1.0, 0.0),
        );
        $proj = Mat4::orthographic(-10.0, 10.0, -10.0, 10.0, 0.1, 100.0);

        $unprojector = new ScreenPointUnprojector($view, $proj, 100, 100);
        $cell = $unprojector->screenToGridCell(50.0, 50.0);

        $this->assertNotNull($cell);
        $this->assertArrayHasKey('x', $cell);
        $this->assertArrayHasKey('z', $cell);
    }

    public function testOrthographicUnprojection(): void
    {
        // Top-down orthographic
        $view = Mat4::lookAt(
            new Vec3(0.0, 50.0, 0.01),
            Vec3::zero(),
            new Vec3(0.0, 1.0, 0.0),
        );
        $proj = Mat4::orthographic(-20.0, 20.0, -15.0, 15.0, 0.1, 100.0);

        $unprojector = new ScreenPointUnprojector($view, $proj, 800, 600);
        $ray = $unprojector->screenToRay(400.0, 300.0);

        // Ray should point roughly downward
        $this->assertLessThan(-0.5, $ray->direction->y);
    }

    public function testScreenCornerCreatesRay(): void
    {
        $unprojector = $this->createTestUnprojector();

        $topLeft = $unprojector->screenToRay(0.0, 0.0);
        $bottomRight = $unprojector->screenToRay(800.0, 600.0);

        // Both should have normalized directions
        $this->assertEqualsWithDelta(1.0, $topLeft->direction->length(), 1e-4);
        $this->assertEqualsWithDelta(1.0, $bottomRight->direction->length(), 1e-4);

        // Directions should differ
        $diff = $topLeft->direction->sub($bottomRight->direction)->length();
        $this->assertGreaterThan(0.01, $diff);
    }
}
