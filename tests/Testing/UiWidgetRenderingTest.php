<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Testing;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Color;
use PHPolygon\Runtime\Input;
use PHPolygon\Testing\GdRenderer2D;
use PHPolygon\Testing\VisualTestCase;
use PHPolygon\UI\UIContext;
use PHPolygon\UI\UIStyle;

/**
 * Visual regression tests for the immediate-mode UI toolkit (UIContext).
 *
 * Renders representative widget layouts through GdRenderer2D in their idle
 * state (a fresh Input reports no hover/click, so every widget draws its
 * resting appearance). Guards the widget geometry, spacing and styling that
 * has had no pixel coverage until now.
 *
 * Uses fonts, so — like FontRenderingTest — these run in the Alpine VRT
 * container (deterministic FreeType) and are excluded from host jobs.
 *
 * @group font-vrt
 */
class UiWidgetRenderingTest extends TestCase
{
    use VisualTestCase;

    private const FONT_DIR = __DIR__ . '/../../resources/fonts';

    private function makeRenderer(int $w, int $h): GdRenderer2D
    {
        $fontPath = self::FONT_DIR . '/Inter-Regular.ttf';
        if (!file_exists($fontPath)) {
            $this->markTestSkipped('Inter-Regular.ttf not found in resources/fonts/');
        }
        $renderer = new GdRenderer2D($w, $h);
        // UIContext::begin() sets the style font (default name 'default').
        $renderer->loadFont('default', $fontPath);
        return $renderer;
    }

    public function testWidgetPanelDarkTheme(): void
    {
        $renderer = $this->makeRenderer(360, 520);
        $ctx = new UIContext($renderer, new Input(), UIStyle::dark());

        $renderer->beginFrame();
        $renderer->clear(new Color(0.10, 0.11, 0.14));

        $ctx->begin(20.0, 20.0, 320.0);
        $ctx->label('Graphics Settings');
        $ctx->separator();
        $ctx->button('play', 'Apply');
        $ctx->button('disabled', 'Unavailable', 0.0, true);
        $ctx->checkbox('fullscreen', 'Fullscreen', true);
        $ctx->checkbox('vsync', 'V-Sync', false);
        $ctx->slider('volume', 'Master Volume', 0.65);
        $ctx->slider('gamma', 'Gamma', 0.4);
        $ctx->progressBar('Loading assets', 0.42);
        $ctx->dropdown('quality', ['Low', 'Medium', 'High', 'Ultra'], 2);
        $ctx->end();
        $ctx->flushOverlays();

        $renderer->endFrame();

        $this->assertScreenshot($renderer, 'ui-widgets-dark', maxDiffPixelRatio: 0.001);
    }

    public function testWidgetRowsAndLabels(): void
    {
        $renderer = $this->makeRenderer(320, 260);
        $ctx = new UIContext($renderer, new Input(), UIStyle::dark());

        $renderer->beginFrame();
        $renderer->clear(new Color(0.08, 0.08, 0.10));

        $ctx->begin(16.0, 16.0, 288.0);
        $ctx->label('Status');
        $ctx->label('Health: 100%', new Color(0.3, 0.9, 0.4));
        $ctx->label('Danger: high', new Color(0.95, 0.3, 0.25));
        $ctx->separator();
        $ctx->progressBar('XP', 0.75);
        $ctx->progressBar('Shield', 0.2);
        $ctx->end();
        $ctx->flushOverlays();

        $renderer->endFrame();

        $this->assertScreenshot($renderer, 'ui-labels-progress', maxDiffPixelRatio: 0.001);
    }
}
