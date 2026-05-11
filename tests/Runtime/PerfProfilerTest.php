<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPolygon\Runtime\PerfProfiler;
use PHPUnit\Framework\TestCase;

/**
 * PerfProfiler unit tests.
 *
 * The profiler is keyed off two env vars (`SPX_ENABLED`, `PHPOLYGON_EXCIMER`)
 * and freezes its backend choice at first access. Because the static
 * `$backend` field is private and resolved via lazy initialisation, these
 * tests poke it directly via Reflection so the begin/end accumulator path
 * runs without a real C extension behind it. SPX/Excimer-side calls are
 * gated by `function_exists()` and stay no-ops in CI.
 */
final class PerfProfilerTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset every static the profiler keeps so each test starts clean.
        PerfProfiler::reset();
        $this->setBackend(1); // BACKEND_SPX (matches private const)
    }

    protected function tearDown(): void
    {
        PerfProfiler::reset();
        $this->setBackend(-1); // -1 forces lazy re-resolution on next call
    }

    public function testSectionAccumulatesCallsAndTotalTime(): void
    {
        PerfProfiler::begin('test.alpha');
        // Burn a small amount of real time so totalNs > 0 deterministically.
        $this->busyWait(500_000);
        PerfProfiler::end();

        PerfProfiler::begin('test.alpha');
        $this->busyWait(500_000);
        PerfProfiler::end();

        $snapshot = PerfProfiler::snapshot();

        self::assertArrayHasKey('test.alpha', $snapshot);
        self::assertSame(2, $snapshot['test.alpha']['calls']);
        self::assertGreaterThan(0, $snapshot['test.alpha']['totalNs']);
        self::assertGreaterThan(0.0, $snapshot['test.alpha']['avgNs']);
        self::assertEqualsWithDelta(
            $snapshot['test.alpha']['totalNs'] / 2,
            $snapshot['test.alpha']['avgNs'],
            0.01,
            'avgNs must be totalNs / calls.',
        );
    }

    public function testSectionWrapperRunsCallableAndReturnsResult(): void
    {
        $result = PerfProfiler::section('wrapped', static fn(): int => 42);

        self::assertSame(42, $result);
        $snap = PerfProfiler::snapshot();
        self::assertArrayHasKey('wrapped', $snap);
        self::assertSame(1, $snap['wrapped']['calls']);
    }

    public function testSectionWrapperEndsEvenWhenCallableThrows(): void
    {
        // PHPStan would narrow an inline throwing closure's return type to
        // never; pulling it out behind a callable variable keeps the test
        // readable while preserving the throw-and-recover flow under test.
        $throwing = $this->makeThrowingCallable();

        $caught = null;
        try {
            PerfProfiler::section('throwing', $throwing);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'Exception from the callable must propagate to the caller.');
        self::assertSame('boom', $caught->getMessage());

        // The end() call inside the finally must have closed the section so
        // a follow-up begin() does not blow the stack into incorrect state.
        $snap = PerfProfiler::snapshot();
        self::assertArrayHasKey('throwing', $snap);
        self::assertSame(1, $snap['throwing']['calls']);
    }

    /** @return callable():void */
    private function makeThrowingCallable(): callable
    {
        return static function (): void {
            throw new \RuntimeException('boom');
        };
    }

    public function testEndWithoutMatchingBeginIsSafe(): void
    {
        // Profiler must tolerate a stray end() so a coding error in one
        // section does not corrupt later frames.
        PerfProfiler::end();
        PerfProfiler::end();

        self::assertSame([], PerfProfiler::snapshot());
    }

    public function testGcDeltaFirstCallReturnsZeros(): void
    {
        // setUp() + reset() drops the baseline, so the first gcDelta call
        // after that establishes the baseline and reports zeros.
        $delta = PerfProfiler::gcDelta();

        self::assertSame(['runs' => 0, 'collected' => 0], $delta);
    }

    public function testGcDeltaReportsRunCountSinceBaseline(): void
    {
        PerfProfiler::gcDelta(); // baseline

        // Force at least one GC run so we have a measurable delta.
        \gc_collect_cycles();
        \gc_collect_cycles();

        $delta = PerfProfiler::gcDelta();

        self::assertGreaterThanOrEqual(0, $delta['runs']);
        // Cannot assert > 0 strictly: PHP only counts cycle-collector runs,
        // and a no-op gc_collect_cycles increments the counter on most
        // builds but not all. The contract under test is that the field
        // exists and is non-negative once a baseline is in place.
        self::assertArrayHasKey('runs', $delta);
        self::assertArrayHasKey('collected', $delta);
    }

    public function testResetClearsSectionsStackAndGcBaseline(): void
    {
        PerfProfiler::begin('a');
        PerfProfiler::end();
        PerfProfiler::gcDelta();

        PerfProfiler::reset();

        self::assertSame([], PerfProfiler::snapshot());
        // Baseline reset means the next gcDelta call returns zeros again.
        self::assertSame(['runs' => 0, 'collected' => 0], PerfProfiler::gcDelta());
    }

    public function testIsActiveReflectsBackendChoice(): void
    {
        $this->setBackend(0); // BACKEND_NONE
        self::assertFalse(PerfProfiler::isActive());

        $this->setBackend(1); // BACKEND_SPX
        self::assertTrue(PerfProfiler::isActive());
    }

    public function testNoOpWhenBackendIsNone(): void
    {
        $this->setBackend(0); // BACKEND_NONE

        PerfProfiler::begin('ignored');
        PerfProfiler::end();

        self::assertSame([], PerfProfiler::snapshot(),
            'begin/end pairs must be no-ops when no profiler backend is active so production builds pay zero overhead.',
        );
    }

    /**
     * Sleep-busy for at least $minNs nanoseconds. Avoids `usleep` so the
     * test does not depend on OS scheduler granularity.
     */
    private function busyWait(int $minNs): void
    {
        $end = \hrtime(true) + $minNs;
        while (\hrtime(true) < $end) {
            // spin
        }
    }

    private function setBackend(int $value): void
    {
        $rp = new \ReflectionProperty(PerfProfiler::class, 'backend');
        $rp->setValue(null, $value);
    }
}
