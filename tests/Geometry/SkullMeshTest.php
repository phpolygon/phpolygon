<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Geometry;

use PHPolygon\Geometry\SkullMesh;
use PHPolygon\Geometry\SphereMesh;
use PHPUnit\Framework\TestCase;

final class SkullMeshTest extends TestCase
{
    public function testProducesNonEmptyMesh(): void
    {
        $mesh = SkullMesh::generate();
        $this->assertGreaterThan(0, $mesh->vertexCount());
        $this->assertGreaterThan(0, $mesh->triangleCount());
    }

    public function testTopologyMatchesSphereWithSameStacksAndSlices(): void
    {
        // Skull is sphere-topology with displaced vertices; the vertex and
        // triangle counts must match a sphere of the same resolution so the
        // index buffer stays valid.
        $skull = SkullMesh::generate(0.5, 16, 24);
        $sphere = SphereMesh::generate(0.5, 16, 24);

        $this->assertSame($sphere->vertexCount(), $skull->vertexCount());
        $this->assertSame($sphere->triangleCount(), $skull->triangleCount());
        $this->assertSame(count($sphere->indices), count($skull->indices));
    }

    public function testNormalsAreUnitLength(): void
    {
        $mesh = SkullMesh::generate(0.5, 16, 24);
        $count = $mesh->vertexCount();
        $this->assertGreaterThan(0, $count);

        for ($i = 0; $i < $count; $i++) {
            $nx = $mesh->normals[$i * 3];
            $ny = $mesh->normals[$i * 3 + 1];
            $nz = $mesh->normals[$i * 3 + 2];
            $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
            // Smooth-averaged normals on a closed mesh should be unit-length.
            // Tolerance kept generous to accommodate float accumulation.
            $this->assertEqualsWithDelta(1.0, $len, 1e-6);
        }
    }

    public function testEyeSocketVerticesAreInsetTowardsCenter(): void
    {
        // Compare radii at a sample point that should land inside the eye
        // socket on both meshes. The skull's radius at that direction must
        // be strictly smaller than the sphere's.
        $skull = SkullMesh::generate(0.5, 64, 96);
        $sphere = SphereMesh::generate(0.5, 64, 96);

        $skullEye = self::findVertexNearDirection($skull->vertices, 0.55, 0.10, 0.83);
        $sphereEye = self::findVertexNearDirection($sphere->vertices, 0.55, 0.10, 0.83);

        $this->assertNotNull($skullEye);
        $this->assertNotNull($sphereEye);

        $skullR = sqrt($skullEye[0] ** 2 + $skullEye[1] ** 2 + $skullEye[2] ** 2);
        $sphereR = sqrt($sphereEye[0] ** 2 + $sphereEye[1] ** 2 + $sphereEye[2] ** 2);

        $this->assertLessThan($sphereR, $skullR);
        // The depression should be a noticeable fraction of the sphere
        // radius; a 5% minimum guards against accidental no-op changes.
        $this->assertLessThan($sphereR * 0.95, $skullR);
    }

    public function testBackOfHeadVerticesAreUnaffected(): void
    {
        // The displacement is gated by nz > 0.15; a vertex pointing
        // straight backward (-Z) must be at the canonical sphere radius.
        $skull = SkullMesh::generate(0.5, 32, 48);
        $sphere = SphereMesh::generate(0.5, 32, 48);

        $skullBack = self::findVertexNearDirection($skull->vertices, 0.0, 0.0, -1.0);
        $sphereBack = self::findVertexNearDirection($sphere->vertices, 0.0, 0.0, -1.0);

        $this->assertNotNull($skullBack);
        $this->assertNotNull($sphereBack);

        $skullR = sqrt($skullBack[0] ** 2 + $skullBack[1] ** 2 + $skullBack[2] ** 2);
        $sphereR = sqrt($sphereBack[0] ** 2 + $sphereBack[1] ** 2 + $sphereBack[2] ** 2);

        $this->assertEqualsWithDelta($sphereR, $skullR, 1e-9);
    }

    /**
     * Locate the vertex whose normalised direction is closest to ($tx, $ty, $tz).
     *
     * @param  float[] $vertices
     * @return ?array{0: float, 1: float, 2: float}
     */
    private static function findVertexNearDirection(array $vertices, float $tx, float $ty, float $tz): ?array
    {
        $bestDot = -INF;
        $best = null;
        $count = (int) (count($vertices) / 3);
        $tLen = sqrt($tx * $tx + $ty * $ty + $tz * $tz);
        if ($tLen < 1e-9) {
            return null;
        }
        $tx /= $tLen; $ty /= $tLen; $tz /= $tLen;

        for ($k = 0; $k < $count; $k++) {
            $vx = $vertices[$k * 3];
            $vy = $vertices[$k * 3 + 1];
            $vz = $vertices[$k * 3 + 2];
            $vLen = sqrt($vx * $vx + $vy * $vy + $vz * $vz);
            if ($vLen < 1e-9) {
                continue;
            }
            $dot = ($vx * $tx + $vy * $ty + $vz * $tz) / $vLen;
            if ($dot > $bestDot) {
                $bestDot = $dot;
                $best = [$vx, $vy, $vz];
            }
        }
        return $best;
    }
}
