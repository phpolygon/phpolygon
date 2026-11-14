<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Streaming;

use PHPUnit\Framework\TestCase;
use PHPolygon\Streaming\CellStreamer;
use PHPolygon\Streaming\CellStreamerConfig;
use PHPolygon\Streaming\StreamFrame;
use PHPolygon\Tests\Streaming\Jobs\CellBakeJob;
use PHPolygon\Thread\AsyncJobPool;
use PHPolygon\Thread\ParallelCapability;

/**
 * Deterministic headless simulations of the cell streamer: an inline job pool
 * with a fixed throughput per frame, a fake clock, and a recording game side.
 */
class CellStreamerTest extends TestCase
{
    /** @var list<AsyncJobPool> */
    private array $pools = [];

    protected function tearDown(): void
    {
        foreach ($this->pools as $p) {
            $p->shutdown();
        }
        $this->pools = [];
    }

    /**
     * @return array{0: CellStreamer, 1: \stdClass, 2: AsyncJobPool}
     */
    private function rig(CellStreamerConfig $config, int $jobsPerCollect = 2, string $job = 'bake', int $applyCostNs = 0, ?AsyncJobPool $pool = null): array
    {
        $log = new \stdClass();
        $log->clock = 0;
        $log->requests = [];     // list of [key, lod, epoch, frame]
        $log->applies = [];      // list of [key, lod, epoch, frame, value]
        $log->evictions = [];    // list of [key, frame]
        $log->frame = 0;

        if ($pool === null) {
            $pool = new AsyncJobPool(0, '512M', 30.0, 2, $jobsPerCollect);
            $pool->register('bake', CellBakeJob::class . '::bake');
            $pool->register('flaky', CellBakeJob::class . '::flaky');
            $pool->register('parts', CellBakeJob::class . '::parts');
            $this->pools[] = $pool;
        }

        $streamer = new CellStreamer(
            $config,
            $pool,
            $job,
            static function (int $cx, int $cz, int $lod, int $epoch) use ($log): array {
                $log->requests[] = [CellStreamer::key($cx, $cz), $lod, $epoch, $log->frame];

                return [$cx, $cz, $lod, $epoch];
            },
            static function (int $cx, int $cz, int $lod, mixed $value, int $epoch) use ($log, $applyCostNs): void {
                $log->applies[] = [CellStreamer::key($cx, $cz), $lod, $epoch, $log->frame, $value];
                $log->clock += $applyCostNs;
            },
            static function (int $cx, int $cz) use ($log): void {
                $log->evictions[] = [CellStreamer::key($cx, $cz), $log->frame];
            },
            null,
            static fn (): int => $log->clock,
        );

        return [$streamer, $log, $pool];
    }

    /** @return list<StreamFrame> */
    private function walk(CellStreamer $s, \stdClass $log, float $x0, float $z0, float $x1, float $z1, float $step): array
    {
        $frames = [];
        $len = sqrt(($x1 - $x0) ** 2 + ($z1 - $z0) ** 2);
        $n = max(1, (int) ceil($len / $step));
        for ($i = 0; $i <= $n; $i++) {
            $t = $i / $n;
            $log->frame++;
            $frames[] = $s->update($x0 + ($x1 - $x0) * $t, $z0 + ($z1 - $z0) * $t);
        }

        return $frames;
    }

    private function settle(CellStreamer $s, \stdClass $log, float $x, float $z, int $maxFrames = 200): int
    {
        for ($i = 1; $i <= $maxFrames; $i++) {
            $log->frame++;
            $s->update($x, $z);
            if ($s->isSettled()) {
                return $i;
            }
        }
        $this->fail("not settled after {$maxFrames} frames");
    }

    private static function config(array $override = []): CellStreamerConfig
    {
        return new CellStreamerConfig(...array_merge([
            'cellSize' => 100.0,
            'loadRadius' => 2,
            'unloadRadius' => 3,
            'lodByRing' => [0, 1, 2],
            'prefetchRadius' => 1,
            'prefetchDistance' => 1.5,
            'maxInFlight' => 6,
            'maxRequestsPerFrame' => 3,
            'uploadBudgetBytes' => 5000,
            'frameBudgetMs' => 2.0,
            'gridWidth' => 12,
            'gridHeight' => 12,
        ], $override));
    }

