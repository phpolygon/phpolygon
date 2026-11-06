<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\VioRenderer2D;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A right-to-left string split across a fallback chain is placed in visual order
 * (VioRenderer2D::planBidiRuns). Up to v0.97.1 the runs went left to right in
 * logical order: an Arabic sentence came out word by word backwards, its closing
 * "!" on the right.
 *
 * Fake chain: font 0 covers ASCII and the en dash, font 1 the Arabic block. Every
 * character is one unit wide, so a run's x is the number of characters before it.
 */
final class VioRenderer2DBidiRunsTest extends TestCase
{
    /** @return array{runs: list<array{font: int, text: string, x: float}>, width: float} */
    private static function plan(string $text): array
    {
        $covers = static function (int $font, string $ch): bool {
            $cp = mb_ord($ch);
            $latin = $cp < 0x80 || $ch === '–';
            $arabic = $cp >= 0x0600 && $cp <= 0x06FF;

            return $font === 0 ? $latin : $arabic;
        };
        $width = static fn (int $font, string $s): float => (float) mb_strlen($s);
        $m = new ReflectionMethod(VioRenderer2D::class, 'planBidiRuns');
        /** @var array{runs: list<array{font: int, text: string, x: float}>, width: float} $plan */
        $plan = $m->invoke(null, mb_str_split($text), 2, $covers, $width, 0.0);

        return $plan;
    }

    public function testAnArabicSentenceIsPlacedRightToLeft(): void
    {
        $plan = self::plan('ليس تمامًا – حاول مجددًا!');

        // Left to right on screen: "!", the last word, ... the first word on the right.
        // Arabic runs keep their logical order: php-vio shapes and reverses each one.
        self::assertSame([
            ['font' => 0, 'text' => '!', 'x' => 0.0],
            ['font' => 1, 'text' => 'مجددًا', 'x' => 1.0],
            ['font' => 0, 'text' => ' ', 'x' => 7.0],
            ['font' => 1, 'text' => 'حاول', 'x' => 8.0],
            ['font' => 0, 'text' => ' – ', 'x' => 12.0],
            ['font' => 1, 'text' => 'تمامًا', 'x' => 15.0],
            ['font' => 0, 'text' => ' ', 'x' => 21.0],
            ['font' => 1, 'text' => 'ليس', 'x' => 22.0],
        ], $plan['runs']);
        self::assertSame(25.0, $plan['width']);
    }

    public function testCodeAndNumbersInsideArabicStayLeftToRight(): void
    {
        $plan = self::plan('تلميح (0/3)');
        self::assertSame([
            ['font' => 0, 'text' => '(', 'x' => 0.0],   // ")" at the end of the sentence, mirrored
            ['font' => 0, 'text' => '0/3', 'x' => 1.0],
            ['font' => 0, 'text' => ') ', 'x' => 4.0],  // " (" mirrored, reversed
            ['font' => 1, 'text' => 'تلميح', 'x' => 6.0],
        ], $plan['runs']);

        $plan = self::plan('يتم تعيين $language');
        self::assertSame('$language', $plan['runs'][0]['text']);
        self::assertSame('تعيين', $plan['runs'][2]['text']);
        self::assertSame('يتم', $plan['runs'][4]['text']);
    }

    public function testRunsAreMeasuredWhole(): void
    {
        // Joined letters are narrower than their isolated forms: the run width, not
        // a sum per character, sets where the next run starts.
        $covers = static fn (int $font, string $ch): bool => $font === 1 ? mb_ord($ch) >= 0x0600 : mb_ord($ch) < 0x80;
        $width = static fn (int $font, string $s): float => $font === 1 ? 10.0 : (float) mb_strlen($s);
        $m = new ReflectionMethod(VioRenderer2D::class, 'planBidiRuns');
        $plan = $m->invoke(null, mb_str_split('حاول مجددًا'), 2, $covers, $width, 5.0);
        self::assertSame([5.0, 15.0, 16.0], array_column($plan['runs'], 'x'));
        self::assertSame(21.0, $plan['width']);
    }
}
