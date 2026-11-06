<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\VioRenderer2D;

/**
 * Arabic through a fallback chain on screen looks like the shaped reference
 * vio_text_bitmap draws (bidi, HarfBuzz, per-EM raster) - right to left, joined,
 * at full size.
 *
 * Up to v0.97.1 it did not, for two reasons: the runs of a split string went left
 * to right in logical order (sentence backwards word by word), and php-vio's
 * vio_text drew Noto Sans Arabic's glyphs at 47 % of the advance HarfBuzz reserved
 * for them (stb_truetype scale by pixel height, HarfBuzz by EM - see
 * EmNormalizedFont): tiny letters with gaps where they join. Measured on WARP with
 * v0.97.0: ink 360x24 against the reference's 321x35, column correlation 0.31;
 * with the fix 324x34 and 0.98.
 */
#[RequiresPhpExtension('vio')]
#[Group('native-gpu')]
final class VioRenderer2DArabicTextTest extends TestCase
{
    private const int W = 520;
    private const int H = 72;
    private const float SIZE = 32.0;
    private const string FONTS = __DIR__ . '/../../resources/fonts';
    private const string TEXT = 'ليس تمامًا – حاول مجددًا!';

    public function testArabicMatchesTheShapedReference(): void
    {
        if (!function_exists('vio_text_bitmap') || !function_exists('vio_font_face')) {
            self::markTestSkipped('php-vio without vio_text_bitmap');
        }
        $ctx = VioTestContext::create(self::W, self::H);
        if ($ctx === null) {
            self::markTestSkipped('vio_create(headless) unavailable');
        }
        try {
            $renderer = new VioRenderer2D($ctx);
            $renderer->loadFont('regular', self::FONTS . '/Inter-Regular.ttf');
            $renderer->loadFont('noto-sans-arabic', self::FONTS . '/noto-sans-arabic/NotoSansArabic-Regular.ttf');
            $renderer->addFallbackFont('regular', 'noto-sans-arabic');
            $renderer->setFont('regular');
            for ($i = 0; $i < 2; $i++) {
                $renderer->beginFrame();
                $renderer->clear(new Color(0.0, 0.0, 0.0, 1.0));
                $renderer->drawText(self::TEXT, 10.0, 48.0, self::SIZE, new Color(1.0, 1.0, 1.0, 1.0));
                $renderer->endFrame();
            }
            $pixels = vio_read_pixels($ctx);
            self::assertIsString($pixels);
        } finally {
            unset($renderer);
            vio_destroy($ctx);
        }

        $screen = [];
        for ($y = 0; $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                $screen[$y][$x] = ord($pixels[($y * self::W + $x) * 4]);
            }
        }
        $faces = [vio_font_face(self::FONTS . '/Inter-Regular.ttf'), vio_font_face(self::FONTS . '/noto-sans-arabic/NotoSansArabic-Regular.ttf')];
        $ref = vio_text_bitmap($faces, self::TEXT, self::SIZE);
        self::assertIsArray($ref);
        $reference = [];
        for ($y = 0; $y < $ref['height']; $y++) {
            for ($x = 0; $x < $ref['width']; $x++) {
                $reference[$y][$x] = ord(($ref['data'] ?? '')[$y * $ref['width'] + $x]);
            }
        }

        $dump = getenv('PHPOLYGON_TEST_DUMP_DIR');
        if (is_string($dump) && $dump !== '' && function_exists('imagecreatetruecolor')) {
            foreach (['screen' => $screen, 'reference' => $reference] as $name => $img) {
                $w = count($img[0]);
                $out = imagecreatetruecolor($w, count($img));
                foreach ($img as $y => $row) {
                    foreach ($row as $x => $v) {
                        imagesetpixel($out, $x, $y, ($v << 16) | ($v << 8) | $v);
                    }
                }
                imagepng($out, "{$dump}/arabic-{$name}.png");
            }
        }

        [$sw, $sh, $sCols] = self::ink($screen);
        [$rw, $rh, $rCols] = self::ink($reference);
        $correlation = self::correlation(self::resample($sCols, count($rCols)), $rCols);
        fprintf(STDERR, "\n[arabic] ink %dx%d vs reference %dx%d, column correlation %.2f\n", $sw, $sh, $rw, $rh, $correlation);

        self::assertGreaterThan(0.85, $sh / $rh, 'glyphs at full size, not shrunk');
        self::assertEqualsWithDelta(1.0, $sw / $rw, 0.12, 'the line is as long as the reference');
        self::assertGreaterThan(0.8, $correlation, 'right to left and joined like the reference');
    }

    /**
     * Ink box of a coverage image and its column sums inside the box.
     *
     * @param array<int, array<int, int>> $img
     * @return array{int, int, list<float>}
     */
    private static function ink(array $img): array
    {
        $x0 = PHP_INT_MAX;
        $x1 = -1;
        $y0 = PHP_INT_MAX;
        $y1 = -1;
        foreach ($img as $y => $row) {
            foreach ($row as $x => $v) {
                if ($v > 64) {
                    $x0 = min($x0, $x);
                    $x1 = max($x1, $x);
                    $y0 = min($y0, $y);
                    $y1 = max($y1, $y);
                }
            }
        }
        self::assertGreaterThanOrEqual(0, $x1, 'no ink');
        $cols = [];
        for ($x = $x0; $x <= $x1; $x++) {
            $sum = 0;
            foreach ($img as $row) {
                $sum += $row[$x] ?? 0;
            }
            $cols[] = (float) $sum;
        }

        return [$x1 - $x0 + 1, $y1 - $y0 + 1, $cols];
    }

    /**
     * @param list<float> $values
     * @return list<float>
     */
    private static function resample(array $values, int $length): array
    {
        $n = count($values);
        $out = [];
        for ($i = 0; $i < $length; $i++) {
            $out[] = $values[min($n - 1, (int) floor(($i + 0.5) * $n / $length))];
        }

        return $out;
    }

    /**
     * Pearson correlation of two profiles of equal length, each smoothed over 5 columns.
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function correlation(array $a, array $b): float
    {
        $smooth = static function (array $v): array {
            $n = count($v);
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $sum = 0.0;
                $k = 0;
                for ($j = max(0, $i - 2); $j <= min($n - 1, $i + 2); $j++) {
                    $sum += $v[$j];
                    $k++;
                }
                $out[] = $sum / $k;
            }

            return $out;
        };
        $a = $smooth($a);
        $b = $smooth($b);
        $ma = array_sum($a) / count($a);
        $mb = array_sum($b) / count($b);
        $num = 0.0;
        $da = 0.0;
        $db = 0.0;
        foreach ($a as $i => $va) {
            $num += ($va - $ma) * ($b[$i] - $mb);
            $da += ($va - $ma) ** 2;
            $db += ($b[$i] - $mb) ** 2;
        }

        return $da > 0.0 && $db > 0.0 ? $num / sqrt($da * $db) : 0.0;
    }
}
