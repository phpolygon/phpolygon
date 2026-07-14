<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\BarChart;
use PHPolygon\UI\Widget\DataWidgetContext;
use PHPolygon\UI\Widget\Sizing;
use PHPolygon\UI\Widget\WidgetBinder;
use PHPolygon\UI\Widget\WidgetSerializer;
use PHPUnit\Framework\TestCase;

class BarChartTest extends TestCase
{
    private UIStyle $style;
    private WidgetTestHelper $renderer;

    protected function setUp(): void
    {
        $this->style = UIStyle::dark();
        $this->renderer = new WidgetTestHelper;
    }

    /** @return list<array{method: string, args: array<int, mixed>}> */
    private function bars(): array
    {
        return array_values(array_filter($this->renderer->calls, fn ($c) => $c['method'] === 'drawRect'));
    }

    public function testDrawScalesBarsAgainstAutoMax(): void
    {
        $chart = new BarChart;
        $chart->series = [
            ['label' => 'A', 'value' => 50.0],
            ['label' => 'B', 'value' => 100.0], // the auto-max
            ['label' => 'C', 'value' => 25.0],
        ];
        // No padding so plot height == bounds height for exact scaling maths.
        $chart->pad(new \PHPolygon\UI\Widget\EdgeInsets(0, 0, 0, 0));
        $chart->showLabels = false;
        $chart->setBounds(new Rect(0, 0, 300, 100));
        $chart->draw($this->renderer, $this->style);

        $bars = $this->bars();
        $this->assertCount(3, $bars, 'one bar per data point');

        // Heights scale linearly with value (50 : 100 : 25 == 2 : 4 : 1),
        // independent of the auto-scale headroom factor.
        [$h0, $h1, $h2] = [$bars[0]['args'][3], $bars[1]['args'][3], $bars[2]['args'][3]];
        $this->assertEqualsWithDelta(2.0, $h1 / $h0, 0.001);
        $this->assertEqualsWithDelta(0.5, $h2 / $h0, 0.001);

        // The tallest (auto-max) bar stops short of the plot ceiling — top
        // headroom — but still fills most of it.
        $this->assertLessThan(100.0, $h1);
        $this->assertGreaterThan(80.0, $h1);

        // Bars grow upward from the baseline: a taller bar sits higher (smaller y).
        $this->assertGreaterThan($bars[1]['args'][1], $bars[0]['args'][1]);
        $this->assertGreaterThan($bars[0]['args'][1], $bars[2]['args'][1]);
    }

    public function testExplicitMaxValueOverridesAutoScale(): void
    {
        $chart = new BarChart;
        $chart->series = [['label' => 'A', 'value' => 50.0]];
        $chart->maxValue = 200.0; // 50/200 -> quarter height
        $chart->pad(new \PHPolygon\UI\Widget\EdgeInsets(0, 0, 0, 0));
        $chart->showLabels = false;
        $chart->setBounds(new Rect(0, 0, 100, 100));
        $chart->draw($this->renderer, $this->style);

        $bars = $this->bars();
        $this->assertCount(1, $bars);
        $this->assertEqualsWithDelta(25.0, $bars[0]['args'][3], 0.01);
    }

    public function testBarRectsStayWithinPlotBounds(): void
    {
        $chart = new BarChart;
        $chart->series = [['label' => 'A', 'value' => 10.0], ['label' => 'B', 'value' => 20.0]];
        $chart->showLabels = false;
        $chart->setBounds(new Rect(10, 20, 200, 120));
        $chart->draw($this->renderer, $this->style);

        $content = $chart->getBounds();
        foreach ($this->bars() as $bar) {
            [$x, $y, $w, $h] = [$bar['args'][0], $bar['args'][1], $bar['args'][2], $bar['args'][3]];
            $this->assertGreaterThanOrEqual($content->x - 0.01, $x);
            $this->assertLessThanOrEqual($content->right() + 0.01, $x + $w);
            $this->assertGreaterThanOrEqual($content->y - 0.01, $y);
            $this->assertLessThanOrEqual($content->bottom() + 0.01, $y + $h);
        }
    }

