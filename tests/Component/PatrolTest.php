<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Component;

use PHPolygon\Component\Patrol;
use PHPolygon\Component\PatrolAxis;
use PHPUnit\Framework\TestCase;

class PatrolTest extends TestCase
{
    public function testReversedBoundsAreSwappedSoTheSystemDoesNotPingPongInPlace(): void
    {
        // PatrolSystem advances `value += dir * speed`, then clamps to ±bounds.
        // With min > max BOTH `value > $max` AND `value < $min` trip on the
        // same tick — the entity would oscillate stationary forever. The
        // constructor must restore min <= max so the system has room to move.
        $p = new Patrol(axis: PatrolAxis::X, min: 5.0, max: -5.0);
        $this->assertSame(-5.0, $p->min);
        $this->assertSame(5.0, $p->max);
    }

    public function testDirIsClampedToPlusOrMinusOne(): void
    {
        // 0 freezes the entity (value += 0 * speed); any non-±1 just yields
        // the wrong stride per tick. Clamp to a canonical ±1.
        $this->assertSame(1, (new Patrol(dir: 0))->dir, 'dir=0 must be promoted to +1');
        $this->assertSame(1, (new Patrol(dir: 3))->dir, 'dir=3 must be promoted to +1');
        $this->assertSame(-1, (new Patrol(dir: -7))->dir, 'dir=-7 must be promoted to -1');
    }
}
