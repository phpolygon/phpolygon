<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Rect;
use PHPolygon\Rendering\Color;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Label;

/**
 * A Label with a backgroundColor renders a filled pill behind the text (badges,
 * tags) — but nothing when the text is empty, so a data-bound badge vanishes at
 * zero without the view-model also clearing the colour.
 */
class LabelBackgroundTest extends TestCase
{
    private function draw(Label $label): WidgetTestHelper
    {
        $label->setBounds(new Rect(10.0, 10.0, 24.0, 18.0));
        $renderer = new WidgetTestHelper();
        $label->draw($renderer, UIStyle::dark());
        return $renderer;
    }

    private function roundedRects(WidgetTestHelper $r): int
    {
        return count(array_filter($r->calls, static fn($c) => $c['method'] === 'drawRoundedRect'));
    }

    public function testBackgroundColorDrawsAPillBehindText(): void
    {
        $label = new Label('3');
        $label->backgroundColor = new Color(0.90, 0.65, 0.15, 1.0);

        $this->assertSame(1, $this->roundedRects($this->draw($label)), 'a badge label draws its pill');
    }

    public function testEmptyTextDrawsNoPill(): void
    {
        $label = new Label('');
        $label->backgroundColor = new Color(0.90, 0.65, 0.15, 1.0);

        $this->assertSame(0, $this->roundedRects($this->draw($label)), 'an empty badge draws nothing');
    }

    public function testNoBackgroundColorDrawsNoPill(): void
    {
        $label = new Label('hi'); // no backgroundColor set

        $this->assertSame(0, $this->roundedRects($this->draw($label)), 'a plain label has no pill');
    }

    private function drawTextY(WidgetTestHelper $r): float
    {
        $dt = array_values(array_filter($r->calls, static fn($c) => $c['method'] === 'drawText'));
        self::assertNotEmpty($dt, 'text was drawn');
        return (float) $dt[0]['args'][2]; // [text, x, y, size, color]
    }

    public function testValignCenterMiddlesTextInBounds(): void
    {
        // Bounds are (10, 10, 24, 18) -> vertical middle is 10 + 18/2 = 19.
        $label = new Label('3');
        $label->valign = 'center';

        self::assertEqualsWithDelta(19.0, $this->drawTextY($this->draw($label)), 0.01, 'text centred vertically');
    }

    public function testDefaultValignIsTop(): void
    {
        $label = new Label('3'); // default valign 'top' -> y at bounds.y + padding.top

        self::assertLessThan(19.0, $this->drawTextY($this->draw($label)), 'default sits above the middle');
    }
}