    public function testGroupedSeriesDrawsTwoBarsPerSlot(): void
    {
        $chart = new BarChart;
        $chart->series = [['label' => 'Q1', 'value' => 40.0], ['label' => 'Q2', 'value' => 80.0]];
        $chart->series2 = [['label' => 'Q1', 'value' => 20.0], ['label' => 'Q2', 'value' => 60.0]];
        $chart->pad(new \PHPolygon\UI\Widget\EdgeInsets(0, 0, 0, 0));
        $chart->showLabels = false;
        $chart->setBounds(new Rect(0, 0, 200, 100));
        $chart->draw($this->renderer, $this->style);

        $bars = $this->bars();
        $this->assertCount(4, $bars, 'two series x two slots');

        // Primary series uses accent colour; second uses barColor2.
        $this->assertEquals($this->style->accentColor, $bars[0]['args'][4]);
        $this->assertEquals($chart->barColor2, $bars[1]['args'][4]);

        // In a slot the second bar sits immediately right of the first.
        $this->assertEqualsWithDelta($bars[0]['args'][0] + $bars[0]['args'][2], $bars[1]['args'][0], 0.01);
    }

    public function testDrawsXAxisLabels(): void
    {
        $chart = new BarChart;
        $chart->series = [['label' => 'Jan', 'value' => 5.0], ['label' => 'Feb', 'value' => 9.0]];
        $chart->setBounds(new Rect(0, 0, 200, 120));
        $chart->draw($this->renderer, $this->style);

        $labels = array_map(
            fn ($c) => $c['args'][0],
            array_filter($this->renderer->calls, fn ($c) => $c['method'] === 'drawTextCentered'),
        );
        $this->assertContains('Jan', $labels);
        $this->assertContains('Feb', $labels);
    }

    public function testEmptySeriesDrawsNothing(): void
    {
        $chart = new BarChart;
        $chart->setBounds(new Rect(0, 0, 200, 100));
        $chart->draw($this->renderer, $this->style);

        $this->assertEmpty($this->bars());
    }

    public function testAcceptsPositionalAndBareRows(): void
    {
        $chart = new BarChart;
        $chart->series = [['Jan', 30.0], 60.0]; // positional row + bare number
        $chart->maxValue = 60.0; // explicit max -> no auto headroom, exact maths
        $chart->pad(new \PHPolygon\UI\Widget\EdgeInsets(0, 0, 0, 0));
        $chart->showLabels = false;
        $chart->setBounds(new Rect(0, 0, 100, 100));
        $chart->draw($this->renderer, $this->style);

        $bars = $this->bars();
        $this->assertCount(2, $bars);
        $this->assertEqualsWithDelta(50.0, $bars[0]['args'][3], 0.01); // 30/60 * 100
        $this->assertEqualsWithDelta(100.0, $bars[1]['args'][3], 0.01);
    }

    public function testSeriesBindsFromContext(): void
    {
        $chart = new BarChart;
        $chart->bindings['series'] = 'monthly';

        $vm = (object) ['monthly' => [['label' => 'A', 'value' => 1.0], ['label' => 'B', 'value' => 2.0]]];
        (new WidgetBinder)->bind($chart, new DataWidgetContext($vm));

        $this->assertCount(2, $chart->series);
        $this->assertSame(2.0, $chart->series[1]['value']);
    }

    public function testBoundSeriesFromTraversableMaterializes(): void
    {
        $chart = new BarChart;
        $chart->bindings['series'] = 'rows';

        $gen = (function () {
            yield ['label' => 'A', 'value' => 3.0];
            yield ['label' => 'B', 'value' => 4.0];
        });
        $vm = new class($gen())
        {
            public function __construct(public \Iterator $rows) {}
        };

        (new WidgetBinder)->bind($chart, new DataWidgetContext($vm));

        $this->assertIsArray($chart->series);
        $this->assertCount(2, $chart->series);
    }

    public function testSerializerRoundTrip(): void
    {
        $chart = new BarChart;
        $chart->maxValue = 500.0;
        $chart->size(Sizing::fillWidth(180.0));
        $chart->bindings['series'] = 'monthly';

        $serializer = new WidgetSerializer;
        $array = $serializer->toArray($chart);

        $this->assertSame(BarChart::class, $array['_widget']);
        $this->assertSame(['$bind' => 'monthly'], $array['series']);
        $this->assertSame(500.0, $array['maxValue']);

        $restored = $serializer->fromArray($array);
        $this->assertInstanceOf(BarChart::class, $restored);
        $this->assertSame(500.0, $restored->maxValue);
        $this->assertSame('monthly', $restored->bindings['series']);
    }

    public function testInstantiatesFromWidgetKey(): void
    {
        $restored = (new WidgetSerializer)->fromArray([
            '_widget' => BarChart::class,
            'series' => [['label' => 'X', 'value' => 10.0]],
        ]);

        $this->assertInstanceOf(BarChart::class, $restored);
        $this->assertCount(1, $restored->series);
    }
}
