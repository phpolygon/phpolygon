<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread;

use PHPUnit\Framework\TestCase;
use PHPolygon\ECS\World;
use PHPolygon\Log\Log;
use PHPolygon\Runtime\PerfProfiler;
use PHPolygon\Thread\NullThreadScheduler;
use PHPolygon\Thread\ThreadScheduler;

/**
 * Phase 5 hardening: Futures kept instead of discarded, pollAll() bounded
 * instead of blocking forever, dead workers detected instead of just hung
 * on, and the thread sections showing up under {@see PerfProfiler}.
 *
 * The real-threading tests here require ZTS PHP with ext-parallel — the only
 * configuration where {@see ThreadScheduler} (rather than its single-threaded
 * fallback {@see NullThreadScheduler}) is used at all.
 */
class ThreadSchedulerHardeningTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHPOLYGON_PROFILE');
        PerfProfiler::reset();
        Log::reset();
    }

    public function testDeadWorkerIsDetectedAndSkippedInsteadOfHanging(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        $scheduler = new ThreadScheduler(coreCount: 1);
        $scheduler->register('crasher', CrashingSubsystem::class);
        $scheduler->register('ping', PingSubsystem::class);
        $scheduler->boot();

        $world = new World();
        $world->createEntity();

        $this->assertTrue($scheduler->isSubsystemAlive('crasher'));
        $this->assertTrue($scheduler->isSubsystemAlive('ping'));

        $scheduler->sendAll($world, 0.016);

        // Must return (not hang) even though 'crasher' will never send
        // anything on its out channel — this is the bug being fixed: before
        // this change, recvAll() called a plain blocking Channel::recv() per
        // subsystem and a dead worker meant that call never returned.
        $ready = $scheduler->pollAll($world);

        $this->assertFalse($ready, 'not every subsystem delivered — the crashed one cannot');
        $this->assertFalse($scheduler->isSubsystemAlive('crasher'), 'crashed worker detected as dead');
        $this->assertSame(['crasher'], $scheduler->getDeadSubsystems());
        $this->assertTrue($scheduler->isSubsystemAlive('ping'), 'healthy subsystem unaffected by the other one dying');

        // A later tick must not try to wait on the dead subsystem again
        // (sendAll()/pollAll() skip it) — prove it completes and stays dead.
        $scheduler->sendAll($world, 0.016);
        $ready2 = $scheduler->pollAll($world);
        $this->assertTrue($ready2, 'only the live subsystem is polled now, and it delivers');
        $this->assertSame(['crasher'], $scheduler->getDeadSubsystems());

        $scheduler->shutdown();
    }

    public function testStalledButAliveWorkerIsNotMarkedDead(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        $scheduler = new ThreadScheduler(coreCount: 1);
        $scheduler->register('stall', StallingSubsystem::class);
        $scheduler->boot();

        $world = new World();

        // StallingSubsystem::prepareInput() reuses dt as its artificial delay
        // (see fixture doc) — 0.3s of work against a far shorter poll bound.
        $scheduler->sendAll($world, 0.3);
        $ready = $scheduler->pollAll($world, timeoutMicros: 20_000);

        $this->assertFalse($ready, 'worker has not answered within the short bound yet');
        $this->assertTrue($scheduler->isSubsystemAlive('stall'), 'merely slow, not dead — must not be confused with a crash');
        $this->assertSame([], $scheduler->getDeadSubsystems());

        // Give it a bound comfortably larger than its remaining work so the
        // delayed delta is drained before shutdown (the channel still holds
        // it — nothing is lost by the earlier, too-short poll).
        $ready2 = $scheduler->pollAll($world, timeoutMicros: 2_000_000);
        $this->assertTrue($ready2, 'the slow worker eventually delivers');
        $this->assertTrue($scheduler->isSubsystemAlive('stall'));

        $scheduler->shutdown();
    }

    public function testNullSchedulerCatchesThrowingSubsystemInsteadOfCrashingTheLoop(): void
    {
        $scheduler = new NullThreadScheduler();
        $scheduler->register('crasher', CrashingSubsystem::class);
        $scheduler->register('ping', PingSubsystem::class);
        $scheduler->boot();

        $world = new World();

        $scheduler->sendAll($world, 0.016);
        // Must not let CrashingSubsystem::compute()'s exception propagate.
        $ready = $scheduler->pollAll($world);

        $this->assertFalse($ready);
        $this->assertFalse($scheduler->isSubsystemAlive('crasher'));
        $this->assertSame(['crasher'], $scheduler->getDeadSubsystems());
        $this->assertTrue($scheduler->isSubsystemAlive('ping'));

        $scheduler->shutdown();
    }

    public function testFuturesAreKeptSoCrashIsVisibleEvenWithoutPolling(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        $scheduler = new ThreadScheduler(coreCount: 1);
        $scheduler->register('crasher', CrashingSubsystem::class);
        $scheduler->boot();

        // Give the worker thread a moment to run and crash on its own —
        // this only proves anything if boot() kept the Future (otherwise
        // there would be nothing here to observe the crash through).
        usleep(200_000);

        $world = new World();
        $scheduler->sendAll($world, 0.016);
        $ready = $scheduler->pollAll($world, timeoutMicros: 2_000_000);

        $this->assertFalse($ready);
        $this->assertFalse($scheduler->isSubsystemAlive('crasher'));

        $scheduler->shutdown();
    }

    public function testThreadSectionsAppearInPerfProfilerSnapshot(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        putenv('PHPOLYGON_PROFILE=1');
        PerfProfiler::reset();

        $scheduler = new ThreadScheduler(coreCount: 1);
        $scheduler->register('ping', PingSubsystem::class);
        $scheduler->boot();

        $world = new World();
        $scheduler->sendAll($world, 0.016);
        $scheduler->pollAll($world);
        $scheduler->shutdown();

        $snapshot = PerfProfiler::snapshot();
        $this->assertArrayHasKey('thread.scheduler.send', $snapshot);
        $this->assertArrayHasKey('thread.scheduler.poll', $snapshot);
        $this->assertArrayHasKey('thread.ping.wait', $snapshot);
        $this->assertGreaterThan(0, $snapshot['thread.scheduler.poll']['calls']);
    }

    public function testNullSchedulerThreadSectionsAppearInPerfProfilerSnapshot(): void
    {
        putenv('PHPOLYGON_PROFILE=1');
        PerfProfiler::reset();

        $scheduler = new NullThreadScheduler();
        $scheduler->register('ping', PingSubsystem::class);
        $scheduler->boot();

        $world = new World();
        $scheduler->sendAll($world, 0.016);
        $scheduler->pollAll($world);
        $scheduler->shutdown();

        $snapshot = PerfProfiler::snapshot();
        $this->assertArrayHasKey('thread.scheduler.send', $snapshot);
        $this->assertArrayHasKey('thread.scheduler.poll', $snapshot);
        $this->assertArrayHasKey('thread.ping.wait', $snapshot);
    }

    public function testDeadWorkerIsLoggedThroughTheEngineLog(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        Log::reset();
        Log::start(thread: false, trace: false, hooks: false);

        $scheduler = new ThreadScheduler(coreCount: 1);
        $scheduler->register('crasher', CrashingSubsystem::class);
        $scheduler->boot();

        $world = new World();
        $scheduler->sendAll($world, 0.016);
        $scheduler->pollAll($world, timeoutMicros: 2_000_000);
        $scheduler->shutdown();

        $lines = implode("\n", Log::recent());
        $this->assertStringContainsString('thread worker died', $lines);
        $this->assertStringContainsString('crasher', $lines);

        Log::shutdown();
    }
}
