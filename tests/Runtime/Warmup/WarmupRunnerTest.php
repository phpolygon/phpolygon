<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime\Warmup;

use PHPolygon\Runtime\Warmup\CallbackWarmupTask;
use PHPolygon\Runtime\Warmup\WarmupRunner;
use PHPolygon\Runtime\Warmup\WarmupTask;
use PHPUnit\Framework\TestCase;

final class WarmupRunnerTest extends TestCase
{
    private float $now = 0.0;

    /** @var list<string> */
    private array $log = [];

    private function runner(): WarmupRunner
    {
        $this->now = 0.0;
        $this->log = [];
        return new WarmupRunner(fn(): float => $this->now, function (string $line): void {
            $this->log[] = $line;
        });
    }

    /** An atomic task that takes $cost fake seconds and notes that it ran. */
    private function task(string $id, float $cost = 0.003, float $weight = 1.0, array $deps = [], ?array &$ran = null): WarmupTask
    {
        return new CallbackWarmupTask($id, function () use ($id, $cost, &$ran): void {
            $this->now += $cost;
            $ran[] = $id;
        }, 'key.' . $id, $weight, $deps);
    }

    public function testEmptyRunnerIsFinishedAndTouchesNothing(): void
    {
        $r = $this->runner();
        $this->assertTrue($r->isEmpty());
        $this->assertTrue($r->finished());
        $this->assertSame(1.0, $r->fraction());
        $this->assertFalse($r->step(0.01));
        $this->assertNull($r->currentTask());
        $this->assertSame([], $r->results());
        $this->assertSame([], $this->log);
    }

    public function testStepRunsUnitsUntilTheBudgetIsUsed(): void
    {
        $ran = [];
        $r = $this->runner();
        for ($i = 1; $i <= 5; $i++) {
            $r->add($this->task("t$i", 0.003, 1.0, [], $ran));
        }
        // 3 ms per unit, 7 ms budget: after the third unit 9 ms are used.
        $this->assertTrue($r->step(0.007));
        $this->assertSame(['t1', 't2', 't3'], $ran);
        $this->assertFalse($r->step(0.007));
        $this->assertSame(['t1', 't2', 't3', 't4', 't5'], $ran);
        $this->assertSame(1.0, $r->fraction());
    }

    public function testAStepAlwaysRunsAtLeastOneUnit(): void
    {
        $ran = [];
        $r = $this->runner();
        $r->add($this->task('a', 0.05, 1.0, [], $ran));
        $r->add($this->task('b', 0.05, 1.0, [], $ran));
        $this->assertTrue($r->step(0.0));
        $this->assertSame(['a'], $ran);
        $this->assertFalse($r->step(-1.0));
        $this->assertSame(['a', 'b'], $ran);
        $this->assertTrue($r->finished());
    }

    public function testGeneratorRunsInSlicesAndReportsItsFraction(): void
    {
        $r = $this->runner();
        $r->add(new CallbackWarmupTask('bake', function (): \Generator {
            for ($i = 1; $i <= 4; $i++) {
                $this->now += 0.002;
                yield $i / 4;
            }
        }));
        $fractions = [];
        $steps = 0;
        while ($r->step(0.0)) {
            $steps++;
            $fractions[] = $r->fraction();
        }
        // four slices that yield, a fifth that lets the generator end
        $this->assertSame(4, $steps);
        $this->assertEqualsWithDelta([0.25, 0.5, 0.75, 1.0], $fractions, 1e-5);
        $this->assertSame(1.0, $r->fraction());
        $this->assertSame('done', $r->results()['bake']['status']);
        $this->assertEqualsWithDelta(0.008, $r->results()['bake']['seconds'], 1e-9);
        $this->assertEqualsWithDelta(0.002, $r->results()['bake']['maxUnit'], 1e-9);
    }

