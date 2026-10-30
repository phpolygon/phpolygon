<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\Runtime\FrameSpikeRecorder;
use PHPolygon\Runtime\GameLoop;
use PHPUnit\Framework\TestCase;

final class FrameSpikeRecorderTest extends TestCase
{
    private const MS = 1_000_000;

    /** @var list<string> */
    private array $lines = [];

    private int $now = 1_000_000_000;

    protected function tearDown(): void
    {
        FrameSpikeRecorder::activate(null);
    }

    /** @param array<string, mixed> $options */
    private function recorder(array $options = []): FrameSpikeRecorder
    {
        $this->lines = [];
        return new FrameSpikeRecorder(...array_merge([
            'sink' => function (string $line): void {
                $this->lines[] = $line;
            },
            'warmupFrames' => 10,
            'minLineIntervalSeconds' => 0.0,
        ], $options));
    }

    /** Frames of a fixed length on a synthetic clock. */
    private function frames(FrameSpikeRecorder $recorder, int $count, float $ms): void
    {
        for ($i = 0; $i < $count; $i++) {
            $recorder->frame($this->now);
            $this->now += (int) ($ms * self::MS);
        }
    }

    private function spikeLines(): array
    {
        return array_values(array_filter($this->lines, static fn(string $l): bool => str_starts_with($l, '[frame-spike]')));
    }

    /** Real frames on the real clock, so section marks and frame() agree. */
    private function realFrame(FrameSpikeRecorder $recorder, ?callable $update = null): void
    {
        $recorder->frame(hrtime(true));
        $recorder->beginUpdate();
        if ($update !== null) {
            $update();
        }
        $recorder->enter(FrameSpikeRecorder::RENDER);
        $recorder->enter(FrameSpikeRecorder::OTHER);
    }

    public function testAFrameWithoutASpikeWritesNothing(): void
    {
        $recorder = $this->recorder();
        $this->frames($recorder, 500, 16.0);

        $this->assertSame([], $this->lines);
        $this->assertSame(0, $recorder->spikeCount());
    }

    public function testFloorDecidesAtHighFrameRates(): void
    {
        $recorder = $this->recorder();
        $this->frames($recorder, 50, 8.0);
        $this->frames($recorder, 1, 32.0);
        $this->frames($recorder, 1, 34.0);
        $recorder->frame($this->now);

        $this->assertSame(1, $recorder->spikeCount());
        $this->assertStringContainsString('frame=34.00ms limit=33.00ms median=8.00ms', $this->spikeLines()[0]);
    }

    public function testMedianDecidesAtLowFrameRates(): void
    {
        $recorder = $this->recorder();
        $this->frames($recorder, 50, 40.0);
        $this->frames($recorder, 1, 90.0);
        $this->frames($recorder, 1, 110.0);
        $recorder->frame($this->now);

        $this->assertSame(1, $recorder->spikeCount());
        $this->assertStringContainsString('frame=110.00ms limit=100.00ms', $this->spikeLines()[0]);
    }

    public function testNothingIsJudgedDuringWarmup(): void
    {
        $recorder = $this->recorder(['warmupFrames' => 20]);
        $this->frames($recorder, 10, 100.0);
        $recorder->frame($this->now);

        $this->assertSame(0, $recorder->spikeCount());
    }

    public function testRateLimitCountsWhatItDoesNotWrite(): void
    {
        $recorder = $this->recorder(['minLineIntervalSeconds' => 1.0]);
        $this->frames($recorder, 20, 10.0);
        // Three spikes within a second, then one after it.
        $this->frames($recorder, 3, 100.0);
        $this->frames($recorder, 80, 10.0);
        $this->frames($recorder, 1, 100.0);
        $recorder->frame($this->now);

        $spikes = $this->spikeLines();
        $this->assertSame(4, $recorder->spikeCount());
        $this->assertCount(2, $spikes);
        $this->assertStringContainsString(' suppressed=2', $spikes[1]);

        $recorder->finish();
        $this->assertStringContainsString('spikes=4 written=2 suppressed=2', end($this->lines));
    }

