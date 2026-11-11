<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPUnit\Framework\TestCase;
use PHPolygon\Log\Log;
use PHPolygon\Math\Mat3;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextMetrics;
use PHPolygon\Rendering\Texture;
use PHPolygon\Runtime\Input;
use PHPolygon\Tests\Log\RecordingLogWriter;
use PHPolygon\UI\UIContext;

/**
 * The `[ui]` automatic hooks (click, checkbox, slider release, text commit).
 * Dropdown selection and the typing throttle share the same {@see Log::$hooks}
 * gate and are covered by code review + manual runs, not a dedicated test
 * here (same renderer/input fixture cost for one more interaction each).
 */
final class UIContextLoggingTest extends TestCase
{
    private Input $input;
    private UIContext $ctx;
    private RecordingLogWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = new Input();
        $this->writer = new RecordingLogWriter();
        Log::startWithWriter($this->writer, hooks: true);

        $renderer = new class implements Renderer2DInterface {
            public function beginFrame(): void {}
            public function endFrame(): void {}
            public function clearFallbackFonts(?string $baseFont = null): void {}
            public function clear(Color $color): void {}
            public function setViewport(int $x, int $y, int $width, int $height): void {}
            public function getWidth(): int { return 1280; }
            public function getHeight(): int { return 720; }
            public function drawRect(float $x, float $y, float $w, float $h, Color $color): void {}
            public function drawRectOutline(float $x, float $y, float $w, float $h, Color $color, float $lineWidth = 1.0): void {}
            public function drawRoundedRect(float $x, float $y, float $w, float $h, float $radius, Color $color): void {}
            public function drawRoundedRectOutline(float $x, float $y, float $w, float $h, float $radius, Color $color, float $lineWidth = 1.0): void {}
            public function drawCircle(float $cx, float $cy, float $r, Color $color): void {}
            public function drawCircleOutline(float $cx, float $cy, float $r, Color $color, float $lineWidth = 1.0): void {}
            public function drawLine(Vec2 $from, Vec2 $to, Color $color, float $width = 1.0): void {}
            public function drawText(string $text, float $x, float $y, float $size, Color $color): void {}
            public function drawTextCentered(string $text, float $cx, float $cy, float $size, Color $color): void {}
            public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, Color $color): void {}
            public function drawSprite(Texture $texture, ?Rect $srcRegion, float $x, float $y, float $w, float $h, float $opacity = 1.0): void {}
            public function pushTransform(Mat3 $matrix): void {}
            public function popTransform(): void {}
            public function pushScissor(float $x, float $y, float $w, float $h): void {}
            public function popScissor(): void {}
            public function loadFont(string $name, string $path): void {}
            public function preloadFontAsync(string $name, string $path): void {}
            public function setFont(string $name): void {}
            public function setFontRenderScale(float $scale): void {}
            public function setTextAlign(int $align): void {}
            public function measureText(string $text, float $size): TextMetrics { return new TextMetrics(strlen($text) * $size * 0.6, $size); }
            public function measureTextBox(string $text, float $breakWidth, float $size): TextMetrics { return new TextMetrics($breakWidth, $size); }
            public function fontCoversScript(string $font, \PHPolygon\Rendering\Script $script): bool { return true; }
            public function fontForScript(\PHPolygon\Rendering\Script $script, array $candidates): ?string { return $candidates[0] ?? null; }
            public function addFallbackFont(string $baseFont, string $fallbackFont): void {}
            public function setGlobalAlpha(float $alpha): void {}
            public function drawArc(float $cx, float $cy, float $r, float $startAngle, float $endAngle, Color $color, int $direction = 0): void {}
            public function saveState(): void {}
            public function restoreState(): void {}
            public function beginOffscreenFrame(int $width, int $height): void {}
            public function endOffscreenFrame(): void {}
        };

        $this->ctx = new UIContext($renderer, $this->input);
    }

    protected function tearDown(): void
    {
        Log::reset();
        parent::tearDown();
    }

    public function testButtonClickIsLogged(): void
    {
        $this->input->handleCursorPosEvent(15.0, 15.0);
        $this->input->handleMouseButtonEvent(0, 1); // press
        $this->ctx->begin(10.0, 10.0, 300.0);
        $this->ctx->button('opt_benchmark_start', 'Benchmark starten');
        $this->ctx->end();

        $this->input->unsuppress();
        $this->input->endFrame();
        $this->input->handleCursorPosEvent(15.0, 15.0);
        $this->input->handleMouseButtonEvent(0, 0); // release
        $this->ctx->begin(10.0, 10.0, 300.0);
        $this->ctx->button('opt_benchmark_start', 'Benchmark starten');
        $this->ctx->end();

        $log = $this->writer->all();
        $this->assertStringContainsString('[ui] click opt_benchmark_start "Benchmark starten"', $log);
    }

    public function testHooksOffLogsNoClick(): void
    {
        Log::reset();
        $silentWriter = new RecordingLogWriter();
        Log::startWithWriter($silentWriter, hooks: false);

        $this->input->handleCursorPosEvent(15.0, 15.0);
        $this->input->handleMouseButtonEvent(0, 1);
        $this->ctx->begin(10.0, 10.0, 300.0);
        $this->ctx->button('btn', 'Click');
        $this->ctx->end();
        $this->input->unsuppress();
        $this->input->endFrame();
        $this->input->handleMouseButtonEvent(0, 0);
        $this->ctx->begin(10.0, 10.0, 300.0);
        $this->ctx->button('btn', 'Click');
        $this->ctx->end();

        $this->assertSame([], $silentWriter->writes);
    }

    public function testCheckboxToggleIsLoggedWithTheNewValue(): void
    {
        $this->input->handleCursorPosEvent(15.0, 15.0);
        $this->input->handleMouseButtonEvent(0, 1);
        $this->input->endFrame();
        $this->input->handleMouseButtonEvent(0, 0);

        $this->ctx->begin(10.0, 10.0, 300.0);
        $this->ctx->checkbox('opt_vsync', 'VSync', false);
        $this->ctx->end();

        $log = $this->writer->all();
        $this->assertStringContainsString('[ui] checkbox opt_vsync VSync value=true', $log);
    }

    public function testSliderLogsOnlyOnReleaseNotWhileDragging(): void
    {
        // Label ("16px font + padding") sits above the bar - the bar itself
        // is the next row, at y 38..46 for this style/region.
        $barY = 42.0;

        // Frame 1: press on the bar - activates the slider, no log yet.
        $this->input->handleCursorPosEvent(60.0, $barY);
        $this->input->handleMouseButtonEvent(0, 1);
        $this->ctx->begin(10.0, 10.0, 300.0);
        $value = $this->ctx->slider('sl1', 'Volume', 0.2, 0.0, 1.0);
        $this->ctx->end();
        $this->assertStringNotContainsString('slider release', $this->writer->all());

        // Frame 2: still held, dragged further - no log yet either.
        $this->input->unsuppress();
        $this->input->endFrame();
        $this->input->handleCursorPosEvent(120.0, $barY);
        $this->ctx->begin(10.0, 10.0, 300.0);
        $value = $this->ctx->slider('sl1', 'Volume', $value, 0.0, 1.0);
        $this->ctx->end();
        $this->assertStringNotContainsString('slider release', $this->writer->all());

        // Frame 3: release - exactly one log line, with the slider's value.
        $this->input->unsuppress();
        $this->input->endFrame();
        $this->input->handleMouseButtonEvent(0, 0);
        $this->ctx->begin(10.0, 10.0, 300.0);
        $this->ctx->slider('sl1', 'Volume', $value, 0.0, 1.0);
        $this->ctx->end();

        $this->assertSame(1, substr_count($this->writer->all(), 'slider release'));
        $this->assertStringContainsString('[ui] slider release sl1 Volume value=', $this->writer->all());
    }

    public function testTextFieldCommitIsLoggedOnBlurWithTheFullText(): void
    {
        // Frame 1: click into the field, focuses it.
        $this->input->handleCursorPosEvent(20.0, 20.0);
        $this->input->handleMouseButtonEvent(0, 1);
        $this->input->endFrame();
        $this->input->handleMouseButtonEvent(0, 0);
        $this->ctx->begin(10.0, 10.0, 300.0);
        $value = $this->ctx->textField('name_field', '', 'old');
        $this->ctx->end();

        // Frame 2: type a character while focused - no commit line yet (only
        // the throttled "typing" line, at most once per second, may appear).
        $this->input->unsuppress();
        $this->input->endFrame();
        $this->input->handleCharEvent(0x21); // '!'
        $this->ctx->begin(10.0, 10.0, 300.0);
        $value = $this->ctx->textField('name_field', '', $value);
        $this->ctx->end();
        $this->assertStringNotContainsString('text commit', $this->writer->all());

        // Frame 3: click a different field - blurs name_field, commits its text.
        $this->input->unsuppress();
        $this->input->endFrame();
        $this->input->handleCursorPosEvent(20.0, 90.0);
        $this->input->handleMouseButtonEvent(0, 1);
        $this->input->endFrame();
        $this->input->handleMouseButtonEvent(0, 0);
        $this->ctx->begin(10.0, 10.0, 300.0);
        $this->ctx->textField('name_field', '', $value);
        $this->ctx->textField('other_field', '', '');
        $this->ctx->end();

        $log = $this->writer->all();
        $this->assertStringContainsString('[ui] text commit name_field text=', $log);
        $this->assertStringContainsString($value, $log);
    }
}
