<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPolygon\Rendering\Quality\TaaJitter;
use PHPUnit\Framework\TestCase;

final class TaaJitterTest extends TestCase
{
    public function testHaltonBase2KnownPrefix(): void
    {
        // Standard Halton(2): 1/2, 1/4, 3/4, 1/8, 5/8, 3/8, 7/8, ...
        $expected = [0.5, 0.25, 0.75, 0.125, 0.625, 0.375, 0.875];
        foreach ($expected as $i => $v) {
            $this->assertEqualsWithDelta($v, TaaJitter::halton($i + 1, 2), 1e-9);
        }
    }

    public function testHaltonBase3KnownPrefix(): void
    {
        $expected = [1 / 3, 2 / 3, 1 / 9, 4 / 9, 7 / 9];
        foreach ($expected as $i => $v) {
            $this->assertEqualsWithDelta($v, TaaJitter::halton($i + 1, 3), 1e-9);
        }
    }

    public function testOffsetIsCenteredAndScaled(): void
    {
        [$ox, $oy] = TaaJitter::offset(0, 1920, 1080);
        // Offset is in [-0.5/w, +0.5/w] range.
        $this->assertGreaterThan(-1.0 / 1920.0, $ox);
        $this->assertLessThan( 1.0 / 1920.0, $ox);
        $this->assertGreaterThan(-1.0 / 1080.0, $oy);
        $this->assertLessThan( 1.0 / 1080.0, $oy);
    }

    public function testOffsetCyclesAfterSampleCount(): void
    {
        [$ax, $ay] = TaaJitter::offset(0, 1920, 1080, sampleCount: 8);
        [$bx, $by] = TaaJitter::offset(8, 1920, 1080, sampleCount: 8);
        $this->assertEqualsWithDelta($ax, $bx, 1e-9);
        $this->assertEqualsWithDelta($ay, $by, 1e-9);
    }

    public function testOffsetAveragesNearZeroOverFullPattern(): void
    {
        $sx = 0.0;
        $sy = 0.0;
        for ($i = 0; $i < 8; $i++) {
            [$x, $y] = TaaJitter::offset($i, 1920, 1080, sampleCount: 8);
            $sx += $x;
            $sy += $y;
        }
        // Centred Halton averages to ~0; not exactly zero with finite N
        // but close (within 1 / (rt-dim) bound, since each sample is
        // already < 0.5 / rt-dim).
        $this->assertLessThan(1.0 / 1920.0, abs($sx));
        $this->assertLessThan(1.0 / 1080.0, abs($sy));
    }
}
