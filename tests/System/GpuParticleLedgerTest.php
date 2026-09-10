<?php

declare(strict_types=1);

namespace PHPolygon\Tests\System;

use PHPolygon\Component\ParticleEmitter;
use PHPolygon\System\GpuParticleLedger;
use PHPUnit\Framework\TestCase;

/**
 * Pure-PHP ring bookkeeping of a GPU-simulated emitter: live count, cap,
 * expiry, ring wrap, conservative release after a lifetime change and the
 * rewind applied to rows spawned part-way through a render interval.
 */
final class GpuParticleLedgerTest extends TestCase
{
    public function testQueuedRowsCountAsLiveAndPending(): void
    {
        $ledger = new GpuParticleLedger(10);
        $ledger->advance(0.1);

        self::assertSame(3, $ledger->queue(self::rows(3), 1.0));
        self::assertSame(3, $ledger->count());
        self::assertSame(3, $ledger->pendingCount());
        self::assertSame(7, $ledger->room());
        self::assertSame(0, $ledger->head(), 'the head only moves once rows are written');

        $bytes = $ledger->takePending(0.0, 0.0, 0.0);
        self::assertSame(3 * GpuParticleLedger::ROW_FLOATS * 4, strlen($bytes));
        self::assertSame(0, $ledger->pendingCount());
        self::assertSame(3, $ledger->count(), 'written rows stay live');
    }

    public function testQueueIsCappedByTheFreeSlots(): void
    {
        $ledger = new GpuParticleLedger(5);
        $ledger->advance(0.016);

        self::assertSame(4, $ledger->queue(self::rows(4), 10.0));
        self::assertSame(1, $ledger->queue(self::rows(3), 10.0));
        self::assertSame(0, $ledger->queue(self::rows(1), 10.0));
        self::assertSame(5, $ledger->count());
        self::assertSame(0, $ledger->room());
    }

    public function testBatchesAreReleasedOnceTheirLifetimeHasPassed(): void
    {
        $ledger = new GpuParticleLedger(16);
        $ledger->advance(0.1);
        $ledger->queue(self::rows(2), 0.5);       // expires at 0.6
        $ledger->takePending(0.0, 0.0, 0.0);
        $ledger->advanceHead(2);
        $ledger->advance(0.1);
        $ledger->queue(self::rows(3), 0.5);       // expires at 0.7
        self::assertSame(5, $ledger->count());

        $ledger->advance(0.35);                    // 0.55
        self::assertSame(5, $ledger->count());
        $ledger->advance(0.1);                     // 0.65
        self::assertSame(3, $ledger->count());
        $ledger->advance(0.1);                     // 0.75
        self::assertSame(0, $ledger->count());
        self::assertSame(16, $ledger->room());
    }

    public function testRowsThatExpireBeforeTheyAreWrittenAreNeverWritten(): void
    {
        $ledger = new GpuParticleLedger(8);
        $ledger->advance(0.016);
        $ledger->queue(self::rows(2), 0.1);
        $ledger->advance(0.5);

        self::assertSame(0, $ledger->count());
        self::assertSame(0, $ledger->pendingCount());
        self::assertSame('', $ledger->takePending(0.0, 0.0, 0.0));
        self::assertSame(0, $ledger->head());
    }

    public function testHeadWrapsAroundTheRing(): void
    {
        $ledger = new GpuParticleLedger(4);
        $ledger->advance(0.1);
        $ledger->queue(self::rows(3), 0.2);
        $ledger->takePending(0.0, 0.0, 0.0);
        $ledger->advanceHead(3);
        self::assertSame(3, $ledger->head());

        $ledger->advance(0.5);
        self::assertSame(0, $ledger->count());
        self::assertSame(3, $ledger->queue(self::rows(3), 0.2));
        $ledger->takePending(0.0, 0.0, 0.0);
        $ledger->advanceHead(3);
        self::assertSame(2, $ledger->head(), 'slots 3, 0, 1 were written; the next batch starts at 2');
    }

