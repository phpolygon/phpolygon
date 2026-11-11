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

        // Not asserted here: whether 'crasher' is already dead at this exact
        // point. CrashingSubsystem throws as its first statement, so
        // boot()'s own grace period (see waitOutEarlyFailures()) usually
        // catches it before boot() even returns — but that race is an
        // internal detail (see sendAll()'s doc), not a guarantee this test
        // should hard-code. 'ping' is unambiguous: never crashes, so it must
        // stay alive regardless of 'crasher''s timing.
        $this->assertTrue($scheduler->isSubsystemAlive('ping'));

        // Must return (not hang) even though 'crasher' throws before ever
        // reaching its own recv() — this is the bug being fixed, now one
        // layer earlier than it used to be: `_in` is unbuffered (see
        // ThreadScheduler::boot()'s doc), so sendAll()'s own send() would
        // block forever on a channel nobody will ever recv() from if it did
        // not check the Future first. Whichever of boot()'s grace period or
        // this check catches it, 'crasher' is detected and marked dead
        // before ever being sent to, not lazily later via pollAll().
        $scheduler->sendAll($world, 0.016);

        $this->assertFalse($scheduler->isSubsystemAlive('crasher'), 'crashed worker detected as dead during sendAll(), before the rendezvous send()');
        $this->assertSame(['crasher'], $scheduler->getDeadSubsystems());
        $this->assertTrue($scheduler->isSubsystemAlive('ping'), 'healthy subsystem unaffected by the other one dying');

        // 'crasher' was never marked outstanding (sendAll() skipped sending
        // to it), so pollAll() has nothing left to wait for on it — only
        // 'ping' is outstanding, and it delivers normally.
        $ready = $scheduler->pollAll($world);
        $this->assertTrue($ready, 'only the live subsystem is outstanding, and it delivers');

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
        // ReturningSubsystem returns from threadEntry() as its very first
        // action, never opening a channel — same as CrashingSubsystem, this
        // is caught by sendAll()'s own done() check before the rendezvous
        // send() on the now-unbuffered `_in` (see ThreadScheduler::sendAll()'s
        // doc), so 'returner' is dead already by the time sendAll() returns,
        // never outstanding.
        $scheduler->sendAll($world, 0.016);

        $this->assertFalse($scheduler->isSubsystemAlive('returner'), 'a clean return outside shutdown is still a dead worker');
        $this->assertSame(['returner'], $scheduler->getDeadSubsystems());

        // Nothing is outstanding ('returner' was never sent to) — pollAll()
        // must still return (not hang) and vacuously succeeds.
        $ready = $scheduler->pollAll($world, timeoutMicros: 2_000_000);
        $this->assertTrue($ready, 'nothing left outstanding to wait for');

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
        $sendStart = hrtime(true);
        $scheduler->sendAll($world, 0.5); // worker busy ~0.5s

        // Force it behind via an explicit, short bound — same mechanism as
        // the scenario above. Known to be rarely flaky on this Windows
        // machine (observed ~1 of 80 runs while writing fix/unbuffered-
        // scheduler-channels, both before and after that change - cause not
        // isolated, see the commit report) - a dead/not-outstanding 'stall'
        // would make pollAll() return true vacuously without ever actually
        // polling, which the diagnostics below would show directly instead
        // of leaving the next investigator to re-derive this from scratch.
        $pollStart = hrtime(true);
        $ready = $scheduler->pollAll($world, timeoutMicros: 20_000);
        $elapsedSinceSendMs = ($pollStart - $sendStart) / 1_000_000.0;
        $this->assertFalse($ready, sprintf(
            'dead=%s elapsedSinceSend=%.3fms (bound 20ms)',
            json_encode($scheduler->getDeadSubsystems()),
            $elapsedSinceSendMs,
        ));

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
     * Future itself (not a channel read) is what reports it. With `_in`
     * unbuffered, this now matters one step earlier than it used to:
     * sendAll() itself checks the kept Future before ever attempting the
     * rendezvous send() (see its own doc) — the 200ms head start below makes
     * the crash unmistakably already resolved by the time sendAll() checks,
     * so 'crasher' never becomes outstanding at all.
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

        $this->assertFalse($scheduler->isSubsystemAlive('crasher'), 'detected via the kept Future during sendAll(), before any send()');

        // Nothing outstanding ('crasher' was never sent to) — pollAll() must
        // still return (not hang) and vacuously succeeds.
        $ready = $scheduler->pollAll($world, timeoutMicros: 2_000_000);
        $this->assertTrue($ready, 'nothing left outstanding to wait for');

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

    /**
     * {@see DiesAfterOneAnswerSubsystem} dies BETWEEN ticks — healthy for its
     * first request, then returns without ever calling `recv()` again. This
     * is the case {@see ThreadScheduler::sendAll()}'s own `done()` check must
     * also cover (not just {@see ThreadScheduler::boot()}'s grace period,
     * which only ever runs once, right after boot(), long before this
     * worker dies): a SECOND `sendAll()` call must not hang on the now-
     * unbuffered `_in` channel's rendezvous `send()`, even though the
     * subsystem was perfectly alive and answered correctly just one tick
     * earlier.
     */
    public function testWorkerThatDiesBetweenTicksDoesNotHangTheNextSendAll(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        $scheduler = $this->makeThreadScheduler();
        $scheduler->register('dies', DiesAfterOneAnswerSubsystem::class);
        $scheduler->boot();

        $world = new World();

        // First tick: the worker is genuinely healthy and answers normally.
        $scheduler->sendAll($world, 0.016);
        $ready = $scheduler->pollAll($world, timeoutMicros: 2_000_000);
        $this->assertTrue($ready, 'first request delivered normally');
        $this->assertTrue($scheduler->isSubsystemAlive('dies'), 'answered once - not dead yet');

        // Give the worker's thread body a moment to actually return after
        // its one send() (its own remaining work is just "return", nothing
        // else - this is not racing a real compute step).
        usleep(50_000);

        // Second tick must not hang: sendAll()'s own done() check (see its
        // doc) must catch the now-resolved Future before attempting the
        // rendezvous send() on a channel nobody will ever recv() from again.
        $scheduler->sendAll($world, 0.016);
        $this->assertFalse($scheduler->isSubsystemAlive('dies'), 'detected dead during the second sendAll(), before its rendezvous send()');
        $this->assertSame(['dies'], $scheduler->getDeadSubsystems());

        // Nothing outstanding ('dies' was never sent to on this tick) -
        // pollAll() must still return (not hang) and vacuously succeeds.
        $ready2 = $scheduler->pollAll($world, timeoutMicros: 2_000_000);
        $this->assertTrue($ready2, 'nothing left outstanding to wait for');

        $scheduler->shutdown();
    }

    /**
     * {@see StallingSubsystem} is still mid-compute (not back at its own
     * `recv()`) when shutdown() runs — the scenario {@see ThreadScheduler::shutdown()}'s
     * own doc describes: `send(null)` on the now-unbuffered `_in` simply
     * waits out the worker's remaining real work rather than hanging
     * forever, since the worker IS alive and WILL eventually call `recv()`
     * again. Bounded by the worker's own remaining sleep plus a generous
     * margin for scheduling jitter — not by the old, buffered-channel
     * near-zero return, which this assertion deliberately does NOT expect
     * anymore.
     */
    public function testShutdownWithAStillOutstandingRequestFinishesWithinTheWorkersRemainingTime(): void
    {
        if (!\PHP_ZTS || !extension_loaded('parallel')) {
            $this->markTestSkipped('Requires ZTS PHP with ext-parallel for a real ThreadScheduler.');
        }

        $scheduler = $this->makeThreadScheduler();
        $scheduler->register('stall', StallingSubsystem::class);
        $scheduler->boot();

        $world = new World();
        // Worker busy ~0.3s - sendAll()'s own rendezvous send() returns as
        // soon as the worker's recv() takes it (near-instant: the worker is
        // sitting in recv() already), well before the 0.3s compute starts.
        $scheduler->sendAll($world, 0.3);

        $start = hrtime(true);
        $scheduler->shutdown();
        $elapsedMs = (hrtime(true) - $start) / 1_000_000.0;

        // Must finish once the worker's own ~0.3s compute is done (plus
        // scheduling margin), not hang forever, and not take dramatically
        // longer than that remaining work.
        $this->assertLessThan(1_000.0, $elapsedMs, 'shutdown() must not hang far beyond the worker\'s own remaining compute time');
    }
}
