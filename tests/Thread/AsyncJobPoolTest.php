<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread;

use PHPUnit\Framework\TestCase;
use PHPolygon\Tests\Thread\Jobs\StreamingTestJobs;
use PHPolygon\Thread\AsyncJobPool;
use PHPolygon\Thread\AsyncJobResult;
use PHPolygon\Thread\AsyncJobStatus;
use PHPolygon\Thread\ParallelCapability;

class AsyncJobPoolTest extends TestCase
{
    /** @var list<AsyncJobPool> */
    private array $pools = [];

    protected function tearDown(): void
    {
        foreach ($this->pools as $pool) {
            $pool->shutdown();
        }
        $this->pools = [];
    }

    private function threaded(int $workers = 1, string $memory = '512M', float $hung = 30.0, int $depth = 2): AsyncJobPool
    {
        if (!ParallelCapability::isAvailable()) {
            $this->markTestSkipped('ext-parallel (ZTS) not available');
        }
        $pool = new AsyncJobPool($workers, $memory, $hung, $depth);
        $this->registerAll($pool);

        return $this->pools[] = $pool;
    }

    private function inline(int $perCollect = PHP_INT_MAX): AsyncJobPool
    {
        $pool = new AsyncJobPool(0, '512M', 30.0, 2, $perCollect);
        $this->registerAll($pool);

        return $this->pools[] = $pool;
    }

    private function registerAll(AsyncJobPool $pool): void
    {
        foreach (['echo', 'square', 'boom', 'spin', 'alloc', 'memoryLimit', 'cacheProbe', 'packFloats'] as $name) {
            $pool->register($name, StreamingTestJobs::class . '::' . $name);
        }
    }

    /** @return array<string, AsyncJobResult> */
    private static function byKey(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            $out[$r->key . '@' . $r->lod . '/' . $r->epoch] = $r;
        }