    public function testWalkingAPathRequestsNoCellTwiceAtTheSameEpochAndKeepsEveryBudget(): void
    {
        $cfg = self::config();
        [$s, $log] = $this->rig($cfg, 2, 'bake', 700_000);

        $frames = $this->walk($s, $log, 150.0, 150.0, 1050.0, 150.0, 25.0);
        $frames = array_merge($frames, $this->walk($s, $log, 1050.0, 150.0, 1050.0, 1050.0, 25.0));
        $this->settle($s, $log, 1050.0, 1050.0);

        // 1. Never the same (cell, lod, epoch) twice on a path that does not come back.
        $triples = array_map(static fn (array $r): string => "{$r[0]}@{$r[1]}/{$r[2]}", $log->requests);
        $this->assertSame($triples, array_values(array_unique($triples)), 'duplicate request');
        $this->assertGreaterThan(60, count($log->requests));

        // 2. Budgets per frame.
        foreach ($frames as $i => $f) {
            $this->assertLessThanOrEqual($cfg->maxInFlight, $f->outstanding, "frame {$i} in flight");
            $this->assertLessThanOrEqual($cfg->maxRequestsPerFrame, count($f->requested), "frame {$i} requests");
            $this->assertTrue(count($f->applied) <= 1 || $f->appliedBytes <= $cfg->uploadBudgetBytes, "frame {$i} bytes {$f->appliedBytes}");
            $this->assertLessThanOrEqual(3, count($f->applied), "frame {$i}: 0.7 ms per install under a 2 ms budget");
        }

        // 3. Settled around the final cell, nothing resident beyond the unload ring.
        $this->assertTrue($s->isSettled());
        foreach (array_keys($s->residentCells()) as $key) {
            [$cx, $cz] = array_map('intval', explode(':', $key));
            $this->assertLessThanOrEqual(3, max(abs($cx - 10), abs($cz - 10)), "{$key} should have been evicted");
        }
        $this->assertSame(0, $s->residentCells()['10:10']);
        $this->assertSame(2, $s->residentCells()['8:10'], 'two rings out: the coarsest level');

        // 4. Cells were evicted behind the walker and never came back.
        $this->assertNotSame([], $log->evictions);
        $this->assertSame(1, $s->epoch());
        foreach ($log->applies as $a) {
            $this->assertSame(1, $a[2]);
        }
    }

    public function testLodNeverDowngradesOnAWalkOnlyUpgrades(): void
    {
        [$s, $log] = $this->rig(self::config(['prefetchRadius' => 0]), 4);
        $this->walk($s, $log, 150.0, 650.0, 950.0, 650.0, 20.0);
        $this->settle($s, $log, 950.0, 650.0);

        $lastLod = [];
        foreach ($log->applies as [$key, $lod]) {
            if (isset($lastLod[$key])) {
                $this->assertLessThanOrEqual($lastLod[$key], $lod, "{$key} was downgraded");
            }
            $lastLod[$key] = $lod;
        }
        $this->assertGreaterThan(0, count(array_filter($log->applies, static fn (array $a): bool => $a[1] === 0)));
    }

    public function testRingsGetTheirLod(): void
    {
        [$s, $log] = $this->rig(self::config(['prefetchRadius' => 0]), 100);
        $this->settle($s, $log, 650.0, 650.0);
        $res = $s->residentCells();

        $this->assertSame(0, $res['6:6']);
        $this->assertSame(1, $res['7:6']);
        $this->assertSame(1, $res['7:7']);
        $this->assertSame(2, $res['8:6']);
        $this->assertSame(2, $res['4:8']);
        $this->assertCount(25, $res);
    }

    public function testInvalidateNeverInstallsAResultOfAnOlderEpoch(): void
    {
        [$s, $log] = $this->rig(self::config(['loadRadius' => 3, 'unloadRadius' => 4, 'maxInFlight' => 12, 'maxRequestsPerFrame' => 12, 'uploadBudgetBytes' => 100]), 6);
        $log->frame++;
        $s->update(650.0, 650.0);
        $log->frame++;
        $this->assertGreaterThan(2, $s->update(650.0, 650.0)->outstanding, 'finished results wait for their install turn');
        $installedBefore = count($log->applies);
        $this->assertSame(1, $s->epoch());

        $s->invalidate();
        $this->assertSame(2, $s->epoch());
        $frame = $s->update(650.0, 650.0);
        $this->assertGreaterThan(0, $frame->droppedStale, 'finished results of epoch 1 were thrown away');
        $this->settle($s, $log, 650.0, 650.0);

        foreach (array_slice($log->applies, $installedBefore) as $a) {
            $this->assertSame(2, $a[2], "{$a[0]} installed from epoch {$a[2]}");
        }
        foreach ($s->wantedCells() as $key => $_) {
            $this->assertNotNull($s->residentCells()[$key] ?? null);
        }
        // The same cells were requested again, but never the same (cell, lod, epoch).
        $triples = array_map(static fn (array $r): string => "{$r[0]}@{$r[1]}/{$r[2]}", $log->requests);
        $this->assertSame($triples, array_values(array_unique($triples)));
        $this->assertGreaterThan(count(array_unique(array_column($log->requests, 0))), count($log->requests));
    }

