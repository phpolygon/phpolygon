<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Math\Vec4;
use PHPolygon\Rendering\Quality\TaaJitter;
use PHPolygon\Rendering\Quality\TemporalJitter;
use PHPUnit\Framework\TestCase;

/**
 * Sub-pixel camera jitter for temporal anti-aliasing / upscaling: how many
 * phases the sequence needs for a given upscale ratio, and a jittered copy of
 * the projection that shifts every vertex by exactly the offset in render
 * pixels while the unjittered matrices stay available for motion vectors.
 */
final class TemporalJitterTest extends TestCase
{
    public function testPhaseCountGrowsWithTheSquaredUpscaleRatio(): void
    {
        $this->assertSame(8, TemporalJitter::phaseCount(1920, 1920), 'native: 8 phases');
        $this->assertSame(18, TemporalJitter::phaseCount(1280, 1920), 'quality 1.5x');
        $this->assertSame(32, TemporalJitter::phaseCount(960, 1920), 'performance 2x');
        $this->assertSame(72, TemporalJitter::phaseCount(640, 1920), 'ultra performance 3x');
        $this->assertSame(24, TemporalJitter::phaseCount(1129, 1920), 'balanced 1.7x: ceil(8 * 1.7^2)');
        $this->assertGreaterThanOrEqual(1, TemporalJitter::phaseCount(3840, 1920));
        $this->assertSame(8, TemporalJitter::phaseCount(0, 0), 'degenerate sizes do not divide by zero');
    }

    public function testPixelOffsetsAreHaltonCentredAndPeriodic(): void
    {
        $phases = TemporalJitter::phaseCount(960, 1920);
        $sumX = 0.0;
        $sumY = 0.0;
        for ($i = 0; $i < $phases; $i++) {
            [$x, $y] = TemporalJitter::pixelOffset($i, $phases);
            $this->assertGreaterThanOrEqual(-0.5, $x);
            $this->assertLessThan(0.5, $x);
            $this->assertGreaterThanOrEqual(-0.5, $y);
            $this->assertLessThan(0.5, $y);
            $this->assertEqualsWithDelta(TaaJitter::halton($i + 1, 2) - 0.5, $x, 1e-12);
            $this->assertEqualsWithDelta(TaaJitter::halton($i + 1, 3) - 0.5, $y, 1e-12);
            $this->assertSame([$x, $y], TemporalJitter::pixelOffset($i + $phases, $phases));
            $sumX += $x;
            $sumY += $y;
        }
        $this->assertEqualsWithDelta(0.0, $sumX / $phases, 0.05, 'no bias across one period');
        $this->assertEqualsWithDelta(0.0, $sumY / $phases, 0.05);
    }

    public function testJitteredPerspectiveShiftsByExactlyTheOffsetInPixels(): void
    {
        $view = Mat4::lookAt(new Vec3(3.0, 2.0, 5.0), new Vec3(0.0, 0.5, 0.0), new Vec3(0.0, 1.0, 0.0));
        $projection = Mat4::perspective(deg2rad(60.0), 16.0 / 9.0, 0.1, 500.0);
        $this->assertShiftIsExact($view, $projection);
    }

    public function testJitteredOrthographicShiftsByExactlyTheOffsetInPixels(): void
    {
        $view = Mat4::lookAt(new Vec3(10.0, 10.0, 10.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0));
        $projection = Mat4::orthographic(-16.0, 16.0, -9.0, 9.0, 0.1, 100.0);
        $this->assertShiftIsExact($view, $projection);
    }

    public function testUnjitteredMatricesStaySeparateAndTheInputIsUntouched(): void
    {
        $view = Mat4::lookAt(new Vec3(0.0, 1.0, 4.0), new Vec3(0.0, 0.0, 0.0), new Vec3(0.0, 1.0, 0.0));
        $projection = Mat4::perspective(deg2rad(70.0), 1.5, 0.1, 100.0);
        $before = $projection->toArray();

        $j = TemporalJitter::jitter($view, $projection, 3, 960, 640, 1920);

        $this->assertSame($before, $projection->toArray(), 'jitter works on a copy');
        $this->assertSame($projection, $j->unjitteredProjection);
        $this->assertSame($projection->multiply($view)->toArray(), $j->unjitteredViewProjection->toArray());
        $this->assertNotSame($before, $j->projection->toArray());
        $this->assertSame(32, $j->phaseCount);
        $this->assertSame(3, $j->phase);
        $this->assertSame(TemporalJitter::pixelOffset(3, 32), [$j->pixelX, $j->pixelY]);
        $this->assertEqualsWithDelta(2.0 * $j->pixelX / 960.0, $j->ndcX, 1e-12);
        $this->assertEqualsWithDelta(2.0 * $j->pixelY / 640.0, $j->ndcY, 1e-12);
    }

    public function testDisabledJitterReturnsTheProjectionUnchanged(): void
    {
        $view = Mat4::identity();
        $projection = Mat4::perspective(deg2rad(70.0), 1.5, 0.1, 100.0);

        $j = TemporalJitter::none($view, $projection);

        $this->assertSame($projection, $j->projection);
        $this->assertSame($projection, $j->unjitteredProjection);
        $this->assertSame(0.0, $j->ndcX);
        $this->assertSame(0.0, $j->ndcY);
    }

    private function assertShiftIsExact(Mat4 $view, Mat4 $projection): void
    {
        $renderW = 1280;
        $renderH = 720;
        $points = [new Vec3(0.0, 0.0, 0.0), new Vec3(1.5, 2.0, -3.0), new Vec3(-4.0, 0.5, 2.0)];

        for ($frame = 0; $frame < 5; $frame++) {
            $j = TemporalJitter::jitter($view, $projection, $frame, $renderW, $renderH, 1920);
            $jitteredVp = $j->projection->multiply($view);
            foreach ($points as $p) {
                $plain = $this->ndc($j->unjitteredViewProjection, $p);
                $shifted = $this->ndc($jitteredVp, $p);
                $this->assertEqualsWithDelta(2.0 * $j->pixelX / $renderW, $shifted[0] - $plain[0], 1e-9);
                $this->assertEqualsWithDelta(2.0 * $j->pixelY / $renderH, $shifted[1] - $plain[1], 1e-9);
                $this->assertEqualsWithDelta($plain[2], $shifted[2], 1e-9, 'depth is not jittered');
            }
        }
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function ndc(Mat4 $viewProjection, Vec3 $p): array
    {
        $c = $viewProjection->multiplyVec4(new Vec4($p->x, $p->y, $p->z, 1.0));
        return [$c->x / $c->w, $c->y / $c->w, $c->z / $c->w];
    }
}
