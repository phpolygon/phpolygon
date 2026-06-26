<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Rendering;

use PHPolygon\Rendering\VioRenderer2D;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Glyph-atlas devicePixelRatio ({@see VioRenderer2D::setFontRenderScale()}).
 *
 * vio rasterizes glyphs into a fixed-size atlas and bilinearly stretches that
 * atlas when a transform magnifies text — so UI drawn through a design-grid
 * scale (e.g. Code Tycoon's 1280×720 grid magnified to 1440p) rasterized text
 * at its logical size and upscaled it, producing blurry text. Setting a render
 * scale makes the renderer rasterize the atlas at size*scale physical pixels via
 * the vio_font `$scale` param, while the C side reports every metric back in
 * logical units so layout is unchanged.
 *
 * The clamp / key-folding tests are pure and run everywhere; the metric-invariance
 * test needs a live vio context + a system font and is skipped where unavailable.
 */
final class VioRenderer2DFontScaleTest extends TestCase
{
    private const FONT_CANDIDATES = [
        '/Library/Fonts/Arial Unicode.ttf',
        '/System/Library/Fonts/Helvetica.ttc',
        '/System/Library/Fonts/Supplemental/Arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        'C:\\Windows\\Fonts\\arial.ttf',
    ];

    public function testDefaultRenderScaleIsOne(): void
    {
        $renderer = new VioRenderer2D($this->stubContext());
        self::assertSame(1.0, $renderer->getFontRenderScale());
    }

    public function testSetRenderScaleStores(): void
    {
        $renderer = new VioRenderer2D($this->stubContext());
        $renderer->setFontRenderScale(2.0);
        self::assertSame(2.0, $renderer->getFontRenderScale());
    }

    public function testRenderScaleBelowOneIsClamped(): void
    {
        $renderer = new VioRenderer2D($this->stubContext());
        $renderer->setFontRenderScale(0.5);
        self::assertSame(1.0, $renderer->getFontRenderScale(), 'atlas smaller than logical would be the very blur this prevents');
        $renderer->setFontRenderScale(-3.0);
        self::assertSame(1.0, $renderer->getFontRenderScale());
    }

    public function testCacheKeyFoldsInTheRenderScale(): void
    {
        $renderer = new VioRenderer2D($this->stubContext());
        $key = new ReflectionMethod(VioRenderer2D::class, 'fontCacheKey');

        $renderer->setFontRenderScale(1.0);
        $at1 = $key->invoke($renderer, 'inter', 15);

        $renderer->setFontRenderScale(2.0);
        $at2 = $key->invoke($renderer, 'inter', 15);

        // Same font+size at different scales must not collide, so a 1× atlas and
        // a 2× atlas can coexist (e.g. dragging a window between two monitors).
        self::assertNotSame($at1, $at2);
        self::assertSame('inter:15@100', $at1);
        self::assertSame('inter:15@200', $at2);
    }

    /**
     * The core contract: a higher render scale rasterizes a denser atlas but
     * vio_text_measure still reports logical widths, so layout code that calls
     * measureText() is unaffected by the scale. Validates the C-side metric
     * division (vio_text / vio_text_measure divide by render_scale).
     */
    public function testMeasuredWidthIsScaleInvariant(): void
    {
        if (!extension_loaded('vio') || !function_exists('vio_create')) {
            $this->markTestSkipped('vio extension not available.');
        }
        $fontPath = $this->fontPath();
        $ctx = $this->liveContext();

        $renderer = new VioRenderer2D($ctx);
        $renderer->loadFont('sys', $fontPath);
        $renderer->setFont('sys');

        $text = 'Crisp Text 1440p';

        $renderer->setFontRenderScale(1.0);
        $w1 = $renderer->measureText($text, 18.0)->width;

        $renderer->setFontRenderScale(2.0);
        $w2 = $renderer->measureText($text, 18.0)->width;

        self::assertGreaterThan(0.0, $w1, 'font produced no measurable width — atlas empty?');
        // Logical widths must match within sub-pixel hinting/rounding noise: the
        // 2× atlas rasterizes at int(18*2)=36px vs int(18*1)=18px, and the C side
        // divides advances by render_scale. Allow ~2% for stb's per-size hinting.
        self::assertEqualsWithDelta($w1, $w2, $w1 * 0.02 + 1.0, "logical width drifted with render scale (w1={$w1}, w2={$w2})");
    }

    private function fontPath(): string
    {
        foreach (self::FONT_CANDIDATES as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        $this->markTestSkipped('no system font found for font-scale test.');
    }

    /**
     * A VioContext is needed only to construct the renderer for the pure tests;
     * those never touch the GPU. Use a real hidden context when vio is present,
     * otherwise skip (the renderer's constructor type-hints VioContext).
     */
    private function stubContext(): \VioContext
    {
        return $this->liveContext();
    }

    private function liveContext(): \VioContext
    {
        if (!function_exists('vio_create')) {
            $this->markTestSkipped('vio_create() not available.');
        }
        try {
            $ctx = vio_create('auto', [
                'width'  => 16,
                'height' => 16,
                'title'  => 'phpolygon-test',
                'hidden' => true,
            ]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('vio_create() failed: ' . $e->getMessage());
        }
        if (!$ctx instanceof \VioContext) {
            $this->markTestSkipped('vio_create() did not return a VioContext.');
        }
        return $ctx;
    }
}
