<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\VioRenderer2D;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression test for the v0.17.2 perf fix: the private
 * VioRenderer2D::textNeedsFallback() gate used to call
 * preg_match('/[\x{0500}-\x{10FFFF}]/u', $text) on every drawText, which
 * dominated per-frame text cost on HUD-heavy panels whenever CJK fallbacks
 * are registered (the gate then runs unconditionally).
 *
 * The replacement is a UTF-8 byte-scan for any byte >= 0xD4 — equivalent to
 * "any codepoint >= U+0500" — and these tests pin the boundaries so a future
 * "optimisation" can't silently shift the gate.
 */
final class VioRenderer2DTextNeedsFallbackTest extends TestCase
{
    private static function gate(string $text): bool
    {
        $m = new ReflectionMethod(VioRenderer2D::class, 'textNeedsFallback');
        return (bool) $m->invoke(null, $text);
    }

    public function testEmptyStringDoesNotNeedFallback(): void
    {
        self::assertFalse(self::gate(''));
    }

    public function testPlainAsciiDoesNotNeedFallback(): void
    {
        self::assertFalse(self::gate('Hello World 1234 !@#'));
    }

    public function testLatin1SupplementDoesNotNeedFallback(): void
    {
        // German umlauts, French accents — all U+00xx, primary font covers them.
        self::assertFalse(self::gate('Grüße aus München, ça va?'));
    }

    public function testLatinExtendedDoesNotNeedFallback(): void
    {
        // Polish, Czech, Romanian — U+0100-U+024F.
        self::assertFalse(self::gate('Łódź, Příliš žluťoučký kůň, Țară'));
    }

    public function testGreekDoesNotNeedFallback(): void
    {
        // U+0370-U+03FF — Inter covers Greek.
        self::assertFalse(self::gate('Καλημέρα κόσμε'));
    }

    public function testCyrillicBasicDoesNotNeedFallback(): void
    {
        // U+0400-U+04FF — last block before the gate trips.
        self::assertFalse(self::gate('Привет мир'));
    }

    public function testJustBelowGateBoundaryDoesNotTrip(): void
    {
        // U+04FF — the very last codepoint that should NOT need fallback.
        self::assertFalse(self::gate("\u{04FF}"));
    }

    public function testGateBoundaryTrips(): void
    {
        // U+0500 — first codepoint that requires the fallback chain.
        self::assertTrue(self::gate("\u{0500}"));
    }

    public function testChineseTripsFallback(): void
    {
        self::assertTrue(self::gate('你好世界'));
    }

    public function testJapaneseTripsFallback(): void
    {
        self::assertTrue(self::gate('こんにちは'));
    }

    public function testKoreanTripsFallback(): void
    {
        self::assertTrue(self::gate('안녕하세요'));
    }

    public function testEmojiTripsFallback(): void
    {
        // U+1F600 — outside BMP, 4-byte UTF-8.
        self::assertTrue(self::gate('Score: 100 😀'));
    }

    public function testMixedLatinAndCjkTripsFallback(): void
    {
        self::assertTrue(self::gate('Hello 世界'));
    }

    public function testContinuationBytesAloneDoNotFalsePositive(): void
    {
        // A 2-byte sequence for U+00FF: 0xC3 0xBF — leading byte 0xC3 < 0xD4,
        // continuation byte 0xBF < 0xD4. Should NOT trip.
        self::assertFalse(self::gate("\u{00FF}"));
    }
}
