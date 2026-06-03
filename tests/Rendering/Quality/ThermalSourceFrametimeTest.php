<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Quality;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Quality\PressureSignal;
use PHPolygon\Rendering\Quality\ThermalSourceFrametime;

final class ThermalSourceFrametimeTest extends TestCase
{
    public function testWarmupSuppressesEarlySpikes(): void
    {
        $src = new ThermalSourceFrametime(warmupFrames: 120);
        for ($i = 0; $i < 119; $i++) {
            $this->assertSame(PressureSignal::Nominal, $src->update(100.0, (float) $i, 60.0));
        }
    }

    public function testHealthyFrametimesStayNominal(): void
    {
        $src = new ThermalSourceFrametime(warmupFrames: 0);
        $signal = PressureSignal::Nominal;
        for ($i = 0; $i < 600; $i++) {
            $signal = $src->update(16.6, $i / 60.0, 60.0);
        }
        $this->assertSame(PressureSignal::Nominal, $signal);
    }

    public function testSustainedOvershootTriggersFairThenSerious(): void
    {
        $src = new ThermalSourceFrametime(warmupFrames: 0);

        // Fill with healthy samples first.
        for ($i = 0; $i < 200; $i++) {
            $src->update(16.6, $i / 60.0, 60.0);
        }

        // Now sustained at 25 ms (~1.5x budget) for > 3 s.
        $signal = PressureSignal::Nominal;
        for ($i = 0; $i < 400; $i++) {
            $signal = $src->update(25.0, 200.0 / 60.0 + $i / 60.0, 60.0);
        }

        $this->assertContains(
            $signal,
            [PressureSignal::Fair, PressureSignal::Serious],
            'Expected pressure to escalate, got ' . $signal->value,
        );
    }

    public function testExtremeOvershootTriggersCritical(): void
    {
        $src = new ThermalSourceFrametime(warmupFrames: 0);

        // Establish a baseline window so p95 actually reflects the bad frames.
        for ($i = 0; $i < 60; $i++) {
            $src->update(16.6, $i / 60.0, 60.0);
        }
        // Then sustained > 2x budget for > 3 s (~ 33+ ms at 60 fps target).
        $signal = PressureSignal::Nominal;
        for ($i = 0; $i < 500; $i++) {
            $signal = $src->update(40.0, 60.0 / 60.0 + $i / 60.0, 60.0);
        }
        $this->assertSame(PressureSignal::Critical, $signal);
    }

    public function testRecoveryAfterSustainedHealthy(): void
    {
        $src = new ThermalSourceFrametime(warmupFrames: 0);
        // Push into Serious.
        for ($i = 0; $i < 600; $i++) {
            $src->update(30.0, $i / 60.0, 60.0);
        }
        $this->assertNotSame(PressureSignal::Nominal, $src->update(30.0, 10.5, 60.0));

        // Recovery needs (a) the sliding window to flush out the bad
        // samples - 600 frames worth - and (b) another 8 s of sustained
        // sub-budget p95 on top. 1200 healthy frames (= 20 s @ 60 fps)
        // gives both with margin.
        $start = 11.0;
        $signal = PressureSignal::Critical;
        for ($i = 0; $i < 1200; $i++) {
            $signal = $src->update(13.0, $start + $i / 60.0, 60.0); // 13ms < budget*0.9 (15ms)
        }
        $this->assertSame(PressureSignal::Nominal, $signal);
    }

    public function testBudgetIsRelativeToCurrentTargetFps(): void
    {
        // At 144 fps target, budget = 6.94 ms. 16 ms is 2.3x budget -> Critical.
        $src = new ThermalSourceFrametime(warmupFrames: 0);
        for ($i = 0; $i < 60; $i++) {
            $src->update(6.5, $i / 60.0, 144.0);
        }
        $signal = PressureSignal::Nominal;
        for ($i = 0; $i < 500; $i++) {
            $signal = $src->update(16.0, 1.0 + $i / 60.0, 144.0);
        }
        $this->assertSame(PressureSignal::Critical, $signal);
    }
}
