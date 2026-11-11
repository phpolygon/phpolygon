<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Simulation;

use PHPUnit\Framework\TestCase;
use PHPolygon\ECS\World;
use PHPolygon\Simulation\FarSimulationRunner;

/**
 * Phase 6b of the visibility plan: FarSimulationRunner::register(...,
 * async: true) drives a kernel through SimulationTransport::submit()/
 * collect() instead of the blocking step() - one far-tick of latency per
 * completed step, enforced by the Runner's own control flow (see its class
 * doc), not by the transport. These tests use ONLY InlineTransport (no
 * ext-parallel / ThreadScheduler needed) - they prove the Runner's own
 * pipelining and bookkeeping; {@see \PHPolygon\Tests\Simulation\ChannelTransportTest}
 * proves the same contract holds through a real worker thread.
 */
final class AsyncFarSimulationTest extends TestCase
{
    public function testAsyncModeDelaysACompletedStepByExactlyOneTickCall(): void
    {
        $runner = new FarSimulationRunner();
        $kernel = new GrowthKernel();
        $runner->register('growth', $kernel, async: true);
        $world = new World();
        $runner->handover('growth', $world, 1);

        $runner->tick(FarSimulationRunner::FIXED_DT); // submits the first job - nothing collected yet
        self::assertSame(0.0, $runner->recordOf('growth', 1)['height'], 'first call only submits - sync mode would already show the step');

        $runner->tick(FarSimulationRunner::FIXED_DT); // collects the first job, submits the second
        self::assertEqualsWithDelta(FarSimulationRunner::FIXED_DT, $runner->recordOf('growth', 1)['height'], 1e-12);
    }

    public function testAsyncModeCatchesUpABurstWithoutDroppingAnyStep(): void
    {
        $runner = new FarSimulationRunner();
        $kernel = new GrowthKernel();
        $runner->register('growth', $kernel, async: true);
        $world = new World();
        $runner->handover('growth', $world, 1);

        $runner->tick(FarSimulationRunner::FIXED_DT * 10); // far more than MAX_STEPS_PER_TICK (4) worth, in one call
        for ($i = 0; $i < 5; $i++) {
            $runner->tick(0.0); // no new time - just lets the in-flight pipeline drain
        }

        self::assertEqualsWithDelta(10.0 * FarSimulationRunner::FIXED_DT, $runner->recordOf('growth', 1)['height'], 1e-9);
    }

    public function testTakebackDrainsAPendingAsyncJobBeforeReadingTheRecord(): void
    {
        $runner = new FarSimulationRunner();
        $kernel = new GrowthKernel();
        $runner->register('growth', $kernel, async: true);
        $world = new World();
        $runner->handover('growth', $world, 1);

        $runner->tick(FarSimulationRunner::FIXED_DT); // submits one job, nothing collected yet

        $runner->takeback('growth', $world, 1);

        self::assertFalse($runner->isHandedOver('growth', 1));
        self::assertEqualsWithDelta(FarSimulationRunner::FIXED_DT, $kernel->imported[1]['height'], 1e-12, 'the pending job must have been drained, not skipped');
    }

    public function testTakebackAllDrainsEveryKernelsPendingAsyncJobBeforeASave(): void
    {
        $runner = new FarSimulationRunner();
        $kernel = new GrowthKernel();
        $runner->register('growth', $kernel, async: true);
        $world = new World();
        $runner->handover('growth', $world, 1);
        $runner->handover('growth', $world, 2);

        $runner->tick(FarSimulationRunner::FIXED_DT * 3); // submits one job covering both entities

        $runner->takebackAll($world);

        self::assertFalse($runner->isHandedOver('growth', 1));
        self::assertFalse($runner->isHandedOver('growth', 2));
        self::assertEqualsWithDelta(3.0 * FarSimulationRunner::FIXED_DT, $kernel->imported[1]['height'], 1e-9);
        self::assertEqualsWithDelta(3.0 * FarSimulationRunner::FIXED_DT, $kernel->imported[2]['height'], 1e-9);
    }

    /**
     * The same determinism guarantee {@see FarSimulationRunnerTest}'s own
     * chunking test asks of sync mode, extended to async: the authoritative
     * comparison is the IMPORTED record after takeback() - per the parity
     * test's own methodology note (see ChannelTransportTest), comparing
     * recordOf() mid-run would depend on exactly how many tick() calls each
     * run happened to need to drain its own backlog, not on the total
     * elapsed simulated time.
     */
    public function testSameTotalElapsedTimeImportsAnIdenticalRecordRegardlessOfChunkingInAsyncMode(): void
    {
        $world = new World();
        $totalTicks = 13; // deliberately more than MAX_STEPS_PER_TICK (4)

        $runnerA = new FarSimulationRunner();
        $kernelA = new GrowthKernel();
        $runnerA->register('growth', $kernelA, async: true);
        $runnerA->handover('growth', $world, 1);
        $runnerA->tick(FarSimulationRunner::FIXED_DT * $totalTicks);
        $runnerA->takeback('growth', $world, 1);

        $runnerB = new FarSimulationRunner();
        $kernelB = new GrowthKernel();
        $runnerB->register('growth', $kernelB, async: true);
        $runnerB->handover('growth', $world, 1);
        for ($i = 0; $i < $totalTicks; $i++) {
            $runnerB->tick(FarSimulationRunner::FIXED_DT);
        }
        $runnerB->takeback('growth', $world, 1);

        self::assertSame($kernelA->imported[1], $kernelB->imported[1]);
        self::assertEqualsWithDelta($totalTicks * FarSimulationRunner::FIXED_DT, $kernelA->imported[1]['height'], 1e-9);
    }
}