    public function testCountStaysConservativeWhenTheLifetimeShrinks(): void
    {
        $ledger = new GpuParticleLedger(8);
        $ledger->advance(0.1);
        $ledger->queue(self::rows(2), 1.0);       // A: expires at 1.1
        $ledger->advance(0.1);
        $ledger->queue(self::rows(2), 0.1);       // B: expires at 0.3, but sits behind A

        $ledger->advance(0.5);                     // 0.7: B expired, A alive
        self::assertSame(4, $ledger->count(), 'B is only released after A, so the head never reaches A');
        self::assertSame(4, $ledger->room());

        $ledger->advance(0.6);                     // 1.3
        self::assertSame(0, $ledger->count());
    }

    public function testSeededRowsAreReleasedInSlotOrder(): void
    {
        $ledger = new GpuParticleLedger(8);
        $ledger->seed([
            [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.5, 1.0], // 0.5 s left
            [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 1.0], // 1.0 s left
            [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 2.0, 1.0], // already dead, but behind a live row
        ]);
        self::assertSame(3, $ledger->count());
        self::assertSame(3, $ledger->head());

        $ledger->advance(0.6);
        self::assertSame(2, $ledger->count());
        $ledger->advance(0.5);
        self::assertSame(0, $ledger->count());
    }

    public function testPendingRowsAreRewoundToTheirSpawnTime(): void
    {
        $gy = -10.0;
        $ledger = new GpuParticleLedger(4);
        $ledger->advance(0.02);                    // simulated before the spawn
        $ledger->queue([[1.0, 2.0, 3.0, 0.5, 5.0, 0.0, 0.0, 1.0]], 1.0);
        $ledger->advance(0.03);                    // simulated after the spawn

        $r = self::floats($ledger->takePending(0.0, $gy, 0.0));
        self::assertCount(GpuParticleLedger::ROW_FLOATS, $r);
        $span = $ledger->takeDt();
        self::assertEqualsWithDelta(0.05, $span, 1e-12);
        self::assertSame(0.0, $ledger->takeDt());

        // One GPU step over the whole span (semi-implicit Euler, as the shader).
        $vy = $r[4] + $gy * $span;
        $py = $r[1] + $vy * $span;
        $px = $r[0] + $r[3] * $span;
        $age = $r[6] + $span;

        // ... lands where 0.03 s of simulation since the spawn puts the particle.
        self::assertEqualsWithDelta(5.0 + $gy * 0.03, $vy, 1e-5);
        self::assertEqualsWithDelta(2.0 + (5.0 + $gy * 0.03) * 0.03, $py, 1e-5);
        self::assertEqualsWithDelta(1.0 + 0.5 * 0.03, $px, 1e-5);
        self::assertEqualsWithDelta(0.03, $age, 1e-5);
        self::assertSame(1.0, $r[7]);
    }

    public function testCapacityAndGenerationDecideWhetherTheLedgerIsCurrent(): void
    {
        $emitter = new ParticleEmitter(maxParticles: 8);
        $ledger = new GpuParticleLedger($emitter->maxParticles, $emitter->generation);
        self::assertTrue($ledger->isCurrent($emitter->maxParticles, $emitter->generation));

        $emitter->clear();
        self::assertFalse($ledger->isCurrent($emitter->maxParticles, $emitter->generation), 'clear() starts a new generation');

        $fresh = new GpuParticleLedger($emitter->maxParticles, $emitter->generation);
        self::assertTrue($fresh->isCurrent($emitter->maxParticles, $emitter->generation));
        self::assertSame(0, $fresh->count());
        self::assertFalse($fresh->isCurrent(16, $emitter->generation), 'a changed cap needs a new ring');
    }

    /** @return list<float> */
    private static function floats(string $bytes): array
    {
        $out = [];
        foreach (unpack('f*', $bytes) ?: [] as $f) {
            if (is_float($f)) {
                $out[] = $f;
            }
        }
        return $out;
    }

    /**
     * @return list<array{0:float,1:float,2:float,3:float,4:float,5:float,6:float,7:float}>
     */
    private static function rows(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [(float) $i, 0.0, 0.0, 0.0, 1.0, 0.0, 0.0, 1.0];
        }
        return $out;
    }
}
