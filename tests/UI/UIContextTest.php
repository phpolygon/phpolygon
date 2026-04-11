<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPUnit\Framework\TestCase;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\TextMetrics;
use PHPolygon\Rendering\Texture;
use PHPolygon\Math\Mat3;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Runtime\Input;
use PHPolygon\UI\UIContext;
use PHPolygon\UI\UIStyle;

class UIContextTest extends TestCase
{
    private Input $input;
    private UIContext $ctx;

    /** @var list<array{method: string, args: array}> */
    private array $drawCalls;

    protected function setUp(): void
    {
        $this->input = new Input();
        $this->drawCalls = [];
        $test = $this;

        $renderer = new class($test) implements Renderer2DInterface {
            public function __construct(private readonly UIContextTest $test) {}
            private function record(string $method, array $args): void {
                $this->test->recordDraw($method, $args);
            }
            public function beginFrame(): void {}
            public function endFrame(): void {}
            public function clear(Color $color): void {}
            public function setViewport(int $x, int $y, int $width, int $height): void {}
            public function getWidth(): int { return 1280; }
            public function getHeight(): int { return 720; }
            public function drawRect(float $x, float $y, float $w, float $h, Color $color): void { $this->record('drawRect', func_get_args()); }
            public function drawRectOutline(float $x, float $y, float $w, float $h, Color $color, float $lineWidth = 1.0): void { $this->record('drawRectOutline', func_get_args()); }
            public function drawRoundedRect(float $x, float $y, float $w, float $h, float $radius, Color $color): void { $this->record('drawRoundedRect', func_get_args()); }
            public function drawRoundedRectOutline(float $x, float $y, float $w, float $h, float $radius, Color $color, float $lineWidth = 1.0): void {}
            public function drawCircle(float $cx, float $cy, float $r, Color $color): void { $this->record('drawCircle', func_get_args()); }
            public function drawCircleOutline(float $cx, float $cy, float $r, Color $color, float $lineWidth = 1.0): void {}
            public function drawLine(Vec2 $from, Vec2 $to, Color $color, float $width = 1.0): void {}
            public function drawText(string $text, float $x, float $y, float $size, Color $color): void { $this->record('drawText', func_get_args()); }
            public function drawTextCentered(string $text, float $cx, float $cy, float $size, Color $color): void { $this->record('drawTextCentered', func_get_args()); }
            public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, Color $color): void {}
            public function drawSprite(Texture $texture, ?Rect $srcRegion, float $x, float $y, float $w, float $h, float $opacity = 1.0): void {}
            public function pushTransform(Mat3 $matrix): void {}
            public function popTransform(): void {}
            public function pushScissor(float $x, float $y, float $w, float $h): void {}
            public function popScissor(): void {}
            public function loadFont(string $name, string $path): void {}
            public function setFont(string $name): void {}
            public function setTextAlign(int $align): void {}
            public function measureText(string $text, float $size): TextMetrics { return new TextMetrics(strlen($text) * $size * 0.6, $size); }
            public function measureTextBox(string $text, float $breakWidth, float $size): TextMetrics { return new TextMetrics($breakWidth, $size); }
            public function addFallbackFont(string $baseFont, string $fallbackFont): void {}
            public function setGlobalAlpha(float $alpha): void {}
            public function drawArc(float $cx, float $cy, float $r, float $startAngle, float $endAngle, Color $color, int $direction = 0): void {}
            public function saveState(): void {}
            public function restoreState(): void {}
        };

