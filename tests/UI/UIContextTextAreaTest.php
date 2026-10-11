<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPolygon\Math\Mat3;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\TextMetrics;
use PHPolygon\Rendering\Texture;
use PHPolygon\Runtime\Input;
use PHPolygon\UI\TextAreaView;
use PHPolygon\UI\UIContext;
use PHPUnit\Framework\TestCase;

/**
 * Scrolling, view geometry, underlay and focus queries of
 * {@see UIContext::textArea()}.
 *
 * Geometry with the default style (font 16, padding 6) at begin(10, 10, 300):
 * field x 16, y 10, w 288; text origin (22, 16); line height 21.6; the fake
 * renderer measures 9.6 px per byte, so the 276 px inner width holds 28 chars.
 */
final class UIContextTextAreaTest extends TestCase
{
    private Input $input;
    private UIContext $ctx;

    /** @var list<array{method: string, args: array<int, mixed>}> */
    public array $calls = [];

    protected function setUp(): void
    {
        $this->input = new Input();
        $this->calls = [];
        $this->ctx = new UIContext($this->recordingRenderer(), $this->input);
    }

    private function lines(int $count): string
    {
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = "line{$i}";
        }
        return implode("\n", $out);
    }

    /** One frame with a 4-row text area; returns the value it hands back. */
    private function frame(string $value, ?\Closure $underlay = null, int $rows = 4): string
    {
        $this->calls = [];
        $this->ctx->begin(10, 10, 300);
        $value = $this->ctx->textArea('code', $value, $rows, $underlay);
        $this->ctx->end();
        $this->input->unsuppress();
        $this->input->endFrame();
        return $value;
    }

    /** @return list<string> texts drawn this frame, in order */
    private function drawnTexts(): array
    {
        $texts = [];
        foreach ($this->calls as $call) {
            if ($call['method'] === 'drawText') {
                $texts[] = (string) $call['args'][0];
            }
        }
        return $texts;
    }

    // ── focus queries (E2) ──────────────────────────────────────────

    public function testFocusedTextFieldIdIsEmptyWithoutFocus(): void
    {
        self::assertSame('', $this->ctx->focusedTextFieldId());
    }

    public function testFocusedTextFieldIdNamesTheFocusedField(): void
    {
        $this->ctx->focusTextField('console', 'x');
        self::assertSame('console', $this->ctx->focusedTextFieldId());

        $this->ctx->focusTextField('');
        self::assertSame('', $this->ctx->focusedTextFieldId());
    }

    public function testFocusCanBeRestoredWithTheCaretWhereItWas(): void
    {
        $this->ctx->focusTextField('code', "ab\ncd");
        self::assertSame(5, $this->ctx->focusedTextFieldCaret(), 'focusing without a caret puts it at the end');

        $this->ctx->focusTextField('code', "ab\ncd", 1);
        self::assertSame(1, $this->ctx->focusedTextFieldCaret());
        $this->input->handleCharEvent(ord('X'));
        self::assertSame("aXb\ncd", $this->frame("ab\ncd"));

        $this->ctx->focusTextField('code', 'abc', 99);
        self::assertSame(3, $this->ctx->focusedTextFieldCaret(), 'clamped to the text');
    }

    // ── vertical scrolling (E1) ─────────────────────────────────────

    public function testShortTextDrawsEveryLineFromTheTop(): void
    {
        $this->frame($this->lines(3));

        self::assertSame(['line1', 'line2', 'line3'], $this->drawnTexts());
        $view = $this->ctx->textAreaView('code');
        self::assertInstanceOf(TextAreaView::class, $view);
        self::assertSame(0, $view->firstRow);
        self::assertSame(4, $view->visibleRows);
        self::assertSame(3, $view->lineCount);
    }

    public function testOnlyTheVisibleWindowOfALongTextIsDrawn(): void
    {
        $this->frame($this->lines(10));

        self::assertSame(['line1', 'line2', 'line3', 'line4'], $this->drawnTexts(), 'lines below the field must not spill out');
    }

    public function testTheWindowFollowsTheCaretToTheLastLine(): void
    {
        $text = $this->lines(10);
        $this->ctx->focusTextField('code', $text); // caret at the end

        $this->frame($text);

        $view = $this->ctx->textAreaView('code');
        self::assertNotNull($view);
        self::assertSame(6, $view->firstRow, 'caret row 9 must be the last visible row');
        self::assertSame(['line7', 'line8', 'line9', 'line10'], array_slice($this->drawnTexts(), 0, 4));
    }

    public function testTheMouseWheelScrollsTheFieldAndIsClamped(): void
    {
        $text = $this->lines(10);
        $this->input->handleCursorPosEvent(50.0, 40.0); // over the field

        $this->input->handleScrollEvent(0.0, -1.0); // wheel down
        $this->frame($text);
        self::assertSame(TextAreaView::WHEEL_ROWS, $this->ctx->textAreaView('code')?->firstRow);

        $this->input->handleScrollEvent(0.0, -10.0);
        $this->frame($text);
        self::assertSame(6, $this->ctx->textAreaView('code')?->firstRow, 'never past the last full window');

        $this->input->handleScrollEvent(0.0, 10.0);
        $this->frame($text);
        self::assertSame(0, $this->ctx->textAreaView('code')?->firstRow);
    }

    public function testTheWheelDoesNotSnapBackToTheCaret(): void
    {
        $text = $this->lines(10);
        $this->ctx->focusTextField('code', $text);
        $this->frame($text); // follows the caret: firstRow 6

        $this->input->handleCursorPosEvent(50.0, 40.0);
        $this->input->handleScrollEvent(0.0, 2.0); // wheel up
        $this->frame($text);
        $scrolled = $this->ctx->textAreaView('code')?->firstRow;
        self::assertLessThan(6, $scrolled);

        $this->frame($text); // caret unchanged: the view stays where the wheel put it
        self::assertSame($scrolled, $this->ctx->textAreaView('code')?->firstRow);
    }

    public function testTypingBringsTheCaretBackIntoView(): void
    {
        $text = $this->lines(10);
        $this->ctx->focusTextField('code', $text);
        $this->frame($text);
        $this->input->handleCursorPosEvent(50.0, 40.0);
        $this->input->handleScrollEvent(0.0, 10.0);
        $text = $this->frame($text);
        self::assertSame(0, $this->ctx->textAreaView('code')?->firstRow);

        $this->input->handleCharEvent(ord('x'));
        $text = $this->frame($text);

        self::assertStringEndsWith('line10x', $text);
        self::assertSame(6, $this->ctx->textAreaView('code')?->firstRow);
    }

    public function testRevealRowScrollsAnUnfocusedField(): void
    {
        $text = $this->lines(10);
        $this->frame($text);

        $this->ctx->revealTextAreaRow('code', 8);
        $this->frame($text);

        $view = $this->ctx->textAreaView('code');
        self::assertNotNull($view);
        self::assertTrue($view->isRowVisible(8));
        self::assertFalse($view->isRowVisible(0));
    }

    // ── horizontal scrolling (E1) ───────────────────────────────────

    public function testALongLineIsCutAtTheRightEdge(): void
    {
        $long = str_repeat('a', 60);
        $this->frame($long);

        $drawn = $this->drawnTexts()[0];
        self::assertLessThanOrEqual(28, strlen($drawn), 'text must stay inside the field');
        self::assertStringStartsWith('aaa', $drawn);
    }

    public function testTheCaretAtTheEndOfALongLineScrollsHorizontally(): void
    {
        $long = str_repeat('a', 50) . 'END';
        $this->ctx->focusTextField('code', $long);
        $this->frame($long);

        $view = $this->ctx->textAreaView('code');
        self::assertNotNull($view);
        self::assertGreaterThan(0.0, $view->scrollX);
        $drawn = $this->drawnTexts()[0];
        self::assertStringEndsWith('END', $drawn);
        self::assertLessThanOrEqual(28, strlen($drawn));

        // The drawn slice starts at a glyph boundary left of or at the field's text origin.
        $first = $this->firstCall('drawText');
        self::assertGreaterThanOrEqual($view->textX - 0.001, (float) $first['args'][1]);
    }

    public function testShiftWheelScrollsHorizontally(): void
    {
        $long = str_repeat('b', 80);
        $this->input->handleCursorPosEvent(50.0, 40.0);
        $this->input->handleKeyEvent(340, 1); // left shift held
        $this->input->handleScrollEvent(0.0, -1.0);
        $this->frame($long);

        self::assertGreaterThan(0.0, $this->ctx->textAreaView('code')?->scrollX);
    }

    public function testASingleLineFieldScrollsSidewaysWithTheCaret(): void
    {
        $long = str_repeat('c', 40) . 'TAIL';
        $this->ctx->focusTextField('one', $long);
        $this->calls = [];
        $this->ctx->begin(10, 10, 300);
        $this->ctx->textField('one', '', $long);
        $this->ctx->end();

        $drawn = $this->drawnTexts()[0];
        self::assertStringEndsWith('TAIL', $drawn);
        self::assertLessThanOrEqual(28, strlen($drawn));
    }

    // ── click places the caret ──────────────────────────────────────

    public function testAClickPlacesTheCaretAtTheClickedRowAndColumn(): void
    {
        $text = "hello\nworld";
        // Row 1 ("world"), between 'w','o' and 'r': x = 22 + 2.4 * 9.6.
        $this->input->handleCursorPosEvent(22.0 + 2.4 * 9.6, 16.0 + 21.6 + 5.0);
        $this->input->handleMouseButtonEvent(0, 1);
        $this->frame($text);
        $this->input->handleCursorPosEvent(22.0 + 2.4 * 9.6, 16.0 + 21.6 + 5.0);
        $this->input->handleMouseButtonEvent(0, 0);
        $this->frame($text);

        self::assertSame('code', $this->ctx->focusedTextFieldId());
        $this->input->handleCharEvent(ord('X'));
        self::assertSame("hello\nwoXrld", $this->frame($text));
    }

    // ── underlay (E3) ───────────────────────────────────────────────

    public function testTheUnderlayIsDrawnAfterTheBackgroundAndBeforeTheText(): void
    {
        $seen = null;
        $this->frame($this->lines(3), function (TextAreaView $view) use (&$seen): void {
            $seen = $view;
            $this->calls[] = ['method' => 'underlay', 'args' => []];
        });

        self::assertInstanceOf(TextAreaView::class, $seen);
        $order = array_column($this->calls, 'method');
        $underlay = array_search('underlay', $order, true);
        $background = array_search('drawRoundedRect', $order, true);
        $text = array_search('drawText', $order, true);
        self::assertNotFalse($underlay);
        self::assertLessThan($underlay, $background);
        self::assertGreaterThan($underlay, $text);
    }

    public function testTheViewMapsRowsToScreenY(): void
    {
        $this->frame($this->lines(3));
        $view = $this->ctx->textAreaView('code');
        self::assertNotNull($view);

        self::assertEqualsWithDelta(16.0, $view->rowY(0), 0.001);
        self::assertEqualsWithDelta(16.0 + 2 * 21.6, $view->rowY(2), 0.001);
        self::assertEqualsWithDelta(16.0, $view->x, 0.001);
        self::assertEqualsWithDelta(22.0, $view->textX, 0.001);
    }

    // ── helpers ─────────────────────────────────────────────────────

    /** @return array{method: string, args: array<int, mixed>} */
    private function firstCall(string $method): array
    {
        foreach ($this->calls as $call) {
            if ($call['method'] === $method) {
                return $call;
            }
        }
        self::fail("no {$method} call");
    }

    private function recordingRenderer(): Renderer2DInterface
    {
        $test = $this;
        return new class($test) implements Renderer2DInterface {
            public function __construct(private readonly UIContextTextAreaTest $test) {}
            /** @param array<int, mixed> $args */
            private function record(string $method, array $args): void { $this->test->calls[] = ['method' => $method, 'args' => $args]; }
            public function beginFrame(): void {}
            public function endFrame(): void {}
            public function clearFallbackFonts(?string $baseFont = null): void {}
            public function clear(Color $color): void {}
            public function setViewport(int $x, int $y, int $width, int $height): void {}
            public function getWidth(): int { return 1280; }
            public function getHeight(): int { return 720; }
            public function drawRect(float $x, float $y, float $w, float $h, Color $color): void { $this->record('drawRect', func_get_args()); }
            public function drawRectOutline(float $x, float $y, float $w, float $h, Color $color, float $lineWidth = 1.0): void { $this->record('drawRectOutline', func_get_args()); }
            public function drawRoundedRect(float $x, float $y, float $w, float $h, float $radius, Color $color): void { $this->record('drawRoundedRect', func_get_args()); }
            public function drawRoundedRectOutline(float $x, float $y, float $w, float $h, float $radius, Color $color, float $lineWidth = 1.0): void {}
            public function drawCircle(float $cx, float $cy, float $r, Color $color): void {}
            public function drawCircleOutline(float $cx, float $cy, float $r, Color $color, float $lineWidth = 1.0): void {}
            public function drawLine(Vec2 $from, Vec2 $to, Color $color, float $width = 1.0): void { $this->record('drawLine', func_get_args()); }
            public function drawText(string $text, float $x, float $y, float $size, Color $color): void { $this->record('drawText', func_get_args()); }
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
    }
}
