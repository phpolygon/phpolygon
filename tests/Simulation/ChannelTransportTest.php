<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Simulation;

use PHPUnit\Framework\TestCase;
use PHPolygon\ECS\World;
use PHPolygon\Simulation\ChannelTransport;
use PHPolygon\Simulation\FarSimulationRunner;
use PHPolygon\Simulation\FarSimulationSubsystem;
use PHPolygon\Simulation\InlineTransport;
use PHPolygon\Thread\ThreadScheduler;

/**
 * Phase 6 of the visibility plan: ChannelTransport runs a SimulationKernel's
 * step() on a real worker thread via FarSimulationSubsystem/ThreadScheduler.
 * The required guarantee (the plan's own wording): for the same records and
 * dt, InlineTransport and ChannelTransport import an IDENTICAL state -
 * Phase 4's determinism contract extended to the threaded transport.
 *
 * All real-threading tests require ZTS PHP with ext-parallel - the only
 * configuration where ThreadScheduler (not NullThreadScheduler) is used.
 */
final class ChannelTransportTest extends TestCase
{
    /**
     * Safety net mirroring ThreadSchedulerHardeningTest's own: an orphaned
     * worker this test spawned but never shut down can block the whole
     * PHPUnit process on its `_in` channel's recv(), turning one failed
     * assertion into the entire suite hanging.
     *
     * @var list<ThreadScheduler>
     */
    private array $activeSchedulers = [];

    protected function tearDown(): void
    {
        foreach ($this->activeSchedulers as $scheduler) {
            try {
                $scheduler->shutdown();
            } catch (\Throwable) {
                // Best effort - never mask the test's own failure with this.
            }
        }
        $this->activeSchedulers = [];
    }

    private function skipWithoutRealThreading(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }
    }

    private function bootedScheduler(string $name = 'far'): ThreadScheduler
    {
        $scheduler = new ThreadScheduler(1);
        $this->activeSchedulers[] = $scheduler;
        $scheduler->register($name, FarSimulationSubsystem::class);
        $scheduler->boot();
        return $scheduler;
    }

    public function testChannelTransportRoundTripsThroughARealWorkerThread(): void
    {
        $this->skipWithoutRealThreading();
        $scheduler = $this->bootedScheduler();
        $world = new World();
        $transport = new ChannelTransport($scheduler, 'far', $world);

        $result = $transport->step(new GrowthTestKernel(), [1 => ['height' => 0.0, 'rate' => 2.0]], 0.1);

        self::assertEqualsWithDelta(0.2, $result[1]['height'], 1e-12);
    }

    public function testInlineAndChannelTransportProduceAnIdenticalResultForTheSameInput(): void
    {
        $this->skipWithoutRealThreading();
        $records = [5 => ['height' => 3.0, 'rate' => 1.5], 2 => ['height' => -1.0, 'rate' => 0.5]];
        $kernel = new GrowthTestKernel();

        $inline = new InlineTransport();
        $inlineResult = $inline->step($kernel, $records, 0.1);

        $scheduler = $this->bootedScheduler();
        $world = new World();
        $channel = new ChannelTransport($scheduler, 'far', $world);
        $channelResult = $channel->step($kernel, $records, 0.1);

        self::assertSame($inlineResult, $channelResult);
    }

    public function testFarSimulationRunnerWithChannelTransportMatchesInlineOverSeveralTicks(): void
    {
        $this->skipWithoutRealThreading();
        $world = new World();

        $inlineRunner = new FarSimulationRunner();
        $inlineRunner->register('growth', new GrowthTestKernel(), new InlineTransport());
        $inlineRunner->handover('growth', $world, 1);

        $scheduler = $this->bootedScheduler();
        $channelRunner = new FarSimulationRunner();
        $channelRunner->register('growth', new GrowthTestKernel(), new ChannelTransport($scheduler, 'far', $world));
        $channelRunner->handover('growth', $world, 1);

        for ($i = 0; $i < 7; $i++) {
            $inlineRunner->tick(FarSimulationRunner::FIXED_DT);
            $channelRunner->tick(FarSimulationRunner::FIXED_DT);
        }

        self::assertSame($inlineRunner->recordOf('growth', 1), $channelRunner->recordOf('growth', 1));
    }

    /**
     * The plan's "Snapshot beim Speichern": a record still handed over to
     * Far must be fully caught up and imported before a save, even though
     * doing so means blocking on the worker thread once more via
     * ChannelTransport - the same synchronous flush takeback() already does
     * for InlineTransport.
     */
    public function testTakebackAllFlushesAPendingRecordEvenThroughTheWorkerThread(): void
    {
        $this->skipWithoutRealThreading();
        $world = new World();
        $scheduler = $this->bootedScheduler();
        $runner = new FarSimulationRunner();
        $runner->register('growth', new GrowthTestKernel(), new ChannelTransport($scheduler, 'far', $world));
        $runner->handover('growth', $world, 1);
        $runner->tick(FarSimulationRunner::FIXED_DT * 3);

        self::assertNotNull($runner->recordOf('growth', 1), 'still handed over before the snapshot');

        $runner->takebackAll($world);

        self::assertFalse($runner->isHandedOver('growth', 1), 'snapshot-time takeback must have dropped it');
    }

    public function testChannelTransportThrowsWhenTheWorkerDoesNotAnswerInTime(): void
    {
        $this->skipWithoutRealThreading();
        $scheduler = $this->bootedScheduler();
        $world = new World();
        // timeoutMicros: 0 - a genuinely non-blocking check, immediately
        // after sendAll(): the worker cannot possibly have answered yet.
        $transport = new ChannelTransport($scheduler, 'far', $world, timeoutMicros: 0);

        $this->expectException(\RuntimeException::class);
        $transport->step(new GrowthTestKernel(), [1 => ['height' => 0.0, 'rate' => 1.0]], 0.1);
    }

    public function testConstructingWithAnUnregisteredNameThrows(): void
    {
        $this->skipWithoutRealThreading();
        $scheduler = $this->bootedScheduler();
        $world = new World();

        $this->expectException(\InvalidArgumentException::class);
        new ChannelTransport($scheduler, 'not-registered', $world);
    }
}