    public function testInvalidateKeepsResidentCellsUntilReplacedUnlessToldToDrop(): void
    {
        [$s, $log] = $this->rig(self::config(['prefetchRadius' => 0]), 100);
        $this->settle($s, $log, 650.0, 650.0);
        $before = $s->residentCells();

        $s->invalidate();
        $this->assertSame($before, $s->residentCells(), 'still drawing the old content');
        $this->assertFalse($s->isSettled());
        $this->settle($s, $log, 650.0, 650.0);

        $s->invalidate(true);
        $this->assertSame([], $s->residentCells());
        $frame = $s->update(650.0, 650.0);
        $this->assertCount(count($before), $frame->evicted, 'the drop is reported by the next frame');
    }

    public function testResultsOfCellsTheFocusLeftBehindAreDroppedNotInstalled(): void
    {
        [$s, $log] = $this->rig(self::config(['prefetchRadius' => 0, 'maxInFlight' => 10, 'maxRequestsPerFrame' => 10, 'uploadBudgetBytes' => 100]), 6);
        $log->frame++;
        $s->update(250.0, 250.0);
        $log->frame++;
        $s->update(250.0, 250.0); // results are finished, one installs, the rest wait
        $this->assertGreaterThan(1, count($log->requests));

        $log->frame++;
        $installedBeforeTheJump = count($log->applies);
        $dropped = $s->update(1050.0, 1050.0)->droppedStale; // teleport: the waiting results of the old focus go
        $this->assertGreaterThan(0, $dropped);
        for ($i = 0; $i < 60; $i++) {
            $log->frame++;
            $dropped += $s->update(1050.0, 1050.0)->droppedStale;
        }
        $this->settle($s, $log, 1050.0, 1050.0);

        $this->assertGreaterThan(0, $dropped);
        foreach (array_slice($log->applies, $installedBeforeTheJump) as $a) {
            [$cx, $cz] = array_map('intval', explode(':', $a[0]));
            $this->assertGreaterThanOrEqual(8, $cx, "{$a[0]} belongs to the old focus");
            $this->assertGreaterThanOrEqual(8, $cz);
        }
    }

    public function testACellIsRequestedAgainOnlyAfterItWasEvicted(): void
    {
        [$s, $log] = $this->rig(self::config(['prefetchRadius' => 0]), 100);
        $this->walk($s, $log, 150.0, 150.0, 950.0, 150.0, 25.0);
        $this->walk($s, $log, 950.0, 150.0, 150.0, 150.0, 25.0);
        $this->settle($s, $log, 150.0, 150.0);

        $seen = [];
        $requestsOf = [];
        foreach ($log->requests as [$key, $lod, $epoch, $frame]) {
            $requestsOf["{$key}@{$lod}/{$epoch}"][] = $frame;
        }
        $repeated = array_filter($requestsOf, static fn (array $frames): bool => count($frames) > 1);
        $this->assertNotSame([], $repeated, 'walking back reloads cells that were evicted');
        foreach ($repeated as $triple => $frames) {
            [$key, $rest] = explode('@', $triple);
            [$lod, $epoch] = array_map('intval', explode('/', $rest));
            // A repeat is legitimate when the first request ended in an eviction, or never produced an
            // install at all (the walker had moved on before its turn came and the result was dropped).
            $installedFirst = array_filter(
                $log->applies,
                static fn (array $a): bool => $a[0] === $key && $a[1] === $lod && $a[2] === $epoch && $a[3] >= $frames[0] && $a[3] < $frames[1],
            );
            if ($installedFirst === []) {
                continue;
            }
            $evictedBetween = array_filter(
                $log->evictions,
                static fn (array $e): bool => $e[0] === $key && $e[1] >= $frames[0] && $e[1] <= $frames[1],
            );
            $this->assertNotSame([], $evictedBetween, "{$triple} was resident and requested again without an eviction in between");
        }
    }

    public function testHysteresisSpareACellThatJustLeftTheLoadRing(): void
    {
        [$s, $log] = $this->rig(self::config(['prefetchRadius' => 0, 'lodByRing' => [0]]), 100);
        $this->settle($s, $log, 650.0, 650.0);
        $requests = count($log->requests);

        // Step back and forth over a cell border: nothing is evicted, nothing is requested.
        for ($i = 0; $i < 20; $i++) {
            $log->frame++;
            $s->update($i % 2 === 0 ? 699.0 : 701.0, 650.0);
        }
        $this->settle($s, $log, 701.0, 650.0);
        $moved = count($log->requests) - $requests;

        $this->assertSame([], $log->evictions);
        $this->assertSame(5, $moved, 'only the new column of five cells at the far edge of the load ring');
    }

