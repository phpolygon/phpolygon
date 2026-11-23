<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Runtime\GameLoop;
use PHPUnit\Framework\TestCase;

final class GameLoopFrameIntervalTest extends TestCase
{
    public function testNothingBeforeTheFirstFrame(): void
    {
        $loop = new GameLoop(60.0, variableTimestep: true);
        self::assertSame([], $loop->frameIntervalsMs());
        self::assertSame(0.0, $loop->lastFrameIntervalMs());
    }

    public function testTheIntervalIncludesTheUpdateWork(): void
    {
        $loop = new GameLoop(60.0, variableTimestep: true);
        $frames = 0;
        $loop->run(
            static function (float $dt): void {
                usleep(8000); // update work: 8 ms
            },
            static function (float $i): void {
            },
            static function () use (&$frames): bool {
                return ++$frames > 6;
            },
        );
        $intervals = $loop->frameIntervalsMs();
        self::assertCount(6, $intervals);
        // The first interval is the loop's own start-up; the later ones span update + render.
        foreach (array_slice($intervals, 1) as $ms) {
            self::assertGreaterThanOrEqual(7.5, $ms, 'a whole interval contains the 8 ms update');
        }
        self::assertSame($intervals[5], $loop->lastFrameIntervalMs());
    }

    public function testTheRingKeepsTheLatestSixtyOldestFirst(): void
    {
        $loop = new GameLoop(60.0, variableTimestep: true);
        $frames = 0;
        $loop->run(
            static function (float $dt) use (&$frames): void {
                // Frame n takes about n ms, so the intervals grow with the frame number.
                usleep(1000 * min($frames, 3));
            },
            static function (float $i): void {
            },
            static function () use (&$frames): bool {
                return ++$frames > 75;
            },
        );
        $intervals = $loop->frameIntervalsMs();
        self::assertCount(60, $intervals);
        self::assertSame($intervals[59], $loop->lastFrameIntervalMs());
    }

    public function testAFullRingIsReadOldestFirst(): void
    {
        $loop = new GameLoop(60.0);
        $ring = new \ReflectionProperty($loop, 'frameTimeSamples');
        $index = new \ReflectionProperty($loop, 'sampleIndex');
        // 60 samples of 1..60 ms, the writer has wrapped to position 10: oldest is sample[10].
        $samples = [];
        for ($i = 0; $i < 60; $i++) {
            $samples[$i] = ($i + 1) / 1000.0;
        }
        $ring->setValue($loop, $samples);
        $index->setValue($loop, 10);

        $out = $loop->frameIntervalsMs();
        self::assertCount(60, $out);
        self::assertEqualsWithDelta(11.0, $out[0], 1e-9);
        self::assertEqualsWithDelta(10.0, $out[59], 1e-9);
        self::assertEqualsWithDelta(10.0, $loop->lastFrameIntervalMs(), 1e-9);
    }

    public function testTheEngineNamesTheTwoMeasurementsApart(): void
    {
        $engine = new Engine(new EngineConfig(headless: true));
        $frames = 0;
        $engine->onRender(static function (Engine $e) use (&$frames): void {
            if (++$frames === 8) {
                $e->stop();
            }
        });
        $engine->run();

        self::assertSame($engine->frameTimesMs, $engine->renderTimesMs());
        self::assertNotSame([], $engine->frameIntervalsMs());
        self::assertSame($engine->gameLoop->lastFrameIntervalMs(), $engine->lastFrameIntervalMs());
        self::assertGreaterThan(0.0, $engine->lastFrameIntervalMs());
    }
}
