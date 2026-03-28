<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Thread;

use PHPUnit\Framework\TestCase;
use PHPolygon\Thread\ParallelCapability;
use PHPolygon\Thread\ThreadingMode;

class ParallelCapabilityTest extends TestCase
{
    public function testGetCpuCountReturnsPositiveInteger(): void
    {
        $count = ParallelCapability::getCpuCount();
        $this->assertGreaterThan(0, $count);
    }

    public function testIsAvailableReturnsBool(): void
    {
        $this->assertIsBool(ParallelCapability::isAvailable());
    }

    public function testGetRecommendedThreadCountIsAtLeastOne(): void
    {
        $count = ParallelCapability::getRecommendedThreadCount();
        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertLessThanOrEqual(8, $count);
    }

    public function testGetRecommendedThreadCountIsLessThanCpuCount(): void
    {
        $recommended = ParallelCapability::getRecommendedThreadCount();
        $cpuCount = ParallelCapability::getCpuCount();
        $this->assertLessThan($cpuCount, $recommended);
    }

    public function testResolveModeReturnsExplicitChoice(): void
    {
        $this->assertSame(
            ThreadingMode::SingleThreaded,
            ParallelCapability::resolveMode(ThreadingMode::SingleThreaded),
        );
        $this->assertSame(
            ThreadingMode::MultiThreaded,
            ParallelCapability::resolveMode(ThreadingMode::MultiThreaded),
        );
    }

    public function testResolveModeAutoDetectsWhenNull(): void
    {
        $mode = ParallelCapability::resolveMode(null);
        $this->assertInstanceOf(ThreadingMode::class, $mode);

        if (ParallelCapability::isAvailable()) {
            $this->assertSame(ThreadingMode::MultiThreaded, $mode);
        } else {
            $this->assertSame(ThreadingMode::SingleThreaded, $mode);
        }
    }
}
