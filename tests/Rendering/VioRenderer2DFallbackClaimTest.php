<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\VioRenderer2D;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression test for the CJK font-chain layout in
 * VioRenderer2D::drawTextWithChain, exercised through the pure static helper
 * VioRenderer2D::planTextRuns() without a live VioContext.
 *
 * Bug 1 — double-draw (v0.22.0). The fallback loop emitted a character into
 * *every* fallback font whose glyph the primary lacked, so a CJK char shared by
 * two fallbacks (e.g. a Han char in both noto-sans-sc and noto-sans-kr) was
 * overprinted. Fix: each character is claimed by exactly one font — the earliest
 * in the chain that covers it.
 *
 * Bug 2 — mixed-string mis-positioning (v0.22.1). Fallback runs were anchored at
 * the *primary* font's measured width of the preceding substring. That holds
 * only while the prefix is pure primary text: the primary (Inter) cannot measure
 * a CJK glyph, so as soon as the prefix contained one its width collapsed and
 * any text *after* the CJK overprinted it. "Save 接受" looked fine only because
 * the CJK sat at the end with nothing following.
 *
 * Bug 3 — trailing text over full-width CJK (this fix). The primary still drew
 * the whole string, advancing its pen by its narrow .notdef width over each CJK
 * glyph — far less than the full-width glyph the fallback actually renders — so
 * following characters landed on top of the CJK. planTextRuns() now draws every
 * run (primary runs included) with its claiming font and advances the pen by
 * that font's own per-glyph width, so a full-width CJK glyph reserves its true
 * width and nothing overprints it.
 *
 * planTextRuns() takes a per-glyph width predicate
 * $charWidth(int $fontIndex, string $char): float — the only metric the live
 * path needs — as a callable, so fakes fully exercise claim + positioning.
 */
final class VioRenderer2DFallbackClaimTest extends TestCase
{
    /**
     * @param list<string>                 $chars
     * @param callable(int, string): float $charWidth
     * @return array{runs: list<array{font: int, text: string, x: float}>, width: float}
     */
    private static function plan(array $chars, int $chainSize, callable $charWidth, float $originX = 0.0): array
    {
        $m = new ReflectionMethod(VioRenderer2D::class, 'planTextRuns');

        // planTextRuns() now claims by real glyph coverage rather than advance
        // width. In this fake, a font covers a char exactly when the spec gives
        // it a positive width — so derive $charCovers from the same width map,
        // preserving every existing claim/positioning assertion.
        $charCovers = static fn (int $fontIndex, string $ch): bool => $charWidth($fontIndex, $ch) > 0.0;

        /** @var array{runs: list<array{font: int, text: string, x: float}>, width: float} $result */
        $result = $m->invoke(null, $chars, $chainSize, $charWidth, $charCovers, $originX);

        return $result;
    }

    /**
     * Build a $charWidth(int $fontIndex, string $ch): float from a spec map
     * `$spec[$char] = [$fontIndex => $width, ...]`. Any (font, char) not listed
     * has width 0.0 — i.e. that font has no glyph for the char. Latin chars are
     * 1.0 wide in the primary; CJK glyphs are 2.0 wide in their fallback to model
     * full-width advance against half-width Latin.
     *
     * @param array<string, array<int, float>> $spec
     * @return callable(int, string): float
     */
    private static function widths(array $spec): callable
    {
        return static fn (int $fontIndex, string $ch): float => (float) ($spec[$ch][$fontIndex] ?? 0.0);
    }

    /**
     * Count how many of the planned runs render $char.
     *
     * @param list<array{font: int, text: string, x: float}> $runs
     */
    private static function drawCount(array $runs, string $char): int
    {
        $n = 0;
        foreach ($runs as $run) {
            $n += mb_substr_count($run['text'], $char);
        }

        return $n;
    }

