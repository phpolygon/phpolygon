<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering\Text;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Text\FontChain;
use PHPolygon\Rendering\Text\TextFit;
use PHPolygon\Rendering\Text\TextRasterizer;

/**
 * Fit logic runs on a fake layout source (monospace glyphs of 0.5 em, ink from
 * -0.7 to +0.2 em), so it needs no extension; the scripts run on real php-vio.
 */
final class TextRasterizerTest extends TestCase
{
    private static function fake(): TextRasterizer
    {
        return new TextRasterizer(static function (array $faces, string $text, float $px, ?array $options): array {
            $width = (int) ceil(mb_strlen($text) * 0.5 * $px);
            $height = (int) ceil(0.9 * $px);
            $result = ['width' => $width, 'height' => $height, 'origin_x' => 0, 'baseline' => (int) ceil(0.7 * $px), 'advance' => mb_strlen($text) * 0.5 * $px];
            if (($options['measure'] ?? false) !== true) {
                $result['data'] = str_repeat("\xFF", $width * $height);
            }
            return $result;
        });
    }

    public function testShortTextIsLimitedByTheHeightAndTakesOneLine(): void
    {
        $fit = self::fake()->fit([], 'Exit', 512.0, 64.0, 1.2, 2, 0.0, 10.0);

        self::assertSame(['Exit'], $fit->lines);
        self::assertGreaterThan(60.0, $fit->fontPx);
        self::assertLessThanOrEqual(64.0 / 0.9, $fit->fontPx);
    }

    public function testMaxSizeCaps(): void
    {
        self::assertSame(20.0, self::fake()->fit([], 'Exit', 512.0, 64.0, 1.2, 2, 20.0, 10.0)->fontPx);
    }

    public function testLongTextWrapsWhenThatWinsSizeAndStaysInTheBox(): void
    {
        $box = [200.0, 80.0];
        $text = 'Twenty chars of text longer words';
        $fit = self::fake()->fit([], $text, $box[0], $box[1], 1.2, 2, 0.0, 10.0);

        self::assertCount(2, $fit->lines);
        foreach ($fit->lines as $line) {
            self::assertLessThanOrEqual($box[0], mb_strlen($line) * 0.5 * $fit->fontPx);
        }
        // Ink block: first ascent, one pitch, last descent.
        self::assertLessThanOrEqual($box[1], (0.9 + 1.2) * $fit->fontPx + 0.5);
        self::assertSame($text, implode(' ', $fit->lines), 'nothing is cut');
        self::assertFalse($fit->belowMinimum);
    }

    public function testKeepsOneLineWhenASecondWinsNearlyNothing(): void
    {
        // Two lines would allow 1 % more size in this box, not worth a second line.
        $fit = self::fake()->fit([], 'aaaaaaaa bbbbbbbb', 1700.0, 215.0, 1.2, 2, 0.0, 10.0);

        self::assertCount(1, $fit->lines);
    }

    public function testNothingIsCutEvenBelowTheMinimum(): void
    {
        $text = 'Averyveryverylongsingleword';
        $fit = self::fake()->fit([], $text, 100.0, 40.0, 1.2, 2, 0.0, 10.0);

        self::assertTrue($fit->belowMinimum);
        self::assertSame([$text], $fit->lines, 'a word is shrunk, never split');
        self::assertLessThanOrEqual(100.0, mb_strlen($text) * 0.5 * $fit->fontPx);
    }

    public function testCjkWrapsBetweenCharactersAndKeepsPunctuationAttached(): void
    {
        $text = '東西南北東西南北。東西南北東西南北';
        $fit = self::fake()->fit([], $text, 300.0, 120.0, 1.2, 2, 0.0, 10.0);

        self::assertCount(2, $fit->lines);
        self::assertSame($text, implode('', $fit->lines));
        self::assertStringStartsNotWith('。', $fit->lines[1]);
    }

    public function testEmptyTextIsPlainBackground(): void
    {
        $r = self::fake();
        $rgba = $r->render([], new TextFit([], 10.0), 8, 4, Color::white(), new Color(0.2, 0.4, 0.6), 1.2);

        self::assertSame(str_repeat(pack('C4', 51, 102, 153, 255), 8 * 4), $rgba);
        self::assertSame([], $r->fit([], '  ', 100.0, 20.0, 1.2, 2, 0.0, 5.0)->lines);
    }

