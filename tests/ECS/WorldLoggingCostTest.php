<?php

declare(strict_types=1);

namespace PHPolygon\Tests\ECS;

use PHPUnit\Framework\TestCase;
use PHPolygon\ECS\World;
use PHPolygon\Log\Log;
use PHPolygon\Tests\Log\RecordingLogWriter;

/**
 * Plan item 5: the automatic hooks add at most 0.1 ms to a frame with 100
 * systems, without trace mode. Measured as the per-frame overhead of
 * Log::$hooks on vs off (hooks toggled directly - no writer/thread involved,
 * since the cap is about what the hooks themselves cost the main thread, not
 * disk or channel I/O).
 *
 * Interleaved A/B blocks with a warm-up each, median of each side, taken on
 * whatever this machine was doing at the time - documented in the task
 * report together with the measured numbers, not asserted as a fixed
 * microsecond figure beyond the plan's 0.1 ms cap.
 */
final class WorldLoggingCostTest extends TestCase
{
    private const int SYSTEMS = 100;
    private const int BLOCKS = 8;
    private const int FRAMES_PER_BLOCK = 300;
    private const int WARMUP_FRAMES = 5;
    private const int CAP_NS = 100_000; // 0.1 ms

    protected function tearDown(): void
    {
        Log::reset();
        parent::tearDown();
    }

    public function testHooksOnOverheadStaysUnderTheCapFor100Systems(): void
    {
        $writer = new RecordingLogWriter();
        Log::startWithWriter($writer, hooks: false, trace: false);

        $world = new World();
        for ($i = 0; $i < self::SYSTEMS; $i++) {
            $world->addSystem(new RecordingSystem());
        }

        $onTimesNs = [];
        $offTimesNs = [];

        for ($block = 0; $block < self::BLOCKS; $block++) {
            $offTimesNs = [...$offTimesNs, ...$this->measureBlock($world, false)];
            $onTimesNs = [...$onTimesNs, ...$this->measureBlock($world, true)];
        }

        $offMedian = self::median($offTimesNs);
        $onMedian = self::median($onTimesNs);
        $overheadNs = $onMedian - $offMedian;

        $this->assertLessThan(
            self::CAP_NS,
            $overheadNs,
            sprintf(
                'hooks overhead per frame (update+render, %d systems): %.1f us; hooks-on median %.1f us, hooks-off median %.1f us (writes went to memory, no disk/thread involved)',
                self::SYSTEMS,
                $overheadNs / 1000,
                $onMedian / 1000,
                $offMedian / 1000,
            ),
        );
    }

    /** @return list<int> nanoseconds per frame (update + render) */
    private function measureBlock(World $world, bool $hooks): array
    {
        Log::setHooksEnabled($hooks);
        for ($w = 0; $w < self::WARMUP_FRAMES; $w++) {
            $world->update(0.016);
            $world->render();
        }

        $times = [];
        for ($f = 0; $f < self::FRAMES_PER_BLOCK; $f++) {
            $start = hrtime(true);
            $world->update(0.016);
            $world->render();
            $times[] = hrtime(true) - $start;
        }

        return $times;
    }

    /** @param list<int> $values */
    private static function median(array $values): int
    {
        sort($values);

        return $values[intdiv(count($values), 2)];
    }
}