    public function testFirstInstallOfAFrameIsAlwaysAllowedEvenWhenBiggerThanTheBudget(): void
    {
        [$s, $log] = $this->rig(self::config(['uploadBudgetBytes' => 100, 'prefetchRadius' => 0, 'loadRadius' => 0, 'unloadRadius' => 1]), 100);

        $log->frame++;
        $s->update(650.0, 650.0);
        $log->frame++;
        $frame = $s->update(650.0, 650.0);

        $this->assertCount(1, $frame->applied);
        $this->assertSame(CellBakeJob::FULL_BYTES, $frame->appliedBytes);
    }

    public function testInstallsStopWhenTheFrameTimeIsUsedUpButOneAlwaysGoesThrough(): void
    {
        [$s, $log] = $this->rig(self::config(['prefetchRadius' => 0, 'uploadBudgetBytes' => 1 << 30]), 100, 'bake', 5_000_000);

        $log->frame++;
        $s->update(650.0, 650.0);
        $log->frame++;
        $frame = $s->update(650.0, 650.0);

        $this->assertCount(1, $frame->applied, 'one install costs 5 ms against a 2 ms budget: only the guaranteed one runs');
        $this->assertGreaterThan(1, $frame->outstanding);
    }

    public function testByteBudgetDefersTheNextInstallToTheNextFrame(): void
    {
        [$s, $log] = $this->rig(self::config(['prefetchRadius' => 0, 'loadRadius' => 1, 'uploadBudgetBytes' => 9000, 'lodByRing' => [0]]), 100);

        $log->frame++;
        $s->update(650.0, 650.0);
        $log->frame++;
        $f1 = $s->update(650.0, 650.0);

        $this->assertCount(2, $f1->applied, 'two 4000-byte results fit 9000, the third does not');
        $this->assertSame(8000, $f1->appliedBytes);
        $log->frame++;
        $f2 = $s->update(650.0, 650.0);
        $this->assertGreaterThan(0, count($f2->applied));
    }

    public function testFailedCellIsRetriedThenGivenUpUntilInvalidate(): void
    {
        [$s, $log] = $this->rig(self::config(['prefetchRadius' => 0, 'maxRetries' => 1, 'gridWidth' => null, 'gridHeight' => null]), 100, 'flaky');
        $failures = 0;
        for ($i = 0; $i < 40; $i++) {
            $log->frame++;
            $failures += $s->update(150.0, 150.0)->failed;
        }
        $attempts = count(array_filter($log->requests, static fn (array $r): bool => $r[0] === '1:1'));

        $this->assertSame(2, $failures, 'the first try and one retry');
        $this->assertSame(2, $attempts);
        $this->assertArrayNotHasKey('1:1', $s->residentCells());
        $this->assertFalse($s->isSettled());

        $s->invalidate();
        for ($i = 0; $i < 20; $i++) {
            $log->frame++;
            $failures += $s->update(150.0, 150.0)->failed;
        }
        $this->assertSame(4, $failures, 'a new epoch gives the cell a fresh set of attempts');
    }

    public function testLookAheadCellsAreWantedBeyondTheLoadRing(): void
    {
        $cfg = self::config(['loadRadius' => 1, 'unloadRadius' => 2, 'prefetchRadius' => 1, 'prefetchDistance' => 3.0, 'gridWidth' => null, 'gridHeight' => null]);
        [$s, $log] = $this->rig($cfg, 100);
        for ($x = 650.0; $x < 700.0; $x += 5.0) {
            $log->frame++;
            $s->update($x, 650.0);
        }
        $wanted = $s->wantedCells();

        // Focus cell 6, load ring reaches 7, look-ahead 3 cells (300 u) ahead = cell 9, its ring starts at 8.
        $this->assertArrayHasKey('7:6', $wanted);
        $this->assertArrayHasKey('9:6', $wanted);
        $this->assertArrayHasKey('10:6', $wanted);
        $this->assertArrayNotHasKey('3:6', $wanted, 'nothing is prefetched behind');
    }

