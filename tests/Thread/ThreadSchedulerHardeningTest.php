<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread;

use PHPUnit\Framework\TestCase;
use PHPolygon\ECS\World;
use PHPolygon\Log\Log;
use PHPolygon\Runtime\PerfProfiler;
use PHPolygon\Tests\Log\RecordingLogWriter;
use PHPolygon\Thread\NullThreadScheduler;
use PHPolygon\Thread\ThreadScheduler;

/**
 * Phase 5 hardening: Futures kept instead of discarded, pollAll() bounded
 * (and non-blocking once a subsystem has fallen behind) instead of blocking
 * forever, dead workers detected — including one that returns cleanly rather
 * than throwing — instead of just hung on, the outstanding/behind bookkeeping
 * that keeps a slow worker's `_in` channel from growing without bound, the
 * `__log`/`__timings` side channel, and the thread sections showing up under
 * {@see PerfProfiler}.
 *
 * The real-threading tests here require ZTS PHP with ext-parallel — the only
 * configuration where {@see ThreadScheduler} (rather than its single-threaded
 * fallback {@see NullThreadScheduler}) is used at all.
 */
class ThreadSchedulerHardeningTest extends TestCase
{
    /**
     * Safety net: a worker thread this test spawned but never explicitly
     * shut down (e.g. because an earlier assertion in the same test failed
     * first) is still shut down here. Without this, an orphaned worker
     * blocked forever on its `_in` channel's `recv()` can keep the whole
     * PHPUnit process from exiting cleanly — turning one failing assertion
     * into the entire suite hanging, which is exactly what happened once
     * while writing these tests (see the commit).
     *
     * @var list<ThreadScheduler|NullThreadScheduler>
     */
    private array $activeSchedulers = [];

    protected function setUp(): void
    {
        LoggingSubsystem::$lastAppliedDeltas = null;
        StallingSubsystem::$applyCount = 0;
    }

    protected function tearDown(): void
    {
        foreach ($this->activeSchedulers as $scheduler) {
            try {
                $scheduler->shutdown();
            } catch (\Throwable) {
                // Best effort — never mask the test's own failure with this.
            }
        }
        $this->activeSchedulers = [];

        putenv('PHPOLYGON_PROFILE');
        PerfProfiler::reset();
        Log::reset();
    }

    private function makeThreadScheduler(int $coreCount = 1): ThreadScheduler
    {
        $scheduler = new ThreadScheduler($coreCount);
        $this->activeSchedulers[] = $scheduler;

        return $scheduler;
    }

    private function makeNullScheduler(): NullThreadScheduler
    {
        $scheduler = new NullThreadScheduler();
        $this->activeSchedulers[] = $scheduler;

        return $scheduler;
    }

