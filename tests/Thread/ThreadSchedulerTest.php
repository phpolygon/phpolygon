<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread;

use PHPUnit\Framework\TestCase;
use PHPolygon\ECS\World;
use PHPolygon\Thread\NullThreadScheduler;
use PHPolygon\Thread\ThreadScheduler;
use PHPolygon\Thread\SubsystemInterface;
use PHPolygon\Thread\ThreadSchedulerFactory;
use PHPolygon\EngineConfig;
use PHPolygon\Thread\ThreadingMode;

class PingSubsystem implements SubsystemInterface
{
    public function prepareInput(World $world, float $dt): array
    {
        return ['dt' => $dt, 'entityCount' => $world->entityCount()];
    }

    public function applyDeltas(World $world, array $deltas): void
    {
        // No-op for test — just verify deltas are received
    }

    public static function threadEntry(string $channelPrefix): void
    {
        $in = \parallel\Channel::open("{$channelPrefix}_in");
        $out = \parallel\Channel::open("{$channelPrefix}_out");

        while (true) {
            $input = $in->recv();
            if ($input === null) {
                break;
            }
            $out->send(self::compute($input));
        }
    }

    public static function compute(array $input): array
    {
        return ['pong' => true, 'dt' => $input['dt'] * 2];
    }
}

class ThreadSchedulerTest extends TestCase
{
    public function testNullSchedulerProcessesSynchronously(): void
    {
        $scheduler = new NullThreadScheduler();
        $scheduler->register('ping', PingSubsystem::class);
        $scheduler->boot();

        $world = new World();
        $world->createEntity();
        $world->createEntity();

        $inputs = $scheduler->sendAll($world, 0.016);

        $this->assertArrayHasKey('ping', $inputs);
        $this->assertSame(0.016, $inputs['ping']['dt']);
        $this->assertSame(2, $inputs['ping']['entityCount']);

        // recvAll computes and applies synchronously
        $scheduler->recvAll($world);

        $scheduler->shutdown();
    }

    public function testNullSchedulerIsAlwaysBooted(): void
    {
        $scheduler = new NullThreadScheduler();
        $this->assertTrue($scheduler->isBooted());
        $this->assertSame(1, $scheduler->getCoreCount());
    }

    public function testNullSchedulerRegisterAndShutdown(): void
    {
        $scheduler = new NullThreadScheduler();
        $scheduler->register('ping', PingSubsystem::class);

        $this->assertCount(1, $scheduler->getSubsystems());

        $scheduler->shutdown();
        $this->assertCount(0, $scheduler->getSubsystems());
    }

    public function testFactoryReturnsSingleThreadedWhenForced(): void
    {
        $config = new EngineConfig(threadingMode: ThreadingMode::SingleThreaded);
        $scheduler = ThreadSchedulerFactory::create($config);

        $this->assertInstanceOf(NullThreadScheduler::class, $scheduler);
    }

    public function testFactoryAutoDetects(): void
    {
        $config = new EngineConfig();
        $scheduler = ThreadSchedulerFactory::create($config);

        // Without parallel extension, should fall back to NullThreadScheduler;
        // with it (ZTS + ext-parallel) the factory should pick a real one.
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->assertInstanceOf(NullThreadScheduler::class, $scheduler);
        } else {
            $this->assertNotInstanceOf(NullThreadScheduler::class, $scheduler);
        }
    }

    public function testNullSchedulerRoundTripWithWorld(): void
    {
        $scheduler = new NullThreadScheduler();
        $scheduler->register('ping', PingSubsystem::class);
        $scheduler->boot();

        $world = new World();

        // Simulate 3 frames
        for ($i = 0; $i < 3; $i++) {
            $inputs = $scheduler->sendAll($world, 0.016);
            $this->assertArrayHasKey('ping', $inputs);
            $scheduler->recvAll($world);
        }

        $scheduler->shutdown();
    }

    public function testPingSubsystemComputeReturnsCorrectDeltas(): void
    {
        $deltas = PingSubsystem::compute(['dt' => 0.016, 'entityCount' => 5]);

        $this->assertTrue($deltas['pong']);
        $this->assertEqualsWithDelta(0.032, $deltas['dt'], 0.0001);
    }

    /**
     * Regression guard for the Windows exit-hang fix.
     *
     * `shutdown()` must fully release the named `{name}_in` / `{name}_out`
     * Infinite channels from parallel's process-global channel table (see
     * ThreadScheduler::shutdown()). If a channel is left registered, the
     * extension reclaims it in MSHUTDOWN, which can block the process from
     * exiting on Windows.
     *
     * We can't observe MSHUTDOWN from PHP land, but we can prove the table
     * was emptied: after boot → round-trip → shutdown, booting the SAME
     * subsystem name again must succeed. `ThreadScheduler::boot()` calls
     * `Channel::make("{name}_in", Infinite)` — and `Channel::make()` throws
     * `parallel\Channel\Error\Existence` when a channel of that name is still
     * registered. A clean second boot therefore means shutdown() released it.
     *
     * Skips unless the host is ZTS with ext-parallel (the only config where a
     * real ThreadScheduler is used).
     */
    public function testShutdownReleasesNamedChannelsSoSubsystemCanReboot(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        $scheduler = new ThreadScheduler(coreCount: 1);
        $scheduler->register('reboot_probe', PingSubsystem::class);

        $world = new World();
        $world->createEntity();

        // First boot: makes reboot_probe_in / reboot_probe_out, spawns a worker.
        $scheduler->boot();
        $this->assertTrue($scheduler->isBooted());

        // One real frame through the parallel worker.
        $scheduler->sendAll($world, 0.016);
        $scheduler->recvAll($world);

        // Sentinel + Runtime::close() + explicit Channel::close() of both names.
        $scheduler->shutdown();
        $this->assertFalse($scheduler->isBooted());

        // Second boot of the SAME name. If shutdown() had left either named
        // channel registered, Channel::make() inside boot() would throw
        // Existence and fail this test. A clean boot proves the release.
        $scheduler->boot();
        $this->assertTrue($scheduler->isBooted());

        // The rebooted worker must still answer, confirming the channels are
        // freshly usable (not stale handles).
        $scheduler->sendAll($world, 0.016);
        $scheduler->recvAll($world);

        $scheduler->shutdown();
        $this->assertFalse($scheduler->isBooted());
    }
}