        $this->ctx = new UIContext($renderer, $this->input);
    }

    public function recordDraw(string $method, array $args): void
    {
        $this->drawCalls[] = ['method' => $method, 'args' => $args];
    }

    public function testLabelRendersText(): void
    {
        $this->ctx->begin();
        $this->ctx->label('Hello');
        $this->ctx->end();

        $textCalls = array_filter($this->drawCalls, fn($c) => $c['method'] === 'drawText');
        $this->assertNotEmpty($textCalls);
        $first = array_values($textCalls)[0];
        $this->assertEquals('Hello', $first['args'][0]);
    }

    public function testButtonReturnsFalseWithoutClick(): void
    {
        $this->ctx->begin();
        $result = $this->ctx->button('btn1', 'Click me');
        $this->ctx->end();

        $this->assertFalse($result);
    }

    public function testButtonReturnsTrueOnClick(): void
    {
        // Frame 1: mouse hovers button, press occurs
        $this->input->handleCursorPosEvent(15.0, 15.0);
        $this->input->handleMouseButtonEvent(0, 1); // PRESS

        $this->ctx->begin(10.0, 10.0, 300.0);
        $this->ctx->button('btn1', 'Click me'); // sees isMouseButtonPressed → sets active
        $this->ctx->end();

        // Frame 2: release (unsuppress first since end() suppressed for hovered UI)
        $this->input->unsuppress();
        $this->input->endFrame();
        $this->input->handleCursorPosEvent(15.0, 15.0);
        $this->input->handleMouseButtonEvent(0, 0); // RELEASE

        $this->ctx->begin(10.0, 10.0, 300.0);
        $result = $this->ctx->button('btn1', 'Click me'); // sees isMouseButtonReleased + active → clicked
        $this->ctx->end();

        $this->assertTrue($result);
    }

    public function testCheckboxToggles(): void
    {
        // Place cursor inside checkbox area, simulate release (click = release while hovered)
        $this->input->handleCursorPosEvent(15.0, 15.0);
        $this->input->handleMouseButtonEvent(0, 1); // press
        $this->input->endFrame();
        $this->input->handleMouseButtonEvent(0, 0); // release

        $this->ctx->begin(10.0, 10.0, 300.0);
        $result = $this->ctx->checkbox('cb1', 'Enable', false);
        $this->ctx->end();

        $this->assertTrue($result);
    }

    public function testSliderClampsValue(): void
    {
        $this->ctx->begin();
        $val = $this->ctx->slider('sl1', 'Volume', 0.5, 0.0, 1.0);
        $this->ctx->end();

        // Without interaction, value should pass through unchanged
        $this->assertEquals(0.5, $val);
    }

    public function testTextFieldReturnsValueWithoutFocus(): void
    {
        $this->ctx->begin();
        $val = $this->ctx->textField('tf1', 'Name', 'Alice');
        $this->ctx->end();

        $this->assertEquals('Alice', $val);
    }

    public function testSeparatorDrawsRect(): void
    {
        $this->ctx->begin();
        $this->ctx->separator();
        $this->ctx->end();

        $rectCalls = array_filter($this->drawCalls, fn($c) => $c['method'] === 'drawRect');
        $this->assertNotEmpty($rectCalls);
    }

    public function testCursorAdvances(): void
    {
        $this->ctx->begin(0.0, 0.0, 200.0);
        $y1 = $this->ctx->getCursorY();
        $this->ctx->label('Line 1');
        $y2 = $this->ctx->getCursorY();
        $this->ctx->end();

        $this->assertGreaterThan($y1, $y2);
    }

    public function testAnyHoveredWhenMouseOverButton(): void
    {
        // Mouse over the button area
        $this->input->handleCursorPosEvent(15.0, 15.0);

        $this->ctx->begin(10.0, 10.0, 300.0);
        $this->ctx->button('btn1', 'Hover me');
        $this->ctx->end();

        // isAnyHovered() should be true — game code can suppress input manually
        $this->assertTrue($this->ctx->isAnyHovered());
    }

    public function testInputNotSuppressedWhenNotHovered(): void
    {
        // Mouse far from UI
        $this->input->handleCursorPosEvent(999.0, 999.0);

        $this->ctx->begin(10.0, 10.0, 100.0);
        $this->ctx->button('btn1', 'Button');
        $this->ctx->end();

        $this->assertFalse($this->input->isSuppressed());
    }

    public function testSetCursorPosition(): void
    {
        $this->ctx->begin();
        $this->ctx->setCursorPosition(50.0, 100.0);
        $this->assertEquals(100.0, $this->ctx->getCursorY());
    }

    public function testStyleAccessors(): void
    {
        $style = UIStyle::light();
        $this->ctx->setStyle($style);
        $this->assertSame($style, $this->ctx->getStyle());
    }

    public function testPanelRendersBackgroundAndTitle(): void
    {
        $this->ctx->begin();
        $this->ctx->panel('Settings', 200.0, 300.0);
        $this->ctx->end();

        $roundedCalls = array_filter($this->drawCalls, fn($c) => $c['method'] === 'drawRoundedRect');
        $this->assertGreaterThanOrEqual(2, count($roundedCalls)); // bg + title bar

        $textCalls = array_filter($this->drawCalls, fn($c) => $c['method'] === 'drawText');
        $this->assertNotEmpty($textCalls);
        $first = array_values($textCalls)[0];
        $this->assertEquals('Settings', $first['args'][0]);
    }

    public function testProgressBarRenders(): void
    {
        $this->ctx->begin();
        $this->ctx->progressBar('Loading', 0.75);
        $this->ctx->end();

        $textCalls = array_filter($this->drawCalls, fn($c) => $c['method'] === 'drawText');
        $this->assertNotEmpty($textCalls);
    }
}
