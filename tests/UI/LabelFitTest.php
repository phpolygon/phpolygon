<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPolygon\Rendering\TextWrap;
use PHPolygon\UI\LabelFit;
use PHPUnit\Framework\TestCase;

/**
 * One shared font size for a row of labels, wrapping before shrinking,
 * shrinking before splitting a word, and never truncating.
 * Measuring is 1 unit per character per font-size point / 10, so a label of
 * N characters at size S is N * S / 10 wide.
 */
final class LabelFitTest extends TestCase
{
    /** @return callable(string, float): float */
    private static function measure(): callable
    {
        return static fn(string $t, float $size): float => mb_strlen($t) * $size / 10.0;
    }

    public function testShortLabelsKeepTheSizeAndOneLine(): void
    {
        $fit = LabelFit::fit(['Yes', 'No'], 100.0, 20.0, 14.0, 2, self::measure());

        self::assertSame(20.0, $fit->fontSize);
        self::assertSame([['Yes'], ['No']], $fit->lines);
        self::assertSame(1, $fit->lineCount());
    }

    public function testALongLabelWrapsBeforeTheFontShrinks(): void
    {
        // "Skip everything" = 15 chars = 30 wide at 20: too wide for 20, fits on two lines.
        $fit = LabelFit::fit(['Next', 'Skip everything'], 20.0, 20.0, 14.0, 2, self::measure());

        self::assertSame(20.0, $fit->fontSize);
        self::assertSame(['Skip', 'everything'], $fit->label(1));
        self::assertSame(2, $fit->lineCount());
    }

    public function testTheWholeRowShrinksTogetherBeforeAWordIsSplit(): void
    {
        // "everything" (10 chars) is 20 wide at 20 but the box is 16: shrink to 16.
        $fit = LabelFit::fit(['Go', 'Skip everything'], 16.0, 20.0, 12.0, 2, self::measure());

        self::assertSame(16.0, $fit->fontSize, 'one size for every label of the row');
        self::assertSame(['Go'], $fit->label(0));
        self::assertSame(['Skip', 'everything'], $fit->label(1));
    }

    public function testNothingIsTruncatedWhenEvenTheMinimumIsTooBig(): void
    {
        $fit = LabelFit::fit(['Unbreakableword'], 10.0, 20.0, 10.0, 2, self::measure());

        self::assertSame(10.0, $fit->fontSize);
        self::assertSame('Unbreakableword', implode('', $fit->label(0)), 'every character is still there');
        foreach ($fit->label(0) as $line) {
            self::assertLessThanOrEqual(10.0, mb_strlen($line) * 1.0);
        }
    }

    public function testCjkBreaksBetweenCharacters(): void
    {
        // 5 characters per line; the small "ッ" may not start a line and stays behind "キ".
        $fit = LabelFit::fit(['全部スキッププする'], 10.0, 20.0, 20.0, 2, self::measure());

        self::assertSame(['全部スキッ', 'ププする'], $fit->label(0));
    }

    public function testLinesWithinSplitsAnOverlongWordInsteadOfOverflowing(): void
    {
        $width = static fn(string $s): float => (float) mb_strlen($s);

        self::assertSame(['ab', 'abcdef'], TextWrap::lines('ab abcdef', 4.0, $width), 'plain wrap lets the word overflow');
        self::assertSame(['ab', 'abcd', 'ef'], TextWrap::linesWithin('ab abcdef', 4.0, $width));
    }
}