    public function testProgressNeverMovesBackwardAndIsClamped(): void
    {
        $r = $this->runner();
        $r->add(new CallbackWarmupTask('g', function (): \Generator {
            yield 0.6;
            yield 0.2;     // backwards: ignored
            yield 7.0;     // above 1: clamped
            yield null;
            yield NAN;
        }));
        $r->add($this->task('after'));
        $seen = [];
        do {
            $more = $r->step(0.0);
            $seen[] = $r->fraction();
        } while ($more);
        $sorted = $seen;
        sort($sorted);
        $this->assertSame($sorted, $seen);
        $this->assertSame(1.0, end($seen));
        foreach (array_slice($seen, 0, -1) as $f) {
            $this->assertLessThan(1.0, $f);
        }
    }

    public function testWeightsShapeTheBar(): void
    {
        $r = $this->runner();
        $r->add($this->task('light', 0.001, 1.0));
        $r->add($this->task('heavy', 0.001, 3.0));
        $r->step(0.0);
        $this->assertEqualsWithDelta(0.25, $r->fraction(), 1e-9);
        $this->assertSame('key.heavy', $r->labelKey());
        $r->step(0.0);
        $this->assertSame(1.0, $r->fraction());
        $this->assertNull($r->labelKey());
    }

    public function testAThrowingTaskIsIsolatedAndItsDependentsAreSkipped(): void
    {
        $ran = [];
        $r = $this->runner();
        $r->add(new CallbackWarmupTask('bad', function (): void {
            throw new \RuntimeException('disk full');
        }));
        $r->add($this->task('child', 0.001, 1.0, ['bad'], $ran));
        $r->add($this->task('grandchild', 0.001, 1.0, ['child'], $ran));
        $r->add($this->task('other', 0.001, 1.0, [], $ran));
        $r->drain();

        $this->assertSame(['other'], $ran);
        $res = $r->results();
        $this->assertSame('failed', $res['bad']['status']);
        $this->assertSame('RuntimeException: disk full', $res['bad']['error']);
        $this->assertSame('skipped', $res['child']['status']);
        $this->assertSame('skipped', $res['grandchild']['status']);
        $this->assertSame('done', $res['other']['status']);
        $this->assertSame(1.0, $r->fraction());
        $this->assertTrue($r->finished());
        $this->assertContains("warmup: task 'bad' failed: RuntimeException: disk full", $this->log);
        $this->assertStringContainsString('1 done, 1 failed, 2 skipped', end($this->log));
    }

    public function testAGeneratorThatThrowsMidwayFailsOnlyItself(): void
    {
        $ran = [];
        $r = $this->runner();
        $r->add(new CallbackWarmupTask('flaky', function (): \Generator {
            yield 0.5;
            throw new \LogicException('boom');
        }));
        $r->add($this->task('next', 0.001, 1.0, [], $ran));
        $r->drain();
        $this->assertSame('failed', $r->results()['flaky']['status']);
        $this->assertSame(['next'], $ran);
    }

    public function testDependenciesRunFirstAndOrderIsStable(): void
    {
        $ran = [];
        $r = $this->runner();
        $r->add($this->task('c', 0.001, 1.0, ['a', 'b'], $ran));
        $r->add($this->task('b', 0.001, 1.0, ['a'], $ran));
        $r->add($this->task('a', 0.001, 1.0, [], $ran));
        $r->add($this->task('d', 0.001, 1.0, [], $ran));
        $r->drain();
        $this->assertSame(['a', 'b', 'c', 'd'], $ran);
    }

    public function testUnknownDependencyAndCycleAreSkippedNotFatal(): void
    {
        $ran = [];
        $r = $this->runner();
        $r->add($this->task('orphan', 0.001, 1.0, ['nowhere'], $ran));
        $r->add($this->task('x', 0.001, 1.0, ['y'], $ran));
        $r->add($this->task('y', 0.001, 1.0, ['x'], $ran));
        $r->add($this->task('self', 0.001, 1.0, ['self'], $ran));
        $r->add($this->task('fine', 0.001, 1.0, [], $ran));
        $r->drain();
        $this->assertSame(['fine'], $ran);
        $res = $r->results();
        $this->assertSame("unknown dependency 'nowhere'", $res['orphan']['error']);
        $this->assertSame('dependency cycle', $res['x']['error']);
        $this->assertSame('dependency cycle', $res['self']['error']);
        $this->assertSame(1.0, $r->fraction());
    }

