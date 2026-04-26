<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Navigation;

use PHPUnit\Framework\TestCase;
use PHPolygon\Navigation\BinaryHeap;

class BinaryHeapTest extends TestCase
{
    public function testEmptyHeap(): void
    {
        $heap = new BinaryHeap();
        $this->assertTrue($heap->isEmpty());
        $this->assertSame(0, $heap->count());
        $this->assertNull($heap->extractMin());
    }

    public function testInsertAndExtractMin(): void
    {
        $heap = new BinaryHeap();
        $heap->insert(1, 5.0);
        $heap->insert(2, 3.0);
        $heap->insert(3, 7.0);

        $this->assertSame(3, $heap->count());

        $min = $heap->extractMin();
        $this->assertSame(2, $min[0]);
        $this->assertEqualsWithDelta(3.0, $min[1], 1e-6);
    }

    public function testExtractsInPriorityOrder(): void
    {
        $heap = new BinaryHeap();
        $heap->insert(10, 50.0);
        $heap->insert(20, 10.0);
        $heap->insert(30, 30.0);
        $heap->insert(40, 5.0);
        $heap->insert(50, 20.0);

        $order = [];
        while (!$heap->isEmpty()) {
            $entry = $heap->extractMin();
            $order[] = $entry[0];
        }

        $this->assertSame([40, 20, 50, 30, 10], $order);
    }

    public function testDecreaseKey(): void
    {
        $heap = new BinaryHeap();
        $heap->insert(1, 10.0);
        $heap->insert(2, 20.0);
        $heap->insert(3, 15.0);

        $heap->decreaseKey(2, 5.0);

        $min = $heap->extractMin();
        $this->assertSame(2, $min[0]);
        $this->assertEqualsWithDelta(5.0, $min[1], 1e-6);
    }

    public function testDecreaseKeyIgnoresHigherPriority(): void
    {
        $heap = new BinaryHeap();
        $heap->insert(1, 10.0);
        $heap->decreaseKey(1, 20.0); // Higher, should be ignored

        $min = $heap->extractMin();
        $this->assertEqualsWithDelta(10.0, $min[1], 1e-6);
    }

    public function testContains(): void
    {
        $heap = new BinaryHeap();
        $heap->insert(42, 1.0);

        $this->assertTrue($heap->contains(42));
        $this->assertFalse($heap->contains(99));

        $heap->extractMin();
        $this->assertFalse($heap->contains(42));
    }

    public function testManyInsertions(): void
    {
        $heap = new BinaryHeap();
        $values = range(100, 1, -1);

        foreach ($values as $v) {
            $heap->insert($v, (float) $v);
        }

        $this->assertSame(100, $heap->count());

        $prev = -1.0;
        while (!$heap->isEmpty()) {
            $entry = $heap->extractMin();
            $this->assertGreaterThanOrEqual($prev, $entry[1]);
            $prev = $entry[1];
        }
    }
}
