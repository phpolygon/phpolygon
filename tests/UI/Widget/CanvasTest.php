<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Runtime\Input;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Canvas;
use PHPolygon\UI\Widget\DataWidgetContext;
use PHPolygon\UI\Widget\EdgeInsets;
use PHPolygon\UI\Widget\Sizing;
use PHPolygon\UI\Widget\WidgetBinder;
use PHPolygon\UI\Widget\WidgetSerializer;
use PHPUnit\Framework\TestCase;

class CanvasTest extends TestCase
{
    private UIStyle $style;
    private WidgetTestHelper $renderer;

    protected function setUp(): void
    {
        $this->style = UIStyle::dark();
        $this->renderer = new WidgetTestHelper;
    }

    public function testCallbackInvokedWithContentRect(): void
    {
        $captured = null;
        $canvas = new Canvas;
        $canvas->pad(EdgeInsets::all(5.0));
        $canvas->setBounds(new Rect(10, 20, 100, 60));
        $canvas->drawFn = function (Renderer2DInterface $r, Rect $bounds) use (&$captured): void {
            $captured = $bounds;
        };

        $canvas->draw($this->renderer, $this->style);

        $this->assertInstanceOf(Rect::class, $captured);
        // Content rect = bounds minus padding.
        $this->assertEqualsWithDelta(15.0, $captured->x, 0.001);
        $this->assertEqualsWithDelta(25.0, $captured->y, 0.001);
        $this->assertEqualsWithDelta(90.0, $captured->width, 0.001);
        $this->assertEqualsWithDelta(50.0, $captured->height, 0.001);
    }

    public function testCallbackReceivesRendererAndCanDraw(): void
    {
        $canvas = new Canvas;
        $canvas->setBounds(new Rect(0, 0, 40, 40));
        $canvas->drawFn = function (Renderer2DInterface $r, Rect $b): void {
            $r->drawRect($b->x, $b->y, $b->width, $b->height, Color::white());
        };

        $canvas->draw($this->renderer, $this->style);

        $rects = array_values(array_filter($this->renderer->calls, fn ($c) => $c['method'] === 'drawRect'));
        $this->assertCount(1, $rects);
        $this->assertSame([0.0, 0.0, 40.0, 40.0], array_slice($rects[0]['args'], 0, 4));
    }

    public function testInputThreadedToCallback(): void
    {
        $receivedInput = 'unset';
        $canvas = new Canvas;
        $canvas->setBounds(new Rect(0, 0, 10, 10));
        $input = new Input;
        $canvas->setInput($input);
        $canvas->drawFn = function (Renderer2DInterface $r, Rect $b, ?InputInterface $in) use (&$receivedInput): void {
            $receivedInput = $in;
        };

        $canvas->draw($this->renderer, $this->style);

        $this->assertSame($input, $receivedInput);
    }

    public function testInputDefaultsToNull(): void
    {
        $received = 'unset';
        $canvas = new Canvas;
        $canvas->setBounds(new Rect(0, 0, 10, 10));
        $canvas->drawFn = function (Renderer2DInterface $r, Rect $b, ?InputInterface $in) use (&$received): void {
            $received = $in;
        };

        $canvas->draw($this->renderer, $this->style);

        $this->assertNull($received);
    }

    public function testNoCallbackIsSafeNoOp(): void
    {
        $canvas = new Canvas;
        $canvas->setBounds(new Rect(0, 0, 10, 10));
        $canvas->draw($this->renderer, $this->style);

        $this->assertEmpty($this->renderer->calls);
    }

    public function testNonCallableBoundValueIgnored(): void
    {
        $canvas = new Canvas;
        $canvas->drawFn = 'not a callable';
        $canvas->setBounds(new Rect(0, 0, 10, 10));

        $canvas->draw($this->renderer, $this->style);

        $this->assertEmpty($this->renderer->calls);
    }

    public function testDrawFnBindsFromContext(): void
    {
        $called = false;
        $vm = new class
        {
            /** @var callable */
            public $paint;
        };
        $vm->paint = function (Renderer2DInterface $r, Rect $b) use (&$called): void {
            $called = true;
        };

        $canvas = new Canvas;
        $canvas->bindings['drawFn'] = 'paint';
        (new WidgetBinder)->bind($canvas, new DataWidgetContext($vm));

        $this->assertIsCallable($canvas->drawFn);

        $canvas->setBounds(new Rect(0, 0, 10, 10));
        $canvas->draw($this->renderer, $this->style);
        $this->assertTrue($called);
    }

    public function testParticipatesInLayoutFillAndFixed(): void
    {
        $fill = new Canvas;
        $fill->size(Sizing::fill());
        $fill->measure(400, 300, $this->style);
        $this->assertSame(400.0, $fill->getMeasuredWidth());
        $this->assertSame(300.0, $fill->getMeasuredHeight());

        $fixed = new Canvas;
        $fixed->size(Sizing::fixed(120, 80));
        $fixed->measure(400, 300, $this->style);
        $this->assertSame(120.0, $fixed->getMeasuredWidth());
        $this->assertSame(80.0, $fixed->getMeasuredHeight());
    }

    public function testSerializerRoundTripDropsCallback(): void
    {
        $canvas = new Canvas;
        $canvas->size(Sizing::fixed(200, 120));
        $canvas->bindings['drawFn'] = 'paint';

        $serializer = new WidgetSerializer;
        $array = $serializer->toArray($canvas);

        $this->assertSame(Canvas::class, $array['_widget']);
        $this->assertSame(['$bind' => 'paint'], $array['drawFn']);

        $restored = $serializer->fromArray($array);
        $this->assertInstanceOf(Canvas::class, $restored);
        $this->assertSame('paint', $restored->bindings['drawFn']);
        $this->assertNull($restored->drawFn);
    }

    public function testInstantiatesFromWidgetKey(): void
    {
        $restored = (new WidgetSerializer)->fromArray([
            '_widget' => Canvas::class,
            'drawFn' => ['$bind' => 'render'],
        ]);

        $this->assertInstanceOf(Canvas::class, $restored);
        $this->assertSame('render', $restored->bindings['drawFn']);
    }
}
