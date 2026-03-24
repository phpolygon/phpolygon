<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Testing;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Color;
use PHPolygon\Testing\GdRenderer2D;
use PHPolygon\Testing\VisualTestCase;

/**
 * Visual regression tests that use fonts.
 *
 * Because FreeType rendering can differ between OS versions,
 * these tests use platform-suffixed snapshots.
 *
 * @group font-vrt
 */
class FontRenderingTest extends TestCase
{
    use VisualTestCase;

    private const FONT_DIR = __DIR__ . '/../../resources/fonts';

    protected function usePlatformSuffix(): bool
    {
        return true; // Fonts need per-platform snapshots
    }

    public function testTextRendering(): void
    {
        $fontPath = self::FONT_DIR . '/Inter-Regular.ttf';
        if (!file_exists($fontPath)) {
            $this->markTestSkipped('Inter-Regular.ttf not found in resources/fonts/');
        }

        $renderer = new GdRenderer2D(400, 200);
        $renderer->loadFont('inter', $fontPath);
        $renderer->setFont('inter');

        $renderer->beginFrame();
        $renderer->clear(new Color(0.12, 0.12, 0.15));

        $renderer->drawText('Hello PHPolygon', 20, 20, 24, new Color(1.0, 1.0, 1.0));
        $renderer->drawText('Score: 42,000', 20, 60, 18, new Color(0.3, 0.9, 0.4));
        $renderer->drawText('Game Over', 20, 100, 36, new Color(0.9, 0.2, 0.2));

        $renderer->endFrame();

        $this->assertScreenshot($renderer, 'text-rendering');
    }

    public function testTextCentered(): void
    {
        $fontPath = self::FONT_DIR . '/Inter-Regular.ttf';
        if (!file_exists($fontPath)) {
            $this->markTestSkipped('Inter-Regular.ttf not found in resources/fonts/');
        }

        $renderer = new GdRenderer2D(400, 100);
        $renderer->loadFont('inter', $fontPath);
        $renderer->setFont('inter');

        $renderer->beginFrame();
        $renderer->clear(new Color(0.05, 0.05, 0.1));

        // Draw a button-like shape with centered text
        $renderer->drawRoundedRect(100, 20, 200, 60, 8, new Color(0.2, 0.5, 0.9));
        $renderer->drawTextCentered('Start Game', 200, 50, 20, new Color(1.0, 1.0, 1.0));

        $renderer->endFrame();

        $this->assertScreenshot($renderer, 'text-centered');
    }

    public function testTextWithShapes(): void
    {
        $fontPath = self::FONT_DIR . '/Inter-Regular.ttf';
        $boldFontPath = self::FONT_DIR . '/Inter-SemiBold.ttf';
        if (!file_exists($fontPath) || !file_exists($boldFontPath)) {
            $this->markTestSkipped('Inter fonts not found in resources/fonts/');
        }

        $renderer = new GdRenderer2D(300, 200);
        $renderer->loadFont('inter', $fontPath);
        $renderer->loadFont('inter-bold', $boldFontPath);

        $renderer->beginFrame();
        $renderer->clear(new Color(0.08, 0.08, 0.1));

        // Panel background
        $renderer->drawRoundedRect(10, 10, 280, 180, 6, new Color(0.15, 0.15, 0.2));

        // Title
        $renderer->setFont('inter-bold');
        $renderer->drawText('Inventory', 25, 20, 20, new Color(0.9, 0.85, 0.6));

        // Items
        $renderer->setFont('inter');
        $items = ['Sword of Light', 'Health Potion x3', 'Iron Shield'];
        $y = 60;
        foreach ($items as $i => $item) {
            $bgColor = $i % 2 === 0
                ? new Color(0.18, 0.18, 0.24)
                : new Color(0.14, 0.14, 0.19);
            $renderer->drawRect(20, (float) $y - 2, 260, 28, $bgColor);
            $renderer->drawText($item, 30, (float) $y, 14, new Color(0.8, 0.8, 0.85));
            $y += 32;
        }

        $renderer->endFrame();

        $this->assertScreenshot($renderer, 'text-with-shapes');
    }
}