    public function testGlyphInFonts2And3IsDrawnExactlyOnceByFont2(): void
    {
        // 3-font chain: 0 = primary (Latin only), 1 = "SC", 2 = "KR".
        // 世 is present in BOTH SC and KR — the double-draw overlap case.
        $spec = ['世' => [1 => 2.0, 2 => 2.0]];

        $plan = self::plan(['世'], 3, self::widths($spec));
        $runs = $plan['runs'];

        self::assertSame(1, self::drawCount($runs, '世'), 'A glyph in fonts 2 AND 3 must be drawn exactly once.');
        self::assertCount(1, $runs);
        self::assertSame(1, $runs[0]['font'], 'Font 2 (first covering fallback) must claim the glyph.');
        self::assertSame('世', $runs[0]['text']);
        self::assertSame(0.0, $runs[0]['x'], 'Single leading glyph draws at the origin.');
        self::assertSame(2.0, $plan['width'], 'Width is the full-width glyph advance.');
    }

    public function testGlyphOnlyInFont3IsStillDrawnByFont3(): void
    {
        // Hangul that SC lacks but KR has: only KR (2) covers it.
        $spec = ['한' => [2 => 2.0]];

        $runs = self::plan(['한'], 3, self::widths($spec))['runs'];

        self::assertSame(1, self::drawCount($runs, '한'), 'A glyph only in font 3 must still be drawn — once.');
        self::assertCount(1, $runs);
        self::assertSame(2, $runs[0]['font'], 'Font 3 must draw the glyph it alone covers.');
        self::assertSame('한', $runs[0]['text']);
    }

    public function testPrimaryCoveredGlyphIsDrawnByThePrimaryRun(): void
    {
        // 'A' is covered by the primary; it must be drawn once, by the primary,
        // and never by a fallback.
        $spec = ['A' => [0 => 1.0, 1 => 1.0, 2 => 1.0]];

        $runs = self::plan(['A'], 3, self::widths($spec))['runs'];

        self::assertSame([['font' => 0, 'text' => 'A', 'x' => 0.0]], $runs);
    }

    public function testGlyphCoveredByNoFontFallsBackToThePrimaryNotdef(): void
    {
        // Nothing in the chain covers it -> the primary draws the .notdef box,
        // in place, so it can never overprint a neighbour.
        $runs = self::plan(['猫'], 3, self::widths([]))['runs'];

        self::assertSame([['font' => 0, 'text' => '猫', 'x' => 0.0]], $runs);
    }

    /**
     * A fallback glyph between primary characters sits at the pen position the
     * primary text reached. "A世B": A(primary,1) 世(SC,2) B(primary,1).
     */
    public function testFallbackGlyphBetweenPrimaryRunsIsPositionedAtThePen(): void
    {
        $spec = [
            'A'  => [0 => 1.0],
            'B'  => [0 => 1.0],
            '世' => [1 => 2.0, 2 => 2.0],
        ];

        $plan = self::plan(['A', '世', 'B'], 3, self::widths($spec));
        $runs = $plan['runs'];

        self::assertSame([
            ['font' => 0, 'text' => 'A', 'x' => 0.0],
            ['font' => 1, 'text' => '世', 'x' => 1.0],
            ['font' => 0, 'text' => 'B', 'x' => 3.0], // past the full-width 世 (1.0 + 2.0)
        ], $runs);
        self::assertSame(4.0, $plan['width']);
    }

    /**
     * THE bug that survived v0.22.1: a CJK glyph FOLLOWED by more text. The
     * trailing text must start past the full-width CJK glyph, not on top of it.
     *
     * "世X": 世 is full-width (2.0) in the fallback; X (primary, 1.0) must draw at
     * x=2.0. The old path measured the "世" prefix with the primary (width ~0) and
     * let the primary's whole-string pass place X at x≈0 — directly over 世.
     */
    public function testCjkFollowedByPrimaryTextDoesNotOverprint(): void
    {
        $spec = [
            '世' => [1 => 2.0],
            'X'  => [0 => 1.0],
        ];

        $plan = self::plan(['世', 'X'], 3, self::widths($spec));

        self::assertSame([
            ['font' => 1, 'text' => '世', 'x' => 0.0],
            ['font' => 0, 'text' => 'X', 'x' => 2.0],
        ], $plan['runs']);
        self::assertSame(3.0, $plan['width']);
    }

