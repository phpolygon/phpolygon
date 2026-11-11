<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\ShaderWarmProgress;
use PHPolygon\Rendering\VioRenderer3D;

/**
 * The progress contract of the shader warm-up, without a GPU: the total is the
 * plan's, every planned program is reported once with done running 1..total, and
 * neither a skipped, a failed, a retried nor an unplanned program moves that.
 * The compile sequence itself needs a context: VioShaderWarmProgressTest.
 */
final class ShaderWarmProgressTest extends TestCase
{
    /** @var list<array{0: int, 1: int, 2: string}> */
    private array $calls = [];

    /** @param list<string> $programs */
    private function progress(array $programs): ShaderWarmProgress
    {
        $this->calls = [];
        return new ShaderWarmProgress($programs, function (int $done, int $total, string $program): void {
            $this->calls[] = [$done, $total, $program];
        });
    }

    private function assertGapless(int $total): void
    {
        self::assertCount($total, $this->calls, 'one call per planned program');
        foreach ($this->calls as $i => [$done, $reportedTotal]) {
            self::assertSame($i + 1, $done, 'done runs 1..total without a gap');
            self::assertSame($total, $reportedTotal, 'the total never changes');
        }
    }

    public function testEveryProgramIsReportedOnceInOrder(): void
    {
        $progress = $this->progress(['a', 'b', 'c']);
        self::assertSame(3, $progress->total());
        self::assertSame([], $this->calls, 'nothing is reported before the first compile');

        $progress->compiled('a');
        self::assertSame([[1, 3, 'a']], $this->calls);
        $progress->compiled('b');
        $progress->compiled('c');
        $progress->finish();

        $this->assertGapless(3);
        self::assertSame(['a', 'b', 'c'], array_column($this->calls, 2));
        self::assertSame(3, $progress->done());
    }

    public function testASkippedProgramIsReportedWithTheNextOne(): void
    {
        $progress = $this->progress(['a', 'optional', 'c']);
        $progress->compiled('a');
        $progress->compiled('c');

        self::assertSame([[1, 3, 'a'], [2, 3, 'optional'], [3, 3, 'c']], $this->calls);
        $progress->finish();
        $this->assertGapless(3);
    }

    public function testFinishReportsWhatFailedAtTheEnd(): void
    {
        $progress = $this->progress(['a', 'b', 'mrt_1', 'mrt_2']);
        $progress->compiled('a');
        $progress->compiled('b');
        self::assertCount(2, $this->calls);

        $progress->finish();
        $this->assertGapless(4);
        self::assertSame([4, 4, 'mrt_2'], $this->calls[3], 'the last call has done === total');
    }

    public function testARetryCountsOnce(): void
    {
        $progress = $this->progress(['default', 'unlit']);
        $progress->compiled('default');
        $progress->compiled('default');
        $progress->compiled('unlit');
        $progress->finish();
        $progress->finish();

        $this->assertGapless(2);
    }

    public function testAnUnplannedProgramIsNotReported(): void
    {
        $progress = $this->progress(['a', 'b']);
        $progress->compiled('a');
        $progress->compiled('default#tess');
        $progress->compiled('b');
        $progress->compiled('late');
        $progress->finish();

        $this->assertGapless(2);
    }

    public function testAProgramCompiledLateDoesNotGoBackwards(): void
    {
        $progress = $this->progress(['a', 'b', 'c']);
        $progress->compiled('c');
        $progress->compiled('a');
        $progress->finish();

        $this->assertGapless(3);
    }

    public function testARepeatedPlanEntryCountsOnce(): void
    {
        $progress = $this->progress(['a', 'b', 'a']);
        self::assertSame(2, $progress->total());
        $progress->finish();
        $this->assertGapless(2);
    }

    public function testAThrowingCallbackIsKeptAndNotCalledAgain(): void
    {
        $calls = 0;
        $progress = new ShaderWarmProgress(['a', 'b', 'c'], function () use (&$calls): void {
            $calls++;
            throw new \LogicException('draw failed');
        });
        $progress->compiled('a');
        $progress->compiled('b');
        $progress->finish();

        self::assertSame(1, $calls);
        self::assertInstanceOf(\LogicException::class, $progress->failure());
        self::assertSame(3, $progress->done(), 'the count still reaches the total');
    }

    public function testLabel(): void
    {
        self::assertSame('Compiling shaders 17 / 52 (33 %)', ShaderWarmProgress::label('Compiling shaders', 17, 52));
        self::assertSame('Compiling shaders 52 / 52 (100 %)', ShaderWarmProgress::label('Compiling shaders', 52, 52));
        self::assertSame('Compiling shaders 1 / 52 (2 %)', ShaderWarmProgress::label('Compiling shaders', 1, 52));
    }

    public function testPercentReachesAHundredOnlyAtTheEnd(): void
    {
        self::assertSame(99, ShaderWarmProgress::percent(999, 1000), 'rounding never shows 100 % early');
        self::assertSame(100, ShaderWarmProgress::percent(1000, 1000));
        self::assertSame(0, ShaderWarmProgress::percent(0, 52));
        self::assertSame(100, ShaderWarmProgress::percent(0, 0));
        $last = -1;
        for ($done = 0; $done <= 52; $done++) {
            $percent = ShaderWarmProgress::percent($done, 52);
            self::assertGreaterThanOrEqual($last, $percent);
            $last = $percent;
        }
    }

    public function testFractionMapsOntoAPartOfTheBar(): void
    {
        self::assertSame(0.4, ShaderWarmProgress::fraction(0, 52, 0.4, 0.5));
        self::assertSame(0.5, ShaderWarmProgress::fraction(52, 52, 0.4, 0.5));
        self::assertEqualsWithDelta(0.45, ShaderWarmProgress::fraction(26, 52, 0.4, 0.5), 1e-9);
        self::assertSame(0.5, ShaderWarmProgress::fraction(60, 52, 0.4, 0.5), 'never past the end of its part');
        $last = 0.0;
        for ($done = 0; $done <= 52; $done++) {
            $fraction = ShaderWarmProgress::fraction($done, 52, 0.4, 0.5);
            self::assertGreaterThanOrEqual($last, $fraction, 'the bar only moves forward');
            $last = $fraction;
        }
    }

    public function testWarmRendererDoesNotCallBack(): void
    {
        // No context: a renderer whose shaders are warm must return before it
        // touches one, and must not report anything.
        $class = new \ReflectionClass(VioRenderer3D::class);
        $renderer = $class->newInstanceWithoutConstructor();
        $class->getProperty('shadersWarmed')->setValue($renderer, true);

        $calls = 0;
        $renderer->warmShaders(function () use (&$calls): void {
            $calls++;
        });
        $renderer->warmShaders();

        self::assertSame(0, $calls);
    }
}