    public function testMaxLinesCapsASession(): void
    {
        $recorder = $this->recorder(['maxLines' => 2]);
        $this->frames($recorder, 20, 10.0);
        for ($i = 0; $i < 5; $i++) {
            $this->frames($recorder, 1, 100.0);
            $this->frames($recorder, 1, 10.0);
        }
        $this->assertCount(2, $this->spikeLines());
        $this->assertSame(5, $recorder->spikeCount());
    }

    public function testSessionSummaryHasPercentilesAndCounts(): void
    {
        $recorder = $this->recorder();
        $this->frames($recorder, 900, 10.0);
        $this->frames($recorder, 90, 20.0);
        $this->frames($recorder, 10, 200.0);
        $recorder->frame($this->now);
        $recorder->finish();
        $recorder->finish();

        $summary = end($this->lines);
        $this->assertCount(1, array_filter($this->lines, static fn(string $l): bool => str_starts_with($l, '[frame-session]')));
        $this->assertStringContainsString('frames=1000 ', $summary);
        $this->assertStringContainsString('spikes=10 ', $summary);
        $this->assertEqualsWithDelta(10.0, $recorder->percentileMs(50.0), 0.26);
        $this->assertEqualsWithDelta(20.0, $recorder->percentileMs(95.0), 0.26);
        $this->assertEqualsWithDelta(20.0, $recorder->percentileMs(99.0), 0.26);
        $this->assertEqualsWithDelta(200.0, $recorder->percentileMs(99.5), 0.26);
        $this->assertStringContainsString('max=200.00ms', $summary);
    }

    public function testAnEmptySessionWritesNoSummary(): void
    {
        $recorder = $this->recorder();
        $recorder->finish();

        $this->assertSame([], $this->lines);
    }

    public function testSuspendedFramesAreNotCountedAndResumeStartsFresh(): void
    {
        $recorder = $this->recorder();
        $this->frames($recorder, 20, 10.0);
        $recorder->suspend();
        $recorder->suspend();
        $this->frames($recorder, 5, 500.0);
        $recorder->resume();
        $this->frames($recorder, 5, 500.0);
        $recorder->resume();
        // The boundary that ends the load is not a frame either.
        $this->frames($recorder, 5, 10.0);
        $recorder->frame($this->now);

        $this->assertSame(0, $recorder->spikeCount());
        $this->assertSame(24, $recorder->frameCount());
    }

    public function testSkippedFrameIsLeftOut(): void
    {
        $recorder = $this->recorder();
        $this->frames($recorder, 20, 10.0);
        $recorder->frame($this->now);
        $recorder->skipFrame();
        $this->now += 500 * self::MS;
        $this->frames($recorder, 2, 10.0);

        $this->assertSame(0, $recorder->spikeCount());
    }

    public function testNotesGoToTheFrameTheyHappenedInAndToTheSession(): void
    {
        $recorder = $this->recorder();
        FrameSpikeRecorder::activate($recorder);
        $this->frames($recorder, 20, 10.0);

        // Inside the open frame, which grows to 40 ms.
        FrameSpikeRecorder::note('save');
        FrameSpikeRecorder::note(FrameSpikeRecorder::NOTE_SHADER, 2, 30 * self::MS);
        $this->now += 30 * self::MS;
        $this->frames($recorder, 1, 40.0);
        $recorder->frame($this->now);
        $recorder->finish();

        $spikes = $this->spikeLines();
        $this->assertCount(2, $spikes);
        $this->assertStringContainsString('cause=shader', $spikes[0]);
        $this->assertStringContainsString(' save=1', $spikes[0]);
        $this->assertStringContainsString(' shader=2/30.00ms', $spikes[0]);
        $this->assertStringNotContainsString('shader=', $spikes[1]);
        $this->assertStringContainsString(' save=1 shader=2/30.00ms', end($this->lines));
    }

