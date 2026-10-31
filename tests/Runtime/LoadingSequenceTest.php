<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use PHPolygon\Runtime\LoadingSequence;

/**
 * The course of a loading screen without a GPU: lines, frames, bar, minimum time.
 */
final class LoadingSequenceTest extends TestCase
{
    private float $now = 10.0;

    /** @var list<array{step: string, label: string, ticks: int}> */
    private const array LINES = [
        ['step' => 'first', 'label' => 'line.first', 'ticks' => 1],
        ['step' => 'warm', 'label' => 'line.warm', 'ticks' => 3],
        ['step' => 'grass', 'label' => 'line.grass', 'ticks' => 4],
        ['step' => 'last', 'label' => 'line.last', 'ticks' => 2],
    ];

    /** @param list<string> $done */
    private function sequence(float $minSeconds = 0.8, array $done = []): LoadingSequence
    {
        return new LoadingSequence(self::LINES, $minSeconds, $done, fn(): float => $this->now);
    }

    /** @return list<string> */
    private function run_(LoadingSequence $sequence): array
    {
        $steps = [];
        while (($work = $sequence->next()) !== null) {
            $steps[] = $work['step'];
        }
        return $steps;
    }

    public function testStepsRunInOrderAndSpreadOverFrames(): void
    {
        self::assertSame(
            ['first', 'warm', 'warm', 'warm', 'grass', 'grass', 'grass', 'grass', 'last', 'last'],
            $this->run_($this->sequence()),
        );
    }

    public function testTicksCountWithinTheLine(): void
    {
        $sequence = $this->sequence();
        self::assertSame(['step' => 'first', 'tick' => 0], $sequence->next());
        self::assertSame(['step' => 'warm', 'tick' => 0], $sequence->next());
        self::assertSame(['step' => 'warm', 'tick' => 1], $sequence->next());
        self::assertSame(['step' => 'warm', 'tick' => 2], $sequence->next());
        self::assertSame(['step' => 'grass', 'tick' => 0], $sequence->next());
    }

    public function testNextStaysNullAfterTheLastLine(): void
    {
        $sequence = $this->sequence();
        $this->run_($sequence);
        self::assertNull($sequence->next());
        self::assertNull($sequence->next());
        self::assertTrue($sequence->workDone());
    }

    public function testTheScreenHoldsForTheMinimumTimeByTheClock(): void
    {
        $sequence = $this->sequence(0.8);
        $this->run_($sequence);

        self::assertTrue($sequence->workDone());
        $this->now = 10.79;
        self::assertFalse($sequence->finished(), 'work done, the clock not: however many frames went by');
        $this->now = 10.8;
        self::assertTrue($sequence->finished());
        self::assertEqualsWithDelta(0.8, $sequence->elapsed(), 1e-9);
    }

    public function testLongWorkIsNotStretchedAndTimeNeverEndsUnfinishedWork(): void
    {
        $done = $this->sequence();
        $this->run_($done);
        $this->now += 5.0;
        self::assertTrue($done->finished());

        $open = $this->sequence();
        $open->next();
        $this->now += 100.0;
        self::assertFalse($open->finished());
    }

    public function testWithoutAMinimumDoneMeansFinished(): void
    {
        $sequence = $this->sequence(0.0);
        $this->run_($sequence);
        self::assertTrue($sequence->finished());
    }

    public function testLinesFromBeforeStayAboveAsDone(): void
    {
        $sequence = $this->sequence(done: ['built.a', 'built.b']);

        self::assertSame(['built.a', 'built.b', 'line.first', 'line.warm', 'line.grass', 'line.last'], $sequence->labels());
        self::assertSame(3, $sequence->currentLine(), 'the first own line runs');
        self::assertEqualsWithDelta(2.0 / 6.0, $sequence->fraction(), 1e-9);
    }

    public function testTheBarOnlyMovesForwardAndEndsFull(): void
    {
        $sequence = $this->sequence(done: ['built.a']);
        $last = -1.0;
        while ($sequence->next() !== null) {
            self::assertGreaterThan($last, $sequence->fraction());
            $last = $sequence->fraction();
        }
        self::assertSame(1.0, $sequence->fraction());
        self::assertSame(count($sequence->labels()) + 1, $sequence->currentLine());
    }

    public function testNoLinesAreFullAtOnce(): void
    {
        $sequence = new LoadingSequence([], clock: fn(): float => $this->now);
        self::assertSame(1.0, $sequence->fraction());
        self::assertTrue($sequence->finished());
        self::assertNull($sequence->next());
        self::assertSame([], $sequence->labels());
    }

    public function testALineCanFollowWhatTheWorkBeforeItFound(): void
    {
        $sequence = $this->sequence();
        $sequence->next();

        self::assertTrue($sequence->setTicks('grass', 5));
        self::assertCount(5, array_filter($this->run_($sequence), static fn(string $s): bool => $s === 'grass'));
    }

    public function testZeroTicksDropALineAndTheBarDoesNotGoBack(): void
    {
        $sequence = $this->sequence();
        $sequence->next();
        $before = $sequence->fraction();

        self::assertTrue($sequence->setTicks('grass', 0));

        self::assertNotContains('line.grass', $sequence->labels());
        self::assertGreaterThanOrEqual($before, $sequence->fraction());
        self::assertNotContains('grass', $this->run_($sequence));
    }

    public function testALineThatHasBegunOrEndedStaysAsItIs(): void
    {
        $sequence = $this->sequence();
        $sequence->next(); // first: done
        $sequence->next(); // warm: begun

        self::assertFalse($sequence->setTicks('first', 9));
        self::assertFalse($sequence->setTicks('warm', 0));
        self::assertFalse($sequence->setTicks('nothing', 2));
        self::assertSame(['warm', 'warm', 'grass', 'grass', 'grass', 'grass', 'last', 'last'], $this->run_($sequence));
    }

    public function testTheLineDueNextCanStillChange(): void
    {
        $sequence = $this->sequence();
        $sequence->next();

        self::assertTrue($sequence->setTicks('warm', 0), 'it is next, not begun');
        self::assertSame('grass', $sequence->next()['step'] ?? null);
    }

    public function testTheLastLineCanBeDroppedAndTheWorkIsThenDone(): void
    {
        $sequence = new LoadingSequence([['step' => 'only', 'label' => 'x', 'ticks' => 2]], clock: fn(): float => $this->now);
        $sequence->setTicks('only', 0);
        self::assertTrue($sequence->workDone());
        self::assertSame(1.0, $sequence->fraction());
    }

    public function testBadInputIsRefused(): void
    {
        foreach ([
            static fn() => new LoadingSequence([['step' => 'x', 'label' => 'x', 'ticks' => 0]]),
            static fn() => new LoadingSequence([], -1.0),
            static fn() => (new LoadingSequence([['step' => 'x', 'label' => 'x', 'ticks' => 1]]))->setTicks('x', -1),
        ] as $i => $bad) {
            try {
                $bad();
                self::fail("case {$i}");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTheRealClockRuns(): void
    {
        $sequence = new LoadingSequence([]);
        self::assertGreaterThanOrEqual(0.0, $sequence->elapsed());
        self::assertLessThan(60.0, $sequence->elapsed());
    }
}
