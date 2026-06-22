<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\VioRenderer2D;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression test for two CJK font-chain bugs in
 * VioRenderer2D::drawTextWithChain, both exercised through the pure static
 * helper VioRenderer2D::planFallbackDraws() without a live VioContext.
 *
 * Bug 1 — double-draw (v0.22.0). The fallback loop emitted a character into
 * *every* fallback font whose glyph the primary lacked. A CJK char present in
 * both fallback fonts (e.g. a Han char shared by noto-sans-sc and noto-sans-kr)
 * was therefore drawn once per covering fallback and the two draws overlapped.
 * Fix: each character is claimed by exactly one font — the earliest in the
 * chain that covers it — so it is drawn once.
 *
 * Bug 2 — mixed-string mis-positioning (v0.22.1). The fix for bug 1 padded each
 * fallback run with substituted spaces and drew the whole run at the text
 * origin, relying on the *fallback* font's space advance to skip over
 * primary-covered characters. The fallback (NotoSansSC/KR) space advance
 * differs from the primary (Inter) per-glyph advances, so CJK glyphs in mixed
 * strings such as "Save 接受" landed at the wrong x. Fix: each fallback run is
 * anchored at the *primary* font's measured width of the preceding substring,
 * supplied here via a fake $prefixWidth predicate.
 *
 * planFallbackDraws() takes everything the live path feeds with real metrics —
 * a coverage predicate $covers(int $fontIndex, string $char): bool and a
 * prefix-width predicate $prefixWidth(int $charCount): float — as callables, so
 * fakes fully exercise the claim + positioning logic.
 */
final class VioRenderer2DFallbackClaimTest extends TestCase
{
    /**
     * @param list<string>                 $chars
     * @param callable(int, string): bool  $covers
     * @param callable(int): float         $prefixWidth
     * @return list<array{font: int, text: string, x: float}>
     */
    private static function plan(array $chars, int $chainSize, callable $covers, callable $prefixWidth): array
    {
        $m = new ReflectionMethod(VioRenderer2D::class, 'planFallbackDraws');

        /** @var list<array{font: int, text: string, x: float}> $result */
        $result = $m->invoke(null, $chars, $chainSize, $covers, $prefixWidth);

        return $result;
    }

    /**
     * Count how many of the planned fallback draws render $char.
     *
     * @param list<array{font: int, text: string, x: float}> $draws
     */
    private static function drawCount(array $draws, string $char): int
    {
        $n = 0;
        foreach ($draws as $draw) {
            $n += mb_substr_count($draw['text'], $char);
        }

        return $n;
    }

    /**
     * Width model standing in for a real font: every character is one "unit"
     * wide so the primary prefix width equals the number of preceding chars.
     * This makes the asserted x-positions exact and readable.
     *
     * @param list<string> $chars
     * @return callable(int): float
     */
    private static function unitPrefixWidth(array $chars): callable
    {
        return static fn (int $charCount): float => (float) max(0, $charCount);
    }

    public function testGlyphInFonts2And3IsDrawnExactlyOnceByFont2(): void
    {
        // 3-font chain: 0 = primary (Latin only), 1 = "SC", 2 = "KR".
        // The CJK char 世 is present in BOTH SC and KR — the exact overlap case.
        $char = '世';
        $covers = static function (int $fontIndex, string $ch) use ($char): bool {
            if ($ch !== $char) {
                return false;
            }
            // Primary (0) does NOT cover it; both fallbacks (1, 2) do.
            return $fontIndex === 1 || $fontIndex === 2;
        };

        $draws = self::plan([$char], 3, $covers, self::unitPrefixWidth([$char]));

        self::assertSame(
            1,
            self::drawCount($draws, $char),
            'A glyph present in fonts 2 AND 3 must be drawn exactly once.',
        );
        self::assertCount(1, $draws);
        self::assertSame(1, $draws[0]['font'], 'Font 2 (first covering fallback) must claim the glyph.');
        self::assertSame($char, $draws[0]['text']);
        self::assertSame(0.0, $draws[0]['x'], 'Single leading glyph draws at the origin.');
    }

    public function testGlyphOnlyInFont3IsStillDrawnByFont3(): void
    {
        // Hangul that SC lacks but KR has: primary (0) and SC (1) miss it,
        // only KR (2) covers it.
        $char = '한';
        $covers = static fn (int $fontIndex, string $ch): bool
            => $ch === $char && $fontIndex === 2;

        $draws = self::plan([$char], 3, $covers, self::unitPrefixWidth([$char]));

        self::assertSame(
            1,
            self::drawCount($draws, $char),
            'A glyph only in font 3 must still be drawn — exactly once.',
        );
        self::assertCount(1, $draws);
        self::assertSame(2, $draws[0]['font'], 'Font 3 must draw the glyph it alone covers.');
        self::assertSame($char, $draws[0]['text']);
    }

    public function testPrimaryCoveredGlyphIsNeverReDrawnByAnyFallback(): void
    {
        // 'A' is covered by the primary; no fallback may re-draw it.
        $char = 'A';
        $covers = static fn (int $fontIndex, string $ch): bool
            => $ch === $char; // covered by all fonts, including primary (0)

        $draws = self::plan([$char], 3, $covers, self::unitPrefixWidth([$char]));

        self::assertSame([], $draws, 'A primary-covered glyph emits no fallback draws.');
    }

