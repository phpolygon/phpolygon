<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Character\DNA\Mapping;

use PHPUnit\Framework\TestCase;
use PHPolygon\Character\DNA\Mapping\ContinuousRange;

class ContinuousRangeTest extends TestCase
{
    public function testStoresMinAndMax(): void
    {
        $range = new ContinuousRange(2.0, 8.0);
        $this->assertSame(2.0, $range->min);
        $this->assertSame(8.0, $range->max);
    }

    public function testCodonZeroMapsToMin(): void
    {
        $range = new ContinuousRange(2.0, 8.0);
        $this->assertSame(2.0, $range->map(0));
    }

    public function testCodon63MapsToMax(): void
    {
        $range = new ContinuousRange(2.0, 8.0);
        $this->assertEqualsWithDelta(8.0, $range->map(63), 1e-9);
    }

    public function testMidCodonInterpolatesLinearly(): void
    {
        $range    = new ContinuousRange(0.0, 63.0);
        // With min=0,max=63 the mapping is the identity codon/63*63 = codon.
        $this->assertEqualsWithDelta(31.0, $range->map(31), 1e-9);
    }

    public function testProgressionIsMonotonicIncreasing(): void
    {
        $range = new ContinuousRange(-1.0, 1.0);
        $prev  = $range->map(0);
        for ($codon = 1; $codon <= 63; $codon++) {
            $value = $range->map($codon);
            $this->assertGreaterThan($prev, $value);
            $prev = $value;
        }
    }

    public function testHandlesNegativeRange(): void
    {
        $range = new ContinuousRange(-5.0, -1.0);
        $this->assertSame(-5.0, $range->map(0));
        $this->assertEqualsWithDelta(-1.0, $range->map(63), 1e-9);
    }
}
