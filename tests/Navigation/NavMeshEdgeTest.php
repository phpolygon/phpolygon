<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Vec3;
use PHPolygon\Navigation\NavMeshEdge;

class NavMeshEdgeTest extends TestCase
{
    public function testMidpoint(): void
    {
        $edge = new NavMeshEdge(
            new Vec3(0, 0, 0),
            new Vec3(10, 0, 0),
            0,
            1,
        );

        $mid = $edge->midpoint();
        $this->assertEqualsWithDelta(5.0, $mid->x, 1e-6);
    }

    public function testWidth(): void
    {
        $edge = new NavMeshEdge(
            new Vec3(0, 0, 0),
            new Vec3(3, 0, 4),
            0,
            1,
        );

        $this->assertEqualsWithDelta(5.0, $edge->width(), 1e-6);
    }

    public function testSerializationRoundtrip(): void
    {
        $edge = new NavMeshEdge(
            new Vec3(1, 2, 3),
            new Vec3(4, 5, 6),
            10,
            20,
        );

        $restored = NavMeshEdge::fromArray($edge->toArray());
        $this->assertTrue($restored->left->equals(new Vec3(1, 2, 3)));
        $this->assertTrue($restored->right->equals(new Vec3(4, 5, 6)));
        $this->assertSame(10, $restored->polygonA);
        $this->assertSame(20, $restored->polygonB);
    }
}