    public function testGlyphCoveredByNoFontIsNotDrawnByAnyFallback(): void
    {
        // Nothing in the chain covers it -> the primary's full-string draw
        // renders the .notdef box; fallbacks emit nothing.
        $char = '猫';
        $covers = static fn (int $fontIndex, string $ch): bool => false;

        $draws = self::plan([$char], 3, $covers, self::unitPrefixWidth([$char]));

        self::assertSame([], $draws);
    }

    /**
     * The core v0.22.1 regression: a fallback glyph after a run of primary
     * characters must be positioned at the PRIMARY-measured width of that run,
     * not at the origin and not via fallback-space padding.
     *
     * "A世B": A,B in primary; 世 in fallback (SC). With unit widths the primary
     * pen reaches x=1.0 after "A", so 世 must draw at x=1.0.
     */
    public function testMixedStringPositionsFallbackGlyphAtPrimaryPrefixWidth(): void
    {
        $chars = ['A', '世', 'B'];
        $covers = static function (int $fontIndex, string $ch): bool {
            return match ($ch) {
                'A', 'B' => $fontIndex === 0,
                '世' => $fontIndex === 1,
                default => false,
            };
        };

        $draws = self::plan($chars, 3, $covers, self::unitPrefixWidth($chars));

        self::assertCount(1, $draws, 'Only the single fallback glyph is drawn by a fallback.');
        self::assertSame(1, $draws[0]['font']);
        self::assertSame('世', $draws[0]['text']);
        self::assertSame(
            1.0,
            $draws[0]['x'],
            'The fallback glyph must sit at the primary-measured width of "A" (1 unit), '
            . 'not at the origin or a space-padded approximation.',
        );
        // The Latin chars are never re-drawn by a fallback.
        self::assertSame(0, self::drawCount($draws, 'A'));
        self::assertSame(0, self::drawCount($draws, 'B'));
    }

    /**
     * A realistic "Save 接受" style mix: a multi-char primary prefix (incl. a
     * space) followed by a contiguous CJK run that is shared between SC and KR.
     * The run must be drawn once, by SC, anchored at the primary width of the
     * 5-char prefix "Save " (5 units), as a single merged draw.
     */
    public function testContiguousFallbackRunIsMergedAndAnchoredAtPrimaryWidth(): void
    {
        $chars = ['S', 'a', 'v', 'e', ' ', '接', '受'];
        $covers = static function (int $fontIndex, string $ch): bool {
            // CJK shared by SC (1) and KR (2); everything else primary-only.
            if ($ch === '接' || $ch === '受') {
                return $fontIndex === 1 || $fontIndex === 2;
            }
            return $fontIndex === 0;
        };

        $draws = self::plan($chars, 3, $covers, self::unitPrefixWidth($chars));

        self::assertCount(1, $draws, 'The two CJK chars merge into a single fallback run/draw.');
        self::assertSame(1, $draws[0]['font'], 'SC (earliest covering fallback) claims the run.');
        self::assertSame('接受', $draws[0]['text']);
        self::assertSame(
            5.0,
            $draws[0]['x'],
            'The CJK run starts at the primary width of "Save " (5 units).',
        );
        // Each CJK glyph drawn exactly once — no KR overprint.
        self::assertSame(1, self::drawCount($draws, '接'));
        self::assertSame(1, self::drawCount($draws, '受'));
    }

    public function testMixedStringWithTwoFallbackFontsClaimsAndPositionsEach(): void
    {
        // "A世한B" — primary has A/B, SC has 世, only KR has 한. The two CJK
        // chars are claimed by DIFFERENT fallbacks, so they are separate draws,
        // each anchored at its own primary prefix width.
        $chars = ['A', '世', '한', 'B'];
        $covers = static function (int $fontIndex, string $ch): bool {
            return match ($ch) {
                'A', 'B' => $fontIndex === 0,
                '世' => $fontIndex === 1 || $fontIndex === 2, // shared SC+KR
                '한' => $fontIndex === 2,
                default => false,
            };
        };

        $draws = self::plan($chars, 3, $covers, self::unitPrefixWidth($chars));

        self::assertCount(2, $draws);

        // 世 -> SC (1) at width of "A" (1 unit)
        self::assertSame(1, $draws[0]['font']);
        self::assertSame('世', $draws[0]['text']);
        self::assertSame(1.0, $draws[0]['x']);

        // 한 -> KR (2) at width of "A世" (2 units)
        self::assertSame(2, $draws[1]['font']);
        self::assertSame('한', $draws[1]['text']);
        self::assertSame(2.0, $draws[1]['x']);

        self::assertSame(1, self::drawCount($draws, '世'));
        self::assertSame(1, self::drawCount($draws, '한'));
        self::assertSame(0, self::drawCount($draws, 'A'));
    }

    public function testTwoFontChainStillPositionsFallbackGlyph(): void
    {
        // Backwards-compat: with a single fallback, a mixed string still draws
        // the missing glyph through the fallback at the primary prefix width.
        $chars = ['A', '世'];
        $covers = static function (int $fontIndex, string $ch): bool {
            return match ($ch) {
                'A' => $fontIndex === 0,
                '世' => $fontIndex === 1,
                default => false,
            };
        };

        $draws = self::plan($chars, 2, $covers, self::unitPrefixWidth($chars));

        self::assertSame(
            [['font' => 1, 'text' => '世', 'x' => 1.0]],
            $draws,
        );
    }
}
