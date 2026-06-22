<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Testing;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Color;
use PHPolygon\Testing\GdRenderer2D;

/**
 * Regression: drawTextBox/measureTextBox must treat explicit \n as a hard
 * line break instead of dropping it into a token. Multi-line monospace prompts
 * (e.g. overlay text) rely on this.
 *
 * Exercises the GdRenderer2D backend (it shares the wrap algorithm with
 * VioRenderer2D). VioRenderer2D needs a live VioContext, which is unavailable
 * in headless CI, but the bug surface is the same: both backends split on
 * spaces and previously ignored \n.
 *
 * @group font-vrt
 */
class TextBoxNewlineTest extends TestCase
{
    private const FONT_DIR = __DIR__ . '/../../resources/fonts';

    private function renderer(): GdRenderer2D
    {
        $fontPath = self::FONT_DIR . '/Inter-Regular.ttf';
        if (!file_exists($fontPath)) {
            $this->markTestSkipped('Inter-Regular.ttf not found in resources/fonts/');
        }

        $r = new GdRenderer2D(400, 400);
        $r->loadFont('inter', $fontPath);
        $r->setFont('inter');
        return $r;
    }

    public function testMeasureTextBoxCountsHardBreaksAsLines(): void
    {
        $r = $this->renderer();
        $size = 16.0;
        $expectedLineHeight = $size * 1.4;

        // Three lines, each short enough not to wrap.
        $single = $r->measureTextBox('one', 400.0, $size);
        $triple = $r->measureTextBox("one\ntwo\nthree", 400.0, $size);

        $this->assertEqualsWithDelta(
            $expectedLineHeight,
            $single->height,
            0.001,
            'Single line should report exactly one line of height.',
        );
        $this->assertEqualsWithDelta(
            3.0 * $expectedLineHeight,
            $triple->height,
            0.001,
            'Three \\n-separated lines should report three lines of height.',
        );
    }

    public function testMeasureTextBoxTreatsCrlfAndCrAsNewline(): void
    {
        $r = $this->renderer();
        $size = 16.0;
        $expectedLineHeight = $size * 1.4;

        $crlf  = $r->measureTextBox("alpha\r\nbeta",   400.0, $size);
        $cr    = $r->measureTextBox("alpha\rbeta",     400.0, $size);
        $lf    = $r->measureTextBox("alpha\nbeta",     400.0, $size);

        $this->assertEqualsWithDelta(2.0 * $expectedLineHeight, $crlf->height, 0.001);
        $this->assertEqualsWithDelta(2.0 * $expectedLineHeight, $cr->height,   0.001);
        $this->assertEqualsWithDelta(2.0 * $expectedLineHeight, $lf->height,   0.001);
    }

    public function testMeasureTextBoxEmptyParagraphTakesOneLineHeight(): void
    {
        $r = $this->renderer();
        $size = 16.0;
        $expectedLineHeight = $size * 1.4;

        // "a\n\nb" = three lines: "a", empty, "b"
        $m = $r->measureTextBox("a\n\nb", 400.0, $size);
        $this->assertEqualsWithDelta(3.0 * $expectedLineHeight, $m->height, 0.001);
    }

    public function testMeasureTextBoxHardBreakOverridesWordWrap(): void
    {
        $r = $this->renderer();
        $size = 16.0;
        $expectedLineHeight = $size * 1.4;

        // Each side fits in 400px; the only reason there are two lines is the
        // explicit \n. Confirms hard breaks are honoured even when there's
        // plenty of horizontal room.
        $m = $r->measureTextBox("short\nshorter", 400.0, $size);
        $this->assertEqualsWithDelta(2.0 * $expectedLineHeight, $m->height, 0.001);
    }

    public function testDrawTextBoxWithNewlinesDoesNotThrow(): void
    {
        // Smoke test: previously the text would have rendered as a single line
        // containing the literal \n bytes. Now we just confirm the new code
        // path doesn't throw on any of the line-ending variants we accept.
        $r = $this->renderer();
        $r->beginFrame();
        $r->drawTextBox(
            "10 LET X = 5\n20 LET Y = X + 3\n30 PRINT Y",
            10.0, 10.0, 380.0, 16.0,
            new Color(1.0, 1.0, 1.0),
        );
        $r->drawTextBox("a\r\nb\rc", 10.0, 200.0, 380.0, 16.0, new Color(1.0, 1.0, 1.0));
        $r->endFrame();

        $this->assertTrue(true);
    }
}