    public function testDeadWorkerIsDetectedAndSkippedInsteadOfHanging(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        $scheduler = $this->makeThreadScheduler();
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

    /**
     * A Future that resolves WITHOUT an exception fires the same event type
     * as a real channel delivery (measured directly against the installed
     * parallel extension, not assumed from documentation) — only its
     * `source` differs. This worker returns from threadEntry() immediately,
     * never sending anything; the scheduler must still detect it as dead
     * rather than mistaking the Future's own event for a delivered result.
     */
    public function testWorkerThatReturnsWithoutThrowingIsDetectedAsDead(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        $scheduler = $this->makeThreadScheduler();
        $scheduler->register('returner', ReturningSubsystem::class);
        $scheduler->boot();

        $world = new World();
        $scheduler->sendAll($world, 0.016);
        $ready = $scheduler->pollAll($world, timeoutMicros: 2_000_000);

        $this->assertFalse($ready, 'a worker that returned without sending anything cannot have delivered');
        $this->assertFalse($scheduler->isSubsystemAlive('returner'), 'a clean return outside shutdown is still a dead worker');
        $this->assertSame(['returner'], $scheduler->getDeadSubsystems());

        $scheduler->shutdown();
    }

    public function testStalledButAliveWorkerIsNotMarkedDead(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        $scheduler = $this->makeThreadScheduler();
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

    /**
     * recvAll() — the method the game loop actually calls every tick — must
     * itself switch a behind subsystem to a non-blocking check rather than
     * paying {@see ThreadScheduler::DEFAULT_POLL_TIMEOUT_US} (several
     * seconds) again on every later tick; {@see pollAll()}, used explicitly
     * elsewhere in this file, deliberately does not make that substitution
     * (see its own doc) — this test is recvAll()'s own behaviour, not a
     * duplicate of the pollAll() tests above.
     */
    public function testRecvAllPollsBehindSubsystemNonBlockingInsteadOfPayingTheDefaultBoundAgain(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        $scheduler = $this->makeThreadScheduler();
        $scheduler->register('stall', StallingSubsystem::class);
        $scheduler->boot();

        $world = new World();
        $scheduler->sendAll($world, 0.5); // worker busy ~0.5s

        // Force it behind via an explicit, short bound — same mechanism as
        // the scenario above.
        $ready = $scheduler->pollAll($world, timeoutMicros: 20_000);
        $this->assertFalse($ready);

        // The worker is still busy; recvAll() must notice it is "behind" and
        // poll it non-blocking, returning almost instantly rather than
        // waiting out DEFAULT_POLL_TIMEOUT_US (several seconds) again.
        $start = hrtime(true);
        $scheduler->recvAll($world);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;

        $this->assertLessThan(200.0, $elapsedMs, 'recvAll() must poll a behind subsystem non-blocking, not pay the default bound again');
        $this->assertTrue($scheduler->isSubsystemAlive('stall'), 'still just behind, not dead');

        // Drain the real, eventual answer explicitly so nothing is left
        // mid-flight before shutdown.
        $drained = $scheduler->pollAll($world, timeoutMicros: 2_000_000);
        $this->assertTrue($drained);

        $scheduler->shutdown();
    }

    /**
     * Once a subsystem has missed one bounded poll, sendAll() must not queue
     * a second input behind the still-unanswered first one — otherwise a
     * worker slower than the tick rate would grow its `_in` channel without
     * bound, and a late answer could be misread as the answer to the wrong
     * input.
     */
    public function testSendAllDoesNotQueueASecondInputWhileOneIsOutstanding(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        $scheduler = $this->makeThreadScheduler();
        $scheduler->register('stall', StallingSubsystem::class);
        $scheduler->boot();

        $world = new World();

        $inputs1 = $scheduler->sendAll($world, 0.3);
        $this->assertArrayHasKey('stall', $inputs1, 'first tick: nothing outstanding yet, so it sends');
        $scheduler->pollAll($world, timeoutMicros: 20_000); // too short — still outstanding afterwards

        $inputs2 = $scheduler->sendAll($world, 0.3);
        $this->assertArrayNotHasKey('stall', $inputs2, 'second tick: previous input still unanswered, so it is skipped');

        // Drain the first (and only) request so the worker is not left
        // mid-flight before shutdown.
        $ready = $scheduler->pollAll($world, timeoutMicros: 2_000_000);
        $this->assertTrue($ready);

        $inputs3 = $scheduler->sendAll($world, 0.016);
        $this->assertArrayHasKey('stall', $inputs3, 'caught up: sendAll() resumes once the outstanding answer arrived');

        $scheduler->shutdown();
    }

    /**
     * A delayed answer — one that missed its bound and put the subsystem
     * "behind" — must be applied exactly once: not dropped while the
     * scheduler is polling it non-blocking tick after tick, and not
     * double-applied once it finally arrives. LoggingSubsystem would answer
     * too fast for this (its first poll could already catch the real
     * answer, making every assertion after it pass trivially regardless of
     * whether "applied exactly once" actually held) — StallingSubsystem's
     * artificial delay is what makes "still behind" and "finally delivered"
     * observably different moments.
     */
    public function testLateAnswerIsAppliedExactlyOnce(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        StallingSubsystem::$applyCount = 0;

        $scheduler = $this->makeThreadScheduler();
        $scheduler->register('stall', StallingSubsystem::class);
        $scheduler->boot();

        $world = new World();
        $scheduler->sendAll($world, 0.3); // worker busy ~0.3s

        // Miss the bound on purpose, putting 'stall' behind.
        $this->assertFalse($scheduler->pollAll($world, timeoutMicros: 20_000));
        $this->assertSame(0, StallingSubsystem::$applyCount);

        // While still behind and the worker still busy, recvAll() must poll
        // non-blocking and apply nothing — call it a few times to rule out
        // a fluke single pass.
        for ($i = 0; $i < 3; $i++) {
            $scheduler->recvAll($world);
        }
        $this->assertSame(0, StallingSubsystem::$applyCount, 'nothing applied while still behind and still busy');

        // Now wait properly for the one real, delayed answer.
        $this->assertTrue($scheduler->pollAll($world, timeoutMicros: 2_000_000));
        $this->assertSame(1, StallingSubsystem::$applyCount, 'the late answer is applied exactly once');

        // Caught up: nothing outstanding anymore, so recvAll()/a non-blocking
        // pollAll() have nothing left to apply.
        $scheduler->recvAll($world);
        $scheduler->pollAll($world, timeoutMicros: 0);
        $this->assertSame(1, StallingSubsystem::$applyCount, 'no second application of the same answer');

        // Caught up also means sendAll() resumes sending fresh input.
        $inputs = $scheduler->sendAll($world, 0.016);
        $this->assertArrayHasKey('stall', $inputs);

        $scheduler->pollAll($world, timeoutMicros: 2_000_000);
        $scheduler->shutdown();
    }

    /**
     * timeoutMicros === 0 means genuinely non-blocking (parallel\Events
     * cannot combine setBlocking(false) with setTimeout() — confirmed by
     * measurement) — a worker with real, still-running work must be reported
     * as "not ready" immediately, not marked dead, and not waited on.
     */
    public function testZeroTimeoutPollIsNonBlockingAndDoesNotMarkAliveWorkerDead(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        $scheduler = $this->makeThreadScheduler();
        $scheduler->register('stall', StallingSubsystem::class);
        $scheduler->boot();

        $world = new World();
        // Worker busy for 0.5s — comfortably longer than the 200ms bound
        // below, short enough that shutdown() joining it afterwards (see the
        // open risk noted on ThreadScheduler::shutdown()) does not slow the
        // suite down much.
        $scheduler->sendAll($world, 0.5);

        $start = hrtime(true);
        $ready = $scheduler->pollAll($world, timeoutMicros: 0);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;

        $this->assertFalse($ready);
        $this->assertTrue($scheduler->isSubsystemAlive('stall'));
        // Generous bound (this is a correctness check, not a benchmark): a
        // genuinely non-blocking poll must return orders of magnitude faster
        // than the 0.5s the worker is still busy for. Direct measurement
        // against this host (Windows dev worktree machine, PHP 8.5.5 ZTS,
        // ext-parallel, 2026-11-11) of pollOne()'s whole non-blocking
        // sequence (new Events, Channel::open, addChannel, addFuture, poll())
        // against a busy Future+channel pair, 3 runs x 500 calls each:
        // avg 754-880ns, median 700-800ns, p99 1000-1700ns, max 5500-39300ns
        // (one outlier per run) — so 200ms leaves roughly five orders of
        // magnitude of margin for CI slowness.
        $this->assertLessThan(200.0, $elapsedMs, 'a non-blocking poll must not itself block');

        $scheduler->shutdown();
    }

    public function testNullSchedulerPropagatesThrowingSubsystemUnchanged(): void
    {
        $scheduler = $this->makeNullScheduler();
        $scheduler->register('crasher', CrashingSubsystem::class);
        $scheduler->boot();

        $world = new World();
        $scheduler->sendAll($world, 0.016);

        // No worker thread exists under NullThreadScheduler, so a throwing
        // compute() is not a "dead worker" to detect and route around — it
        // behaves exactly as before this hardening: the exception propagates.
        $this->expectException(\RuntimeException::class);
        $scheduler->pollAll($world);
    }

    public function testNullSchedulerAlwaysReportsSubsystemsAlive(): void
    {
        $scheduler = $this->makeNullScheduler();
        $scheduler->register('ping', PingSubsystem::class);
        $scheduler->boot();

        $this->assertTrue($scheduler->isSubsystemAlive('ping'));
        $this->assertSame([], $scheduler->getDeadSubsystems());

        $world = new World();
        $scheduler->sendAll($world, 0.016);
        $scheduler->pollAll($world);

        $this->assertTrue($scheduler->isSubsystemAlive('ping'));
        $this->assertSame([], $scheduler->getDeadSubsystems());

        $scheduler->shutdown();
    }

    public function testThreadSectionsAppearInPerfProfilerSnapshot(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        putenv('PHPOLYGON_PROFILE=1');
        PerfProfiler::reset();

        $scheduler = $this->makeThreadScheduler();
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

        $scheduler = $this->makeNullScheduler();
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

    /**
     * `__log` / `__timings` (see {@see \PHPolygon\Thread\SubsystemInterface})
     * must never reach applyDeltas() and must show up through the engine Log
     * and PerfProfiler instead — for both schedulers.
     */
    public function testLogAndTimingSideChannelIsForwardedAndStrippedBeforeApplyDeltasOnThreadScheduler(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        putenv('PHPOLYGON_PROFILE=1');
        PerfProfiler::reset();
        $writer = new RecordingLogWriter();
        Log::startWithWriter($writer, hooks: false);

        $scheduler = $this->makeThreadScheduler();
        $scheduler->register('log', LoggingSubsystem::class);
        $scheduler->boot();

        $world = new World();
        $scheduler->sendAll($world, 0.016);
        $scheduler->pollAll($world, timeoutMicros: 2_000_000);
        $scheduler->shutdown();
        Log::flush();

        $this->assertNotNull(LoggingSubsystem::$lastAppliedDeltas);
        $this->assertArrayHasKey('ok', LoggingSubsystem::$lastAppliedDeltas);
        $this->assertArrayNotHasKey('__log', LoggingSubsystem::$lastAppliedDeltas, '__log must never reach applyDeltas()');
        $this->assertArrayNotHasKey('__timings', LoggingSubsystem::$lastAppliedDeltas, '__timings must never reach applyDeltas()');

        $this->assertStringContainsString('logging subsystem test fixture ran', $writer->all());
        // LogRecord::body() writes a string-keyed context value as `key=value`
        // (see CLAUDE.md "Engine log") — this is the forwarded entry's own
        // context ('n' => 1) with 'subsystem' added by forwardSideChannel(),
        // not merely the word "log" appearing somewhere in the line (which
        // the subsystem's own name, 'log', would trivially satisfy).
        $this->assertStringContainsString('subsystem=log', $writer->all());

        $snapshot = PerfProfiler::snapshot();
        $this->assertArrayHasKey('thread.log.work', $snapshot, '__timings entries become their own profiler section');

        Log::shutdown();
    }

    public function testLogAndTimingSideChannelIsForwardedAndStrippedBeforeApplyDeltasOnNullScheduler(): void
    {
        putenv('PHPOLYGON_PROFILE=1');
        PerfProfiler::reset();
        $writer = new RecordingLogWriter();
        Log::startWithWriter($writer, hooks: false);

        $scheduler = $this->makeNullScheduler();
        $scheduler->register('log', LoggingSubsystem::class);
        $scheduler->boot();

        $world = new World();
        $scheduler->sendAll($world, 0.016);
        $scheduler->pollAll($world);
        Log::flush();

        $this->assertNotNull(LoggingSubsystem::$lastAppliedDeltas);
        $this->assertArrayNotHasKey('__log', LoggingSubsystem::$lastAppliedDeltas);
        $this->assertArrayNotHasKey('__timings', LoggingSubsystem::$lastAppliedDeltas);
        $this->assertStringContainsString('logging subsystem test fixture ran', $writer->all());
        $this->assertStringContainsString('subsystem=log', $writer->all());

        $snapshot = PerfProfiler::snapshot();
        $this->assertArrayHasKey('thread.log.work', $snapshot);

        Log::shutdown();
    }

    /**
     * Future kept, not discarded (see class doc on {@see ThreadScheduler}):
     * a crash is detectable even without polling right away, because the
     * Future itself (not a channel read) is what reports it.
     */
    public function testFuturesAreKeptSoCrashIsVisibleEvenWithoutPollingSoonAfter(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        $scheduler = $this->makeThreadScheduler();
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

    public function testDeadWorkerIsLoggedThroughTheEngineLog(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        $writer = new RecordingLogWriter();
        Log::startWithWriter($writer, hooks: false);

        $scheduler = $this->makeThreadScheduler();
        $scheduler->register('crasher', CrashingSubsystem::class);
        $scheduler->boot();

        $world = new World();
        $scheduler->sendAll($world, 0.016);
        $scheduler->pollAll($world, timeoutMicros: 2_000_000);
        $scheduler->shutdown();
        Log::flush();

        $this->assertStringContainsString('thread worker died', $writer->all());
        // Precise context key (see LogRecord::body(), "Engine log" in
        // CLAUDE.md), not merely the subsystem's own name, 'crasher',
        // appearing as a substring somewhere in the line.
        $this->assertStringContainsString('subsystem=crasher', $writer->all());

        Log::shutdown();
    }
}