    public function testNearestCellsAreRequestedFirst(): void
    {
        [$s, $log] = $this->rig(self::config(['prefetchRadius' => 0, 'maxRequestsPerFrame' => 1, 'maxInFlight' => 100]), 100);
        for ($i = 0; $i < 6; $i++) {
            $log->frame++;
            $s->update(650.0, 650.0);
        }

        $this->assertSame('6:6', $log->requests[0][0], 'the focus cell comes first');
        $ring = static fn (string $k): int => max(abs((int) explode(':', $k)[0] - 6), abs((int) explode(':', $k)[1] - 6));
        $this->assertLessThanOrEqual(1, $ring($log->requests[1][0]));
        $this->assertLessThanOrEqual(1, $ring($log->requests[5][0]));
    }

    public function testCellsOutsideTheGridAreNeverWanted(): void
    {
        [$s, $log] = $this->rig(self::config(['gridWidth' => 4, 'gridHeight' => 4]), 100);
        $this->settle($s, $log, 50.0, 50.0);

        foreach (array_keys($s->wantedCells()) as $key) {
            [$cx, $cz] = array_map('intval', explode(':', $key));
            $this->assertTrue($cx >= 0 && $cx < 4 && $cz >= 0 && $cz < 4, $key);
        }
    }

    public function testUnboundedGridAllowsNegativeCells(): void
    {
        [$s, $log] = $this->rig(self::config(['gridWidth' => null, 'gridHeight' => null, 'prefetchRadius' => 0, 'loadRadius' => 1]), 100);
        $this->settle($s, $log, -50.0, -250.0);

        $this->assertArrayHasKey('-1:-3', $s->residentCells());
    }

    public function testClearEvictsEverything(): void
    {
        [$s, $log] = $this->rig(self::config(['prefetchRadius' => 0]), 100);
        $this->settle($s, $log, 650.0, 650.0);
        $n = count($s->residentCells());

        $out = $s->clear();

        $this->assertCount($n, $out);
        $this->assertSame([], $s->residentCells());
        $this->assertCount($n, $log->evictions);
    }

    public function testArrayResultsAreChargedByTheirStringLengthsAgainstTheUploadBudget(): void
    {
        [$s, $log] = $this->rig(self::config(['prefetchRadius' => 0, 'loadRadius' => 1, 'uploadBudgetBytes' => 5000, 'lodByRing' => [0]]), 100, 'parts');

        $installs = 0;
        for ($i = 0; $i < 30; $i++) {
            $log->frame++;
            $f = $s->update(650.0, 650.0);
            $installs += count($f->applied);
            $this->assertLessThanOrEqual(1, count($f->applied), '3000 + 1000 bytes per cell: two would pass the 5000 budget');
            $this->assertSame(4000 * count($f->applied), $f->appliedBytes);
        }

        $this->assertSame(9, $installs, 'all nine cells of the load ring, one per frame');
    }

    public function testConfigRejectsNonsense(): void
    {
        foreach ([
            ['cellSize' => 0.0],
            ['cellSize' => 10.0, 'loadRadius' => 3, 'unloadRadius' => 2],
            ['cellSize' => 10.0, 'lodByRing' => []],
            ['cellSize' => 10.0, 'maxInFlight' => 0],
        ] as $args) {
            try {
                new CellStreamerConfig(...$args);
                $this->fail('expected InvalidArgumentException for ' . json_encode($args));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testWithRealWorkerThreadsTheWalkSettlesAndEveryResultMatchesItsCell(): void
    {
        if (!ParallelCapability::isAvailable()) {
            $this->markTestSkipped('ext-parallel (ZTS) not available');
        }
        $pool = new AsyncJobPool(2, '512M', 30.0, 2);
        $pool->register('bake', CellBakeJob::class . '::bake');
        $this->pools[] = $pool;
        [$s, $log] = $this->rig(self::config(['uploadBudgetBytes' => 1 << 20]), 0, 'bake', 0, $pool);
        // The rig's clock is fake; threads need real time to answer.

        $deadline = hrtime(true) + 20_000_000_000;
        $x = 150.0;
        while (hrtime(true) < $deadline && ($x < 950.0 || !$s->isSettled())) {
            $x = min(950.0, $x + 4.0);
            $log->frame++;
            $s->update($x, 150.0);
        }

        $this->assertTrue($s->isSettled(), 'settled within 20 s');
        $this->assertGreaterThan(20, count($log->applies));
        foreach ($log->applies as [$key, $lod, $epoch, , $value]) {
            $this->assertStringStartsWith("{$key}:{$lod}:{$epoch}|", (string) $value);
        }
        $triples = array_map(static fn (array $r): string => "{$r[0]}@{$r[1]}/{$r[2]}", $log->requests);
        $this->assertSame($triples, array_values(array_unique($triples)), 'no duplicate request with real threads either');
    }
}