    public function testLongAtomicUnitOverrunsAndIsRecorded(): void
    {
        $r = $this->runner();
        $r->add($this->task('slow', 0.2));
        $r->add($this->task('quick', 0.001));
        $this->assertTrue($r->step(0.008));
        $this->assertEqualsWithDelta(0.2, $r->results()['slow']['maxUnit'], 1e-9);
        $r->drain();
        $this->assertStringContainsString('longest unit 200.0 ms', end($this->log));
    }

    public function testCancelDestroysTheGeneratorAndSkipsTheRest(): void
    {
        $cleaned = false;
        $ran = [];
        $r = $this->runner();
        $r->add(new CallbackWarmupTask('long', function () use (&$cleaned): \Generator {
            try {
                while (true) {
                    yield 0.1;
                }
            } finally {
                $cleaned = true;
            }
        }));
        $r->add($this->task('rest', 0.001, 1.0, [], $ran));
        $r->step(0.0);
        $before = $r->fraction();
        $r->cancel('window closed');

        $this->assertTrue($cleaned);
        $this->assertTrue($r->finished());
        $this->assertFalse($r->step(1.0));
        $this->assertSame([], $ran);
        $this->assertSame('skipped', $r->results()['long']['status']);
        $this->assertSame('window closed', $r->results()['rest']['error']);
        $this->assertSame($before, $r->fraction());
    }

    public function testAThrowingCleanupDuringCancelDoesNotEscape(): void
    {
        $r = $this->runner();
        $r->add(new CallbackWarmupTask('long', function (): \Generator {
            try {
                yield 0.1;
            } finally {
                throw new \RuntimeException('cleanup broke');
            }
        }));
        $r->step(0.0);
        $r->cancel();
        $this->assertTrue($r->finished());
    }

    public function testListenerGetsTheBarAndNextLabelAndIsDroppedWhenItThrows(): void
    {
        $r = $this->runner();
        $r->add($this->task('a'));
        $r->add($this->task('b'));
        $r->add($this->task('c'));
        $calls = [];
        $r->onProgress(function (float $f, ?string $key, ?string $id) use (&$calls): void {
            $calls[] = [$f, $key, $id];
            if (count($calls) === 2) {
                throw new \RuntimeException('listener broke');
            }
        });
        $r->step(0.0);
        $r->step(0.0);
        $r->step(0.0);
        $this->assertCount(2, $calls);
        $this->assertSame([1 / 3, 'key.b', 'b'], $calls[0]);
        $this->assertTrue($r->finished());
        $this->assertSame(1.0, $r->fraction());
        $this->assertNotEmpty(array_filter($this->log, static fn($l) => str_contains($l, 'listener failed')));
    }

    public function testRegistrationIsValidated(): void
    {
        $r = $this->runner();
        $r->add($this->task('a'));
        try {
            $r->add($this->task('a'));
            $this->fail('duplicate id accepted');
        } catch (\InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        foreach ([0.0, -1.0, NAN, INF] as $w) {
            try {
                $r->add($this->task('w', 0.001, $w));
                $this->fail('bad weight accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $r->step(0.0);
        $this->expectException(\LogicException::class);
        $r->add($this->task('late'));
    }

    public function testCallbackTaskDefaults(): void
    {
        $task = new CallbackWarmupTask('x', static fn() => 42);
        $this->assertSame('x', $task->id());
        $this->assertNull($task->labelKey());
        $this->assertSame(1.0, $task->weight());
        $this->assertSame([], $task->dependsOn());
        $this->assertNull($task->run());
    }
}
