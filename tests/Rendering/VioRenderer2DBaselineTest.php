<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\TextAlign;
use PHPolygon\Rendering\VioRenderer2D;
use PHPUnit\Framework\TestCase;

/**
 * TOP / MIDDLE alignment anchors on the FONT, not on the ink of the string.
 *
 * vio_text_measure() reports the ink extent of exactly the measured glyphs
 * (a lone '_' is ~3 px high). drawText()/drawTextCentered() used to derive the
 * baseline from that height, so every string got its own baseline: a single
 * '_' drawn TOP-aligned landed at the top of the line where '-' belongs, and
 * per-character drawing made letters jump up and down. Now all strings drawn at
 * the same y share one baseline.
 *
 * Needs a live (hidden) vio context, a system font and vio_save_screenshot().
 */
final class VioRenderer2DBaselineTest extends TestCase
{
    private const FONT_CANDIDATES = [
        'C:\\Windows\\Fonts\\consola.ttf',
        'C:\\Windows\\Fonts\\arial.ttf',
        '/System/Library/Fonts/Supplemental/Courier New.ttf',
        '/System/Library/Fonts/Supplemental/Arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
    ];

    private const int W = 400;
    private const int H = 160;
    private const float SIZE = 28.0;
    private const float STEP = 40.0;

    public function testSingleGlyphsShareTheBaselineWhenTopAligned(): void
    {
        $glyphs = ['x', '_', '-', 'H', 'g', '.'];
        $image = $this->render(function (VioRenderer2D $r) use ($glyphs): void {
            $r->setTextAlign(TextAlign::LEFT | TextAlign::TOP);
            foreach ($glyphs as $i => $glyph) {
                $r->drawText($glyph, 10.0 + $i * self::STEP, 40.0, self::SIZE, Color::white());
            }
        });

        [$xTop, $xBottom] = $this->ink($image, 0) ?? self::fail('no "x" drawn');
        [$uTop] = $this->ink($image, 1) ?? self::fail('no "_" drawn');
        [$hTop, $hBottom] = $this->ink($image, 2) ?? self::fail('no "-" drawn');
        [, $capBottom] = $this->ink($image, 3) ?? self::fail('no "H" drawn');
        [, $dotBottom] = $this->ink($image, 5) ?? self::fail('no "." drawn');

        self::assertEqualsWithDelta($xBottom, $capBottom, 1, '"x" and "H" sit on different baselines');
        self::assertEqualsWithDelta($xBottom, $dotBottom, 1, '"." left the baseline');
        self::assertGreaterThanOrEqual($xBottom - 1, $uTop, "'_' starts above the baseline (y={$uTop}, baseline y={$xBottom})");
        $hyphenMid = ($hTop + $hBottom) / 2.0;
        self::assertGreaterThan($xTop, $hyphenMid, "'-' above the x-height");
        self::assertLessThan($xBottom - 1, $hyphenMid, "'-' on the baseline");
    }

    public function testCenteredSingleGlyphsKeepTheirHeightOrder(): void
    {
        $image = $this->render(function (VioRenderer2D $r): void {
            foreach (['_', '-', 'x'] as $i => $glyph) {
                $r->drawTextCentered($glyph, 20.0 + $i * self::STEP, 80.0, self::SIZE, Color::white());
            }
        });

        [$uTop, $uBottom] = $this->ink($image, 0) ?? self::fail('no "_" drawn');
        [$hTop, $hBottom] = $this->ink($image, 1) ?? self::fail('no "-" drawn');
        [$xTop, $xBottom] = $this->ink($image, 2) ?? self::fail('no "x" drawn');
        // Centred at the same point, all three share one baseline.
        self::assertGreaterThanOrEqual($xBottom - 1, $uTop, "centered '_' above the baseline of a centered 'x'");
        $hyphenMid = ($hTop + $hBottom) / 2.0;
        self::assertGreaterThan($xTop, $hyphenMid, "centered '-' above the x-height");
        self::assertLessThan($xBottom - 1, $hyphenMid, "centered '-' on the baseline");
        self::assertGreaterThan($hyphenMid + 5, ($uTop + $uBottom) / 2.0, "centered '_' must sit clearly below centered '-'");
    }

    /** @param callable(VioRenderer2D): void $draw */
    private function render(callable $draw): \GdImage
    {
        if (!function_exists('vio_create') || !function_exists('vio_save_screenshot')) {
            self::markTestSkipped('vio extension not available.');
        }
        $font = null;
        foreach (self::FONT_CANDIDATES as $candidate) {
            if (is_file($candidate)) {
                $font = $candidate;
                break;
            }
        }
        if ($font === null) {
            self::markTestSkipped('no system font found.');
        }
        try {
            $ctx = vio_create('auto', ['width' => self::W, 'height' => self::H, 'headless' => true]);
        } catch (\Throwable $e) {
            self::markTestSkipped('vio_create() failed: ' . $e->getMessage());
        }
        if (!$ctx instanceof \VioContext) {
            self::markTestSkipped('vio_create() did not return a VioContext.');
        }

        $r = new VioRenderer2D($ctx);
        $r->loadFont('probe', $font);
        $r->setFont('probe');
        // A few frames: the lazy glyph atlas fills on the first.
        for ($frame = 0; $frame < 3; $frame++) {
            $r->beginFrame();
            $draw($r);
            $r->endFrame();
        }

        $path = tempnam(sys_get_temp_dir(), 'baseline') . '.png';
        self::assertTrue(vio_save_screenshot($ctx, $path));
        $image = imagecreatefrompng($path);
        @unlink($path);
        self::assertInstanceOf(\GdImage::class, $image);

        return $image;
    }

    /**
     * Topmost and bottommost bright row in the column of glyph $index.
     *
     * @return array{int, int}|null
     */
    private function ink(\GdImage $image, int $index): ?array
    {
        $x0 = (int) (5 + $index * self::STEP);
        $top = null;
        $bottom = null;
        for ($y = 0; $y < self::H; $y++) {
            for ($x = $x0; $x < min(self::W, $x0 + (int) self::STEP - 5); $x++) {
                $c = imagecolorat($image, $x, $y);
                if ((($c >> 16) & 0xFF) + (($c >> 8) & 0xFF) + ($c & 0xFF) > 300) {
                    $top ??= $y;
                    $bottom = $y;
                }
            }
        }

        return $top === null || $bottom === null ? null : [$top, $bottom];
    }
}
