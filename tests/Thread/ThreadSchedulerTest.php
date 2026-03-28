<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread;

use PHPUnit\Framework\TestCase;
use PHPolygon\ECS\World;
use PHPolygon\Thread\NullThreadScheduler;
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

        // Without parallel extension, should fall back to NullThreadScheduler
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->assertInstanceOf(NullThreadScheduler::class, $scheduler);
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
}
