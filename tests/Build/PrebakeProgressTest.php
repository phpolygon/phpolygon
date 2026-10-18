<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\PrebakeProgress;
use PHPUnit\Framework\TestCase;

/**
 * Pre-bake progress in a build log: throttled (step + minimum interval, heartbeat),
 * one line per message, no control characters.
 */
final class PrebakeProgressTest extends TestCase
{
    private float $now = 1000.0;
    /** @var list<string> */
    private array $lines = [];

    private function progress(int $total): PrebakeProgress
    {
        return new PrebakeProgress(
            'voice',
            $total,
            function (string $line): void {
                $this->lines[] = $line;
            },
            fn (): float => $this->now,
        );
    }

    public function testPlanAndFinishCarryTheLabel(): void
    {
        $progress = $this->progress(2753);
        $progress->plan('2753 to render, 12661 up to date, 12 processes');
        $this->now += 6720;
        $progress->update(2753);
        $progress->finish('3 failed');

        self::assertSame([
            'voice: 2753 to render, 12661 up to date, 12 processes',
            'voice: done – 2753/2753 in 1 h 52 min (3 failed)',
        ], $this->lines);
    }

    public function testNothingToDo(): void
    {
        $progress = $this->progress(0);
        $this->now += 0.4;
        $progress->finish();

        self::assertSame(['voice: done – nothing to do in 0.4 s'], $this->lines);
    }

    public function testLineShowsPercentElapsedAndRemaining(): void
    {
        $progress = $this->progress(2753);
        $progress->update(736);

        // 29 min for 736 → ~79 min (1 h 19 min) for the other 2017
        self::assertSame('voice: 736/2753 (26 %) – 29 min, ~1 h 19 min left', $progress->line($this->now + 29 * 60));
    }

    public function testNotPerFile(): void
    {
        $progress = $this->progress(1000);
        for ($i = 1; $i <= 999; $i++) {
            $this->now += 0.5; // 500 s in total
            $progress->advance();
        }

        // one line per 5 % step at most, never closer than 10 s
        self::assertLessThanOrEqual(19, count($this->lines));
        self::assertGreaterThanOrEqual(10, count($this->lines));
    }

    public function testAStepWaitsForTheMinimumInterval(): void
    {
        $progress = $this->progress(100);
        $this->now += 3;
        $progress->update(50); // 50 % after 3 s: too early
        self::assertSame([], $this->lines);

        $this->now += 7;
        $progress->tick(); // 10 s: the pending step is due
        self::assertSame(['voice: 50/100 (50 %) – 10 s, ~10 s left'], $this->lines);

        $this->now += 15;
        $progress->tick(); // same step, no heartbeat yet
        self::assertCount(1, $this->lines);
    }

    public function testHeartbeatWithoutProgress(): void
    {
        $progress = $this->progress(4);
        $this->now += 59;
        $progress->tick();
        self::assertSame([], $this->lines);

        $this->now += 1;
        $progress->tick();
        self::assertSame(['voice: 0/4 (0 %) – 1 min'], $this->lines);
    }

    public function testTheLastPartLeavesTheLineToFinish(): void
    {
        $progress = $this->progress(10);
        $this->now += 100;
        $progress->update(10);
        self::assertSame([], $this->lines);
        self::assertSame(10, $progress->done());
    }

    public function testLinesHaveNoControlCharacters(): void
    {
        $progress = $this->progress(10);
        $progress->plan('Plan');
        for ($i = 0; $i < 10; $i++) {
            $this->now += 30;
            $progress->advance();
        }
        $progress->finish('ok');

        self::assertNotEmpty($this->lines);
        foreach ($this->lines as $line) {
            self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $line);
            self::assertStringNotContainsString('—', $line);
        }
    }

    public function testTheRunCanStartBeforeTheProgress(): void
    {
        $progress = new PrebakeProgress('music', 0, function (string $line): void {
            $this->lines[] = $line;
        }, fn (): float => $this->now, start: $this->now - 42);
        $progress->finish();

        self::assertSame(['music: done – nothing to do in 42 s'], $this->lines);
    }

    public function testDuration(): void
    {
        self::assertSame('4.2 s', PrebakeProgress::duration(4.24));
        self::assertSame('42 s', PrebakeProgress::duration(42.4));
        self::assertSame('4 min 10 s', PrebakeProgress::duration(250));
        self::assertSame('29 min', PrebakeProgress::duration(29 * 60 + 10));
        self::assertSame('1 h 52 min', PrebakeProgress::duration(6720));
        self::assertSame('0.0 s', PrebakeProgress::duration(-3));
    }

    public function testTruthyValues(): void
    {
        self::assertTrue(PrebakeProgress::truthy('1'));
        self::assertTrue(PrebakeProgress::truthy(' TRUE '));
        self::assertTrue(PrebakeProgress::truthy('on'));
        self::assertFalse(PrebakeProgress::truthy('0'));
        self::assertFalse(PrebakeProgress::truthy(''));
        self::assertFalse(PrebakeProgress::truthy(false));
        self::assertFalse(PrebakeProgress::truthy(null));
    }

    public function testStrictReadsTheNamedVariable(): void
    {
        $name = 'PHPOLYGON_TEST_PREBAKE_STRICT_' . getmypid();
        putenv("$name=yes");
        try {
            self::assertTrue(PrebakeProgress::strict($name));
            putenv("$name=0");
            self::assertFalse(PrebakeProgress::strict($name));
        } finally {
            putenv($name);
        }
        self::assertFalse(PrebakeProgress::strict($name));
        self::assertSame('PHPOLYGON_PREBAKE_STRICT', PrebakeProgress::STRICT_ENV);
    }
}