        return $out;
    }

    // ---- inline mode: deterministic -----------------------------------

    public function testInlinePoolRunsInPriorityOrderThenSubmitOrder(): void
    {
        $pool = $this->inline();
        $this->assertTrue($pool->isInline());
        $pool->submit('far', 0, 1, 'square', 9, priority: 5);
        $pool->submit('near', 0, 1, 'square', 3, priority: 1);
        $pool->submit('near2', 0, 1, 'square', 4, priority: 1);

        $results = $pool->collect();

        $this->assertSame(['near', 'near2', 'far'], array_map(static fn (AsyncJobResult $r): string => $r->key, $results));
        $this->assertSame([9, 16, 81], array_map(static fn (AsyncJobResult $r): mixed => $r->value, $results));
        $this->assertSame(AsyncJobStatus::Ok, $results[0]->status);
    }

    public function testInlineCapPerCollectModelsLimitedThroughput(): void
    {
        $pool = $this->inline(2);
        for ($i = 0; $i < 5; $i++) {
            $pool->submit("c{$i}", 0, 1, 'echo', $i, priority: $i);
        }

        $this->assertCount(2, $pool->collect());
        $this->assertSame(3, $pool->pendingCount());
        $this->assertCount(2, $pool->collect());
        $this->assertCount(1, $pool->collect());
        $this->assertSame([], $pool->collect());
    }

    public function testDuplicateKeyLodEpochIsANoOpButAnotherEpochIsNot(): void
    {
        $pool = $this->inline();

        $this->assertTrue($pool->submit('a', 0, 1, 'echo', 1));
        $this->assertFalse($pool->submit('a', 0, 1, 'echo', 2));
        $this->assertTrue($pool->submit('a', 1, 1, 'echo', 3), 'another lod');
        $this->assertTrue($pool->submit('a', 0, 2, 'echo', 4), 'another epoch');
        $this->assertSame(3, $pool->pendingCount());

        $pool->collect();
        $this->assertTrue($pool->submit('a', 0, 1, 'echo', 5), 'finished jobs free their slot');
    }

    public function testCancelOlderThanDropsPendingAndRefusesLateOnes(): void
    {
        $pool = $this->inline();
        $pool->submit('a', 0, 1, 'echo', 1);
        $pool->submit('b', 0, 2, 'echo', 2);

        $this->assertSame(1, $pool->cancelOlderThan(2));
        $this->assertFalse($pool->submit('c', 0, 1, 'echo', 3), 'below the floor');

        $results = $pool->collect();
        $this->assertSame(['b'], array_map(static fn (AsyncJobResult $r): string => $r->key, $results));
        $this->assertSame(1, $pool->stats()['cancelled']);
    }

    public function testCancelByKey(): void
    {
        $pool = $this->inline();
        $pool->submit('a', 0, 1, 'echo', 1);
        $pool->submit('a', 1, 1, 'echo', 1);
        $pool->submit('b', 0, 1, 'echo', 1);

        $this->assertSame(1, $pool->cancel('a', 1));
        $this->assertSame(1, $pool->cancel('a'));
        $this->assertCount(1, $pool->collect());
    }

    public function testInlineHandlerExceptionBecomesAnErrorResult(): void
    {
        $pool = $this->inline();
        $pool->submit('a', 0, 1, 'boom', 'x');
        $pool->submit('b', 0, 1, 'echo', 'y');

        $results = self::byKey($pool->collect());

        $this->assertSame(AsyncJobStatus::Error, $results['a@0/1']->status);
        $this->assertStringContainsString('boom x', (string) $results['a@0/1']->error);
        $this->assertSame('y', $results['b@0/1']->value);
    }

    public function testRegisterRejectsWhatCannotCrossAThread(): void
    {
        $pool = $this->inline();
        $this->expectException(\InvalidArgumentException::class);
        $pool->register('x', 'No\\Such\\Class::method');
    }

    public function testUnknownHandlerIsRejectedAtSubmit(): void
    {
        $pool = $this->inline();
        $this->expectException(\InvalidArgumentException::class);
        $pool->submit('a', 0, 1, 'missing', 1);
    }

    // ---- real worker threads ------------------------------------------

    public function testThreadedJobsAllComplete(): void
    {
        $pool = $this->threaded(2);
        for ($i = 0; $i < 20; $i++) {
            $pool->submit("c{$i}", 0, 1, 'square', $i);
        }

        $results = self::byKey($pool->drain(20.0));

        $this->assertCount(20, $results);
        foreach ($results as $r) {
            $this->assertSame(AsyncJobStatus::Ok, $r->status);
        }
        $this->assertSame(49, $results['c7@0/1']->value);
        $this->assertGreaterThan(0, $results['c7@0/1']->latencyNs);
        $this->assertSame(2, $pool->workerCount());
        $this->assertSame(0, $pool->inFlight());
    }

    public function testCollectNeverBlocksOnABusyWorker(): void
    {
        $pool = $this->threaded(1, depth: 1);
        $pool->submit('slow', 0, 1, 'spin', 400);
        $pool->collect(); // hands it to the worker

        $t = hrtime(true);
        for ($i = 0; $i < 50; $i++) {
            $this->assertSame([], $pool->collect());
        }
        $elapsedMs = (hrtime(true) - $t) / 1e6;

        $this->assertLessThan(100.0, $elapsedMs, '50 empty collect() calls while the worker is busy');
        $this->assertCount(1, $pool->drain(10.0));
    }

    public function testWorkerAppliesTheMemoryLimitToEveryJob(): void
    {
        $pool = $this->threaded(1, '300M');
        $pool->submit('a', 0, 1, 'memoryLimit', null);

        $results = $pool->drain(10.0);

        $this->assertSame('300M', $results[0]->value);
    }

    public function testHandlerExceptionKeepsTheWorkerAlive(): void
    {
        $pool = $this->threaded(1);
        $pool->submit('bad', 0, 1, 'boom', 'z');
        $pool->submit('good', 0, 1, 'echo', 'ok');

        $results = self::byKey($pool->drain(10.0));

        $this->assertSame(AsyncJobStatus::Error, $results['bad@0/1']->status);
        $this->assertSame('ok', $results['good@0/1']->value);
        $this->assertSame(0, $pool->stats()['recycled']);
    }

    public function testOutOfMemoryInAWorkerIsAnErrorAndTheWorkerIsRecycled(): void
    {
        $pool = $this->threaded(1, '64M');
        $pool->submit('big', 0, 1, 'alloc', 200);

        $first = self::byKey($pool->drain(20.0));
        $this->assertSame(AsyncJobStatus::Error, $first['big@0/1']->status);
        $this->assertStringContainsString('worker died', (string) $first['big@0/1']->error);
        $this->assertSame(1, $pool->stats()['recycled']);

        $pool->submit('after', 0, 1, 'square', 6);
        $second = self::byKey($pool->drain(20.0));
        $this->assertSame(36, $second['after@0/1']->value, 'a fresh worker answers the next job');
        $this->assertSame(2, $pool->stats()['spawned']);
    }

    public function testWorkerCachePersistsBetweenJobsOfOneWorker(): void
    {
        $pool = $this->threaded(1, depth: 1);
        $pool->submit('a', 0, 1, 'cacheProbe', null);
        $first = $pool->drain(10.0)[0]->value;
        $pool->submit('b', 0, 1, 'cacheProbe', null);
        $second = $pool->drain(10.0)[0]->value;

        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertSame($first['token'], $second['token']);
        $this->assertSame(0, $first['hits']);
        $this->assertSame(1, $second['hits']);
    }

    public function testEpochCancellationDropsQueuedJobsAndTheRunningOnesResult(): void
    {
        $pool = $this->threaded(1, depth: 1);
        $pool->submit('run', 0, 1, 'spin', 300);
        for ($i = 0; $i < 5; $i++) {
            $pool->submit("queued{$i}", 0, 1, 'echo', $i);
        }
        $pool->collect(); // 'run' is on the worker, five wait on the main thread

        $this->assertSame(1, $pool->inFlight());
        $this->assertSame(6, $pool->cancelOlderThan(2));
        $pool->submit('fresh', 0, 2, 'echo', 'new');

        $results = $pool->drain(10.0);

        $this->assertSame(['fresh'], array_map(static fn (AsyncJobResult $r): string => $r->key, $results));
        $this->assertSame(0, $pool->pendingCount());
    }

    public function testNearestPendingJobOvertakesAFarOneSubmittedEarlier(): void
    {
        $pool = $this->threaded(1, depth: 1);
        $pool->submit('busy', 0, 1, 'spin', 150, priority: 0);
        $pool->collect();
        $pool->submit('far', 0, 1, 'echo', 'far', priority: 9);
        $pool->submit('near', 0, 1, 'echo', 'near', priority: 1);

        $order = array_map(static fn (AsyncJobResult $r): string => $r->key, $pool->drain(10.0));

        $this->assertSame(['busy', 'near', 'far'], $order);
    }

    public function testHungWorkerIsKilledReportedAndReplaced(): void
    {
        $pool = $this->threaded(1, hung: 0.2, depth: 1);
        $pool->submit('hang', 0, 1, 'spin', 5000);
        $pool->submit('next', 0, 1, 'square', 5);

        $t = hrtime(true);
        $results = self::byKey($pool->drain(10.0));
        $elapsed = (hrtime(true) - $t) / 1e9;

        $this->assertSame(AsyncJobStatus::TimedOut, $results['hang@0/1']->status);
        $this->assertSame(25, $results['next@0/1']->value);
        $this->assertLessThan(3.0, $elapsed, 'the 5 s job was cut short at about 0.2 s plus a respawn');
        $this->assertSame(1, $pool->stats()['timedOut']);
        $this->assertSame(1, $pool->stats()['recycled']);
    }

    public function testJobsQueuedBehindAHungOneAreRequeuedNotLost(): void
    {
        $pool = $this->threaded(1, hung: 0.2, depth: 2);
        $pool->submit('hang', 0, 1, 'spin', 5000);
        $pool->submit('behind', 0, 1, 'square', 7);

        $results = self::byKey($pool->drain(10.0));

        $this->assertSame(AsyncJobStatus::TimedOut, $results['hang@0/1']->status);
        $this->assertSame(49, $results['behind@0/1']->value);
        $this->assertSame(1, $pool->stats()['requeued']);
    }

    public function testCancellingJobsQueuedBehindAHungOneDoesNotGiveItAFreshDeadline(): void
    {
        $pool = $this->threaded(1, hung: 0.3, depth: 2);
        $pool->submit('hang', 0, 1, 'spin', 5000);
        $pool->submit('q0', 0, 1, 'echo', 0);
        $pool->collect(); // 'hang' runs, 'q0' waits behind it on the worker

        // Every frame a queued job is cancelled and a new one is queued behind the hung head, as a streamer does
        // when the focus moves. The hung head must still time out about 0.3 s after it started.
        $t = hrtime(true);
        $timedOut = false;
        for ($i = 1; hrtime(true) - $t < 3_000_000_000 && !$timedOut; $i++) {
            $pool->cancel('q' . ($i - 1));
            $pool->submit("q{$i}", 0, 1, 'echo', $i);
            foreach ($pool->collect() as $r) {
                if ($r->key === 'hang' && $r->status === AsyncJobStatus::TimedOut) {
                    $timedOut = true;
                }
            }
        }

        $this->assertTrue($timedOut, 'the hung job was reported within 3 s although jobs behind it were cancelled all along');
        $this->assertLessThan(2.0, (hrtime(true) - $t) / 1e9);
    }

    public function testPayloadThatCannotCrossAThreadIsAnErrorResultNotAnException(): void
    {
        $pool = $this->threaded(1);
        $pool->submit('obj', 0, 1, 'echo', new \ArrayObject([1]));
        $pool->submit('fine', 0, 1, 'echo', 'x');

        $results = self::byKey($pool->drain(10.0));

        $this->assertSame(AsyncJobStatus::Error, $results['obj@0/1']->status);
        $this->assertSame('x', $results['fine@0/1']->value);
    }

    public function testLargePackedStringResultCrossesIntact(): void
    {
        $pool = $this->threaded(1);
        $pool->submit('big', 0, 1, 'packFloats', 250_000);

        $r = $pool->drain(20.0)[0];

        $this->assertIsString($r->value);
        $this->assertSame(1_000_000, strlen($r->value));
        $this->assertSame([0.0, 0.5, 1.0], array_values(unpack('g3', $r->value)));
    }

    public function testShutdownIsIdempotentAndStopsWorkers(): void
    {
        $pool = $this->threaded(2);
        $pool->submit('a', 0, 1, 'echo', 1);
        $pool->drain(10.0);
        $this->assertSame(1, $pool->workerCount());

        $pool->shutdown();
        $pool->shutdown();

        $this->assertSame(0, $pool->workerCount());
    }
}
