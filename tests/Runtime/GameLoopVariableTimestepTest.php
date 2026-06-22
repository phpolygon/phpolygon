<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use PHPolygon\Runtime\GameLoop;

class GameLoopVariableTimestepTest extends TestCase
{
    public function testRunsExactlyOneUpdatePerRenderedFrame(): void
    {
        // Variable timestep couples the sim to the render: every rendered frame
        // is preceded by exactly one update (no fixed-step accumulator), so the
        // sim rate equals the render rate and motion never steps.
        $loop = new GameLoop(60.0, variableTimestep: true);

        $updates = 0;
        $renders = [];
        $frames = 0;

        $loop->run(
            function (float $dt) use (&$updates) {
                $updates++;
            },
            function (float $interpolation) use (&$renders) {
                $renders[] = $interpolation;
            },
            function () use (&$frames): bool {
                return ++$frames > 5; // run 5 frames then stop
            },
        );

        $this->assertSame(5, $updates);
        $this->assertCount(5, $renders);
    }

    public function testRenderInterpolationIsAlwaysOne(): void
    {
        // The rendered state IS the just-updated state, so there is no sub-frame
        // to interpolate — render() must always receive 1.0.
        $loop = new GameLoop(60.0, variableTimestep: true);

        $interps = [];
        $frames = 0;

        $loop->run(
            function (float $dt) {},
            function (float $interpolation) use (&$interps) {
                $interps[] = $interpolation;
            },
            function () use (&$frames): bool {
                return ++$frames > 4;
            },
        );

        $this->assertNotEmpty($interps);
        foreach ($interps as $interpolation) {
            $this->assertSame(1.0, $interpolation);
        }
    }

    public function testDtIsRealFrameTimeClampedToTheSpikeGuard(): void
    {
        // dt is the real elapsed frame time (positive), clamped so a one-off stall
        // can't inject a huge step that tunnels physics. The clamp ceiling is ~1/14 s.
        $loop = new GameLoop(60.0, variableTimestep: true);

        $dts = [];
        $frames = 0;

        $loop->run(
            function (float $dt) use (&$dts) {
                $dts[] = $dt;
            },
            function (float $interpolation) {},
            function () use (&$frames): bool {
                return ++$frames > 4;
            },
        );

        $this->assertNotEmpty($dts);
        foreach ($dts as $dt) {
            $this->assertGreaterThanOrEqual(0.0, $dt);
            $this->assertLessThanOrEqual(1.0 / 14.0 + 1e-9, $dt);
        }
    }
}