    public function testRenderCentresTheBlockAndWritesTheColoursUnchanged(): void
    {
        $r = self::fake();
        $w = 64;
        $h = 32;
        $rgba = $r->render([], new TextFit(['ab'], 10.0), $w, $h, new Color(1.0, 0.0, 0.0), new Color(0.0, 0.0, 1.0), 1.2);

        self::assertSame($w * $h * 4, strlen($rgba));
        $inkRows = [];
        $inkCols = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $o = ($y * $w + $x) * 4;
                if (ord($rgba[$o]) === 255) {
                    $inkRows[$y] = true;
                    $inkCols[$x] = true;
                    self::assertSame(0, ord($rgba[$o + 2]));
                    self::assertSame(255, ord($rgba[$o + 3]));
                } else {
                    self::assertSame(255, ord($rgba[$o + 2]));
                }
            }
        }
        // Ink 10 x 9 px, centred.
        self::assertSame(10, count($inkCols));
        self::assertSame(9, count($inkRows));
        self::assertSame(27, min(array_keys($inkCols)));
        self::assertEqualsWithDelta(($h - 9) / 2, min(array_keys($inkRows)), 1.0);
    }

    /** @return iterable<string, array{string, string}> */
    public static function scripts(): iterable
    {
        yield 'arabic' => ['ar', 'ساحة التدريب'];
        yield 'japanese' => ['ja', '訓練場へようこそ'];
        yield 'thai' => ['th', 'พื้นที่ฝึกซ้อม'];
        yield 'korean' => ['ko', '훈련장으로 가는 길'];
        yield 'russian' => ['ru', 'Тренировочная площадка'];
        yield 'chinese' => ['zh-TW', '訓練場入口'];
    }

    #[DataProvider('scripts')]
    #[RequiresFunction('vio_text_bitmap')]
    public function testScriptsRasterizeWithoutGdInsideTheBox(string $locale, string $text): void
    {
        $faces = FontChain::engine()->faces('semibold', $locale, $text);
        self::assertNotSame([], $faces);

        $r = new TextRasterizer();
        $width = 512;
        $height = 96;
        $margin = 8;
        $fit = $r->fit($faces, $text, $width - 2.0 * $margin, $height - 2.0 * $margin, 1.2, 2, 0.0, 10.0);
        $rgba = $r->render($faces, $fit, $width, $height, Color::white(), new Color(0.0, 0.0, 0.0), 1.2);

        $ink = 0;
        $minX = $width;
        $maxX = -1;
        $minY = $height;
        $maxY = -1;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if (ord($rgba[($y * $width + $x) * 4]) > 16) {
                    $ink++;
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                    $minY = min($minY, $y);
                    $maxY = max($maxY, $y);
                }
            }
        }
        self::assertGreaterThan(500, $ink);
        self::assertGreaterThanOrEqual($margin - 1, $minX);
        self::assertLessThanOrEqual($width - $margin, $maxX);
        self::assertGreaterThanOrEqual($margin - 1, $minY);
        self::assertLessThanOrEqual($height - $margin, $maxY);
    }

    /**
     * A right-to-left paragraph puts a Latin run that follows it on the LEFT: the
     * column profile of the run alone matches the left part of the line.
     */
    #[RequiresFunction('vio_text_bitmap')]
    public function testRightToLeftParagraphRunsRightToLeft(): void
    {
        $columns = static function (string $text): array {
            $bitmap = vio_text_bitmap(FontChain::engine()->faces('regular', 'ar', $text), $text, 40.0);
            self::assertIsArray($bitmap);
            $ink = array_fill(0, $bitmap['width'], 0);
            foreach (str_split((string) $bitmap['data']) as $i => $byte) {
                $ink[$i % $bitmap['width']] += ord($byte);
            }
            return $ink;
        };
        $line = $columns('ساحة التدريب AAAA');
        $run = $columns('AAAA');

        $best = 0;
        $bestError = PHP_INT_MAX;
        for ($offset = 0, $end = count($line) - count($run); $offset <= $end; $offset++) {
            $error = 0;
            foreach ($run as $x => $ink) {
                $error += abs($line[$offset + $x] - $ink);
            }
            if ($error < $bestError) {
                $bestError = $error;
                $best = $offset;
            }
        }

        self::assertLessThan(10, $best, 'the Latin run opens the line on the left');
    }
}
