<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\MetalCubemapTarget;

/**
 * Static-only tests for the cubemap target. Allocation, render loop, and
 * Metal device interactions are covered by an end-to-end Metal smoke
 * test; here we lock down the view-matrix / projection-matrix maths that
 * drive the six face passes, since those are computed in pure PHP and
 * easy to regress without realising.
 */
final class MetalCubemapTargetTest extends TestCase
{
    public function testFaceViewMatricesReturnSixOrthogonalMatrices(): void
    {
        $matrices = MetalCubemapTarget::faceViewMatrices();
        $this->assertCount(6, $matrices);

        foreach ($matrices as $face => $m) {
            $this->assertCount(16, $m, "face {$face} must be float[16]");
            // Bottom-right element is always 1.0 for a homogeneous transform
            // with no perspective component.
            $this->assertEqualsWithDelta(1.0, $m[15], 1e-6, "face {$face} m[15]");
            // Translation column zero (sky has no translation).
            $this->assertEqualsWithDelta(0.0, $m[12], 1e-6, "face {$face} m[12]");
            $this->assertEqualsWithDelta(0.0, $m[13], 1e-6, "face {$face} m[13]");
            $this->assertEqualsWithDelta(0.0, $m[14], 1e-6, "face {$face} m[14]");
        }
    }

    public function testFaceViewMatricesAreOrthonormal(): void
    {
        $matrices = MetalCubemapTarget::faceViewMatrices();
        foreach ($matrices as $face => $m) {
            // Each of the upper-left 3 columns must have unit length and
            // be orthogonal to the others. Column n in column-major layout
            // is m[n*4..n*4+2].
            $cols = [
                [$m[0], $m[1], $m[2]],
                [$m[4], $m[5], $m[6]],
                [$m[8], $m[9], $m[10]],
            ];
            foreach ($cols as $i => $c) {
                $len = sqrt($c[0] * $c[0] + $c[1] * $c[1] + $c[2] * $c[2]);
                $this->assertEqualsWithDelta(1.0, $len, 1e-5, "face {$face} col {$i} length");
            }
            // Pairwise orthogonality.
            $dot01 = $cols[0][0] * $cols[1][0] + $cols[0][1] * $cols[1][1] + $cols[0][2] * $cols[1][2];
            $dot02 = $cols[0][0] * $cols[2][0] + $cols[0][1] * $cols[2][1] + $cols[0][2] * $cols[2][2];
            $dot12 = $cols[1][0] * $cols[2][0] + $cols[1][1] * $cols[2][1] + $cols[1][2] * $cols[2][2];
            $this->assertEqualsWithDelta(0.0, $dot01, 1e-5, "face {$face} cols 0·1");
            $this->assertEqualsWithDelta(0.0, $dot02, 1e-5, "face {$face} cols 0·2");
            $this->assertEqualsWithDelta(0.0, $dot12, 1e-5, "face {$face} cols 1·2");
        }
    }

    public function testFaceProjectionMatrixIs90DegreeFovAspect1(): void
    {
        $proj = MetalCubemapTarget::faceProjectionMatrix();
        $this->assertCount(16, $proj);
        // 90° FOV -> fx = fy = 1/tan(45°) = 1.0
        // Z-corrected for Metal clip space (z' = z*0.5 + 0.5), so the raw
        // fx/fy values still appear at m[0] / m[5] (they are unaffected by
        // the clip multiply because the multiplier is identity in xy).
        $this->assertEqualsWithDelta(1.0, $proj[0], 1e-6, 'fx');
        $this->assertEqualsWithDelta(1.0, $proj[5], 1e-6, 'fy');
    }

    public function testFaceConstantsMatchExpectedDefaults(): void
    {
        // Hard-coded so the MSL shader and its mip-LOD assumptions stay in
        // sync. If FACE_SIZE changes, also bump environment_mip_max in the
        // mesh3d shader's expected range.
        $reflection = new \ReflectionClass(MetalCubemapTarget::class);
        $faceSize  = $reflection->getConstant('FACE_SIZE');
        $mipLevels = $reflection->getConstant('MIP_LEVELS');

        $this->assertSame(256, $faceSize);
        $this->assertSame((int)floor(log($faceSize, 2)) + 1, $mipLevels);
    }
}