    public function testNumericNoteNameSurvivesAsAnArrayKey(): void
    {
        $recorder = $this->recorder();
        FrameSpikeRecorder::activate($recorder);
        $this->frames($recorder, 20, 10.0);

        FrameSpikeRecorder::note('42', 2, 30 * self::MS);
        $this->now += 30 * self::MS;
        $this->frames($recorder, 1, 10.0);
        $recorder->frame($this->now);
        $recorder->finish();

        $this->assertStringContainsString('cause=42', $this->spikeLines()[0]);
        $this->assertStringContainsString(' 42=2/30.00ms', $this->spikeLines()[0]);
        $this->assertStringContainsString(' 42=2/30.00ms', end($this->lines));
    }

    public function testNumericContextKeyIsNotAFailedContext(): void
    {
        $recorder = $this->recorder();
        $recorder->addContext(static fn(): array => ['7' => 'seven']);
        $this->frames($recorder, 20, 10.0);
        $this->frames($recorder, 1, 50.0);
        $recorder->frame($this->now);

        $this->assertStringContainsString(' 7=seven', $this->spikeLines()[0]);
    }

    public function testSummaryCausesAreSplittableTokens(): void
    {
        $recorder = $this->recorder();
        FrameSpikeRecorder::activate($recorder);
        $this->frames($recorder, 20, 10.0);

        FrameSpikeRecorder::note('door opened', 1, 30 * self::MS);
        $this->now += 30 * self::MS;
        $this->frames($recorder, 1, 10.0);
        $recorder->frame($this->now);

        $this->assertStringContainsString(' causes=door_opened:1', $recorder->summaryLine());
    }

    public function testWarmupLongerThanTheRingIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FrameSpikeRecorder(ringSize: 10, warmupFrames: 11);
    }

    public function testWarmupEqualToTheRingStillReportsSpikes(): void
    {
        $recorder = $this->recorder(['ringSize' => 10, 'warmupFrames' => 10]);
        $this->frames($recorder, 12, 10.0);
        $this->frames($recorder, 1, 50.0);
        $recorder->frame($this->now);

        $this->assertSame(1, $recorder->spikeCount());
    }

    public function testNoteWithoutActiveRecorderDoesNothing(): void
    {
        FrameSpikeRecorder::note('nobody-listens');
        $this->addToAssertionCount(1);
    }

    public function testContextRunsOnlyForAWrittenLineAndSanitisesValues(): void
    {
        $recorder = $this->recorder();
        $calls = 0;
        $recorder->addContext(function () use (&$calls): array {
            $calls++;
            return ['region' => 'old town', 'dialog' => true, 'target' => null];
        });
        $this->frames($recorder, 20, 10.0);
        $this->assertSame(0, $calls);

        $this->frames($recorder, 1, 50.0);
        $recorder->frame($this->now);

        $this->assertSame(1, $calls);
        $this->assertStringContainsString(' region=old_town dialog=yes target=-', $this->spikeLines()[0]);
    }

    public function testFailingContextAndSinkNeverReachTheGame(): void
    {
        $recorder = $this->recorder();
        $recorder->addContext(static function (): array {
            throw new \RuntimeException('dead');
        });
        $this->frames($recorder, 20, 10.0);
        $this->frames($recorder, 1, 50.0);
        $recorder->frame($this->now);
        $this->assertStringContainsString(' context=failed', $this->spikeLines()[0]);

        $recorder->setSink(static function (): void {
            throw new \RuntimeException('disk full');
        });
        $this->frames($recorder, 1, 50.0);
        $recorder->frame($this->now);
        $recorder->finish();
        $this->assertSame(2, $recorder->spikeCount());
    }

    public function testPlainFramesDoNotGrowMemory(): void
    {
        $recorder = $this->recorder();
        $this->frames($recorder, 300, 10.0);
        $before = memory_get_usage();
        for ($i = 0; $i < 5000; $i++) {
            $recorder->frame($this->now);
            $recorder->beginUpdate();
            $recorder->enter(FrameSpikeRecorder::RENDER);
            $recorder->enter(FrameSpikeRecorder::PRESENT);
            $recorder->enter(FrameSpikeRecorder::OTHER);
            $this->now += 10 * self::MS;
        }

        $this->assertSame($before, memory_get_usage());
    }

    public function testSleepInUpdateIsNamedInTheLine(): void
    {
        $recorder = $this->recorder(['floorMs' => 20.0]);
        for ($i = 0; $i < 15; $i++) {
            $this->realFrame($recorder);
        }
        $this->realFrame($recorder, static fn() => usleep(40_000));
        $this->realFrame($recorder);

        $line = $this->spikeLines()[0] ?? '';
        $this->assertStringContainsString('cause=update', $line);
        $this->assertStringContainsString('ticks=1', $line);
        $this->assertMatchesRegularExpression('/update=(3[89]|[4-9]\d|\d{3})\.\d\dms/', $line);
    }

    public function testGarbageCollectionIsNamedInTheLine(): void
    {
        $recorder = $this->recorder(['floorMs' => 5.0]);
        // Cycles made up front: the spike frame only pays for the collector,
        // which stays off until that frame runs it by hand.
        gc_disable();
        try {
            for ($i = 0; $i < 100_000; $i++) {
                $cycle = new \stdClass();
                $cycle->self = $cycle;
            }
            unset($cycle);

            for ($i = 0; $i < 15; $i++) {
                $this->realFrame($recorder);
            }
            $this->realFrame($recorder, static fn() => gc_collect_cycles());
            $this->realFrame($recorder);
        } finally {
            gc_enable();
        }

        $line = $this->spikeLines()[0] ?? '';
        $this->assertStringContainsString('cause=gc', $line);
        $this->assertMatchesRegularExpression('/ gc=[1-9]\d*\/[1-9]\d*\.\d\dms collected=[1-9]\d{4,}/', $line);
        $this->assertMatchesRegularExpression('/ mem=-\d/', $line);
    }

    public function testFpsCapWaitIsItsOwnSection(): void
    {
        $recorder = $this->recorder(['warmupFrames' => 3, 'floorMs' => 20.0]);
        $loop = new GameLoop(60.0);
        $loop->setSpikeRecorder($recorder);
        $frames = 0;
        $loop->run(
            update: static function (float $dt): void {},
            render: static function (float $i) use ($loop, &$frames): void {
                if (++$frames === 6) {
                    $loop->setFpsCap(10);
                } elseif ($frames === 7) {
                    $loop->setFpsCap(0);
                }
            },
            shouldStop: static function () use (&$frames): bool {
                return $frames >= 8;
            },
        );

        $line = $this->spikeLines()[0] ?? '';
        $this->assertStringContainsString('cause=wait', $line);
        $this->assertMatchesRegularExpression('/wait=([5-9]\d|\d{3})\.\d\dms/', $line);
    }

    public function testEngineFeedsSectionsAndClosesTheSession(): void
    {
        $recorder = $this->recorder(['warmupFrames' => 5, 'floorMs' => 30.0]);
        $engine = new Engine(new EngineConfig(headless: true, frameSpikes: $recorder));
        $this->assertSame($recorder, $engine->frameSpikes);

        $ticks = 0;
        $engine->onUpdate(function (Engine $e) use (&$ticks): void {
            $ticks++;
            if ($ticks === 15) {
                FrameSpikeRecorder::note('region_change');
                usleep(60_000);
            }
            if ($ticks === 25) {
                $e->stop();
            }
        });
        $engine->run();

        $line = $this->spikeLines()[0] ?? '';
        $this->assertStringContainsString('cause=update', $line);
        $this->assertStringContainsString(' region_change=1', $line);
        $this->assertStringStartsWith('[frame-session]', (string) end($this->lines));
    }

    public function testHeadlessEngineHasNoRecorderUnlessAsked(): void
    {
        $this->assertNull((new Engine(new EngineConfig(headless: true)))->frameSpikes);
        $this->assertNull((new Engine(new EngineConfig(headless: true, frameSpikes: false)))->frameSpikes);
    }
}
