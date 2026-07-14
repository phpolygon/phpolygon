<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\VioRenderer2D;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers VioRenderer2D::wrapTextLines(), the pure word-wrap shared by
 * drawTextBox() and measureTextBox() so both agree on line breaks. It is
 * exercised through its $lineWidth callable (the chain-aware measurer the live
 * path supplies) without a VioContext.
 *
 * The regression it guards: drawTextBox() used to wrap with a single-primary
 * measurer, so a CJK/Arabic body measured to ~0 in the primary and never
 * wrapped (then drew as .notdef boxes). Wrapping now uses the same chain-aware
 * width the runs are drawn with — the last test locks that in.
 */
final class VioRenderer2DWrapTest extends TestCase
{
    /**
     * @param  callable(string): float  $lineWidth
     * @return list<string>
     */
    private static function wrap(string $text, float $breakWidth, callable $lineWidth): array
    {
        $m = new ReflectionMethod(VioRenderer2D::class, 'wrapTextLines');

        /** @var list<string> $lines */
        $lines = $m->invoke(null, $text, $breakWidth, $lineWidth);

        return $lines;
    }

    /** One unit per character. */
    private static function byChar(): callable
    {
        return static fn (string $s): float => (float) mb_strlen($s);
    }

    public function testGreedyWrapAtBreakWidth(): void
    {
        self::assertSame(['aa bb', 'cc'], self::wrap('aa bb cc', 5.0, self::byChar()));
    }

    public function testHardNewlineAlwaysSplits(): void
    {
        self::assertSame(['a', 'b'], self::wrap("a\nb", 100.0, self::byChar()));
        self::assertSame(['a', 'b'], self::wrap("a\r\nb", 100.0, self::byChar()));
        self::assertSame(['a', 'b'], self::wrap("a\rb", 100.0, self::byChar()));
    }

    public function testEmptyParagraphIsABlankLine(): void
    {
        self::assertSame(['a', '', 'b'], self::wrap("a\n\nb", 100.0, self::byChar()));
    }

    public function testWordWiderThanBreakWidthKeepsItsOwnLine(): void
    {
        self::assertSame(['aaaaaaa', 'bb'], self::wrap('aaaaaaa bb', 3.0, self::byChar()));
    }

    public function testWrapUsesProvidedWidthSoWideGlyphsBreak(): void
    {
        // '#' stands in for a full-width fallback glyph (3 units); everything else
        // is 1. "# #" measures 7 > 4 and must wrap. A primary-only measurer that
        // sized '#' to 0 would keep it on one line — the drawTextBox bug.
        $w = static fn (string $s): float => array_sum(array_map(
            static fn (string $c): float => $c === '#' ? 3.0 : 1.0,
            mb_str_split($s),
        ));

        self::assertSame(['#', '#'], self::wrap('# #', 4.0, $w));
        // Same text under a zero-width '#' measurer stays on one line — proving
        // the break point is driven entirely by the supplied (chain-aware) width.
        $zero = static fn (string $s): float => (float) substr_count($s, ' ');
        self::assertSame(['# #'], self::wrap('# #', 4.0, $zero));
    }
}
