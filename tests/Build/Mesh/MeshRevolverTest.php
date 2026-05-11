<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build\Mesh;

use PHPUnit\Framework\TestCase;
use PHPolygon\Build\Mesh\MeshRevolver;

final class MeshRevolverTest extends TestCase
{
    public function testTorusProfileProducesRingMesh(): void
    {
        // Closed quad profile in X[10..14], Y[-2..2] - should sweep a tire-
        // like torus when revolved.
        $profile = [
            [10.0, -2.0],
            [14.0, -2.0],
            [14.0,  2.0],
            [10.0,  2.0],
            [10.0, -2.0],
        ];
        $revolver = new MeshRevolver();
        $mesh = $revolver->revolve($profile, segments: 16);

        $this->assertGreaterThan(0, $mesh->triangleCount());

        // Compute radius range to verify it's a torus, not a disc.
        $minR = INF; $maxR = -INF;
        for ($i = 0; $i < count($mesh->vertices); $i += 3) {
            $r = sqrt($mesh->vertices[$i]**2 + $mesh->vertices[$i+2]**2);
            $minR = min($minR, $r);
            $maxR = max($maxR, $r);
        }
        // Inner radius preserved (within numerical error).
        $this->assertEqualsWithDelta(10.0, $minR, 0.001);
        $this->assertEqualsWithDelta(14.0, $maxR, 0.001);
    }

    public function testProfileTouchingAxisProducesSolidOfRevolution(): void
    {
        // Triangle profile with one vertex on the axis - should produce a
        // closed cone (no central hole).
        $profile = [
            [0.0, 0.0],
            [5.0, 5.0],
            [0.0, 10.0],
        ];
        $revolver = new MeshRevolver();
        $mesh = $revolver->revolve($profile, segments: 16);

        $this->assertGreaterThan(0, $mesh->triangleCount());

        // Some vertices are on the axis (radius ≈ 0).
        $hasAxisVertex = false;
        for ($i = 0; $i < count($mesh->vertices); $i += 3) {
            $r = sqrt($mesh->vertices[$i]**2 + $mesh->vertices[$i+2]**2);
            if ($r < 0.001) { $hasAxisVertex = true; break; }
        }
        $this->assertTrue($hasAxisVertex);
    }

    public function testSegmentCountControlsSmoothness(): void
    {
        $profile = [[5.0, 0.0], [5.0, 1.0]];
        $low  = (new MeshRevolver())->revolve($profile, segments: 4);
        $high = (new MeshRevolver())->revolve($profile, segments: 64);

        $this->assertGreaterThan($low->vertexCount(), $high->vertexCount());
    }

    public function testSegmentCountClampsToMinimumThree(): void
    {
        $profile = [[5.0, 0.0], [5.0, 1.0]];
        $clamped = (new MeshRevolver())->revolve($profile, segments: 1);
        // Even with segments=1, we get at least 3-segment ring (clamped).
        $this->assertGreaterThan(0, $clamped->triangleCount());
    }

    public function testEmptyProfileReturnsEmptyMesh(): void
    {
        $mesh = (new MeshRevolver())->revolve([], segments: 16);
        $this->assertSame(0, $mesh->vertexCount());
        $this->assertSame(0, $mesh->triangleCount());
    }
}