    /**
     * The hotkey case from the bug report: a CJK label followed by a bracketed
     * Latin hotkey, e.g. "暂停 [P]". Every Latin character after the two
     * full-width CJK glyphs must be offset by their true width.
     */
    public function testCjkLabelFollowedByLatinHotkeyIsOffsetByFullWidth(): void
    {
        $spec = [
            '暂' => [1 => 2.0],
            '停' => [1 => 2.0],
            ' '  => [0 => 1.0],
            '['  => [0 => 1.0],
            'P'  => [0 => 1.0],
            ']'  => [0 => 1.0],
        ];

        $plan = self::plan(['暂', '停', ' ', '[', 'P', ']'], 3, self::widths($spec));

        self::assertSame([
            ['font' => 1, 'text' => '暂停', 'x' => 0.0],
            ['font' => 0, 'text' => ' [P]', 'x' => 4.0], // 2 CJK * 2.0 each
        ], $plan['runs']);
        self::assertSame(8.0, $plan['width']); // 4 (CJK) + 4 (" [P]")
    }

    /**
     * "Save 接受": a primary prefix (incl. a space) then a contiguous CJK run
     * shared by SC and KR. The run is drawn once, by SC, after the 5-unit prefix.
     */
    public function testContiguousFallbackRunIsMergedAndAnchoredAfterPrimaryPrefix(): void
    {
        $spec = [
            'S' => [0 => 1.0], 'a' => [0 => 1.0], 'v' => [0 => 1.0], 'e' => [0 => 1.0], ' ' => [0 => 1.0],
            '接' => [1 => 2.0, 2 => 2.0],
            '受' => [1 => 2.0, 2 => 2.0],
        ];

        $plan = self::plan(['S', 'a', 'v', 'e', ' ', '接', '受'], 3, self::widths($spec));
        $runs = $plan['runs'];

        self::assertSame([
            ['font' => 0, 'text' => 'Save ', 'x' => 0.0],
            ['font' => 1, 'text' => '接受', 'x' => 5.0],
        ], $runs);
        self::assertSame(1, self::drawCount($runs, '接'));
        self::assertSame(1, self::drawCount($runs, '受'));
        self::assertSame(9.0, $plan['width']); // 5 + 2*2.0
    }

    public function testMixedStringWithTwoFallbackFontsClaimsAndPositionsEach(): void
    {
        // "A世한B": SC owns 世 (shared with KR), only KR owns 한.
        $spec = [
            'A'  => [0 => 1.0],
            'B'  => [0 => 1.0],
            '世' => [1 => 2.0, 2 => 2.0],
            '한' => [2 => 2.0],
        ];

        $runs = self::plan(['A', '世', '한', 'B'], 3, self::widths($spec))['runs'];

        self::assertSame([
            ['font' => 0, 'text' => 'A',  'x' => 0.0],
            ['font' => 1, 'text' => '世', 'x' => 1.0],
            ['font' => 2, 'text' => '한', 'x' => 3.0], // past 世 (1.0 + 2.0)
            ['font' => 0, 'text' => 'B',  'x' => 5.0], // past 한 (3.0 + 2.0)
        ], $runs);
        self::assertSame(1, self::drawCount($runs, '世'));
        self::assertSame(1, self::drawCount($runs, '한'));
    }

    public function testTwoFontChainStillPositionsFallbackGlyph(): void
    {
        // Backwards-compat: with a single fallback, a mixed string still draws
        // the missing glyph through the fallback at the right pen position.
        $spec = [
            'A'  => [0 => 1.0],
            '世' => [1 => 2.0],
        ];

        $runs = self::plan(['A', '世'], 2, self::widths($spec))['runs'];

        self::assertSame([
            ['font' => 0, 'text' => 'A',  'x' => 0.0],
            ['font' => 1, 'text' => '世', 'x' => 1.0],
        ], $runs);
    }

    public function testOriginOffsetShiftsEveryRun(): void
    {
        // A non-zero text origin must offset every run's x by the same amount.
        $spec = [
            '世' => [1 => 2.0],
            'X'  => [0 => 1.0],
        ];

        $runs = self::plan(['世', 'X'], 3, self::widths($spec), 10.0)['runs'];

        self::assertSame([
            ['font' => 1, 'text' => '世', 'x' => 10.0],
            ['font' => 0, 'text' => 'X', 'x' => 12.0],
        ], $runs);
    }
}
