<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Rect;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Slider;

/**
 * A slider prints "label: 0.50" by default — right for a debug knob, wrong for
 * a currency or a percentage. `valueText` hands the whole line to the host.
 */
class SliderTest extends TestCase
{
    private function caption(Slider $slider): string
    {
        $slider->setBounds(new Rect(0.0, 0.0, 200.0, 32.0));
        $renderer = new WidgetTestHelper();
        $slider->draw($renderer, UIStyle::dark());

        $texts = array_values(array_filter($renderer->calls, static fn($c) => $c['method'] === 'drawText'));
        self::assertNotEmpty($texts, 'the slider draws its caption');

        return (string) $texts[0]['args'][0];
    }

    public function testTheDefaultCaptionIsLabelAndValue(): void
    {
        self::assertSame('Volume: 0.50', $this->caption(new Slider('Volume', 0.5)));
    }

    public function testValueTextReplacesTheWholeLine(): void
    {
        $slider = new Slider('Pay', 500.0, 0.0, 2500.0);
        $slider->valueText = 'Pay yourself: 500 EUR / month';

        self::assertSame(
            'Pay yourself: 500 EUR / month',
            $this->caption($slider),
            'the host formats the line, the widget only prints it',
        );
    }

    public function testAnEmptyValueTextFallsBackToTheDefault(): void
    {
        $slider = new Slider('Volume', 0.25);
        $slider->valueText = '';

        self::assertSame('Volume: 0.25', $this->caption($slider));
    }

    public function testDraggingStillReportsTheNumericValue(): void
    {
        $slider = new Slider('Pay', 0.0, 0.0, 1000.0);
        $slider->valueText = 'anything';
        $slider->setBounds(new Rect(0.0, 0.0, 200.0, 32.0));

        // A caption of the host's choosing must not touch the value the drag
        // reports back — that is what the change event carries.
        self::assertGreaterThan(0.0, $slider->valueFromMouseX(150.0));
    }
}
