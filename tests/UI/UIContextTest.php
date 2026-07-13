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
            public function clearFallbackFonts(?string $baseFont = null): void {}
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
            public function pushScissor(float $x, float $y, float $w, float $h): void { $this->record('pushScissor', func_get_args()); }
            public function popScissor(): void { $this->record('popScissor', []); }
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

    // ── dropdown open-state + upward flip near the viewport bottom ──

    public function testHasOpenDropdownDefaultsFalse(): void
    {
        $this->assertFalse($this->ctx->hasOpenDropdown());
    }

    public function testHasOpenDropdownIsTrueAfterOpening(): void
    {
        $this->openDropdownNearBottom(680.0);
        $this->assertTrue($this->ctx->hasOpenDropdown());
    }

    public function testDropdownFlipsUpWhenItWouldOverflowTheViewportBottom(): void
    {
        $this->ctx->setViewportHeight(720.0);
        $fieldY = 680.0;
        $listY = $this->openDropdownAndCaptureListY($fieldY);
        $this->assertLessThan($fieldY, $listY,
            'a dropdown near the bottom must open its option list upward, not off-screen');
    }

    public function testDropdownOpensDownwardWithoutAKnownViewportHeight(): void
    {
        // No setViewportHeight() → legacy behaviour: the list opens below the field.
        $fieldY = 680.0;
        $listY = $this->openDropdownAndCaptureListY($fieldY);
        $this->assertGreaterThan($fieldY, $listY,
            'without a known viewport height the list opens downward as before');
    }

    /** Open a 10-option dropdown whose field sits at $fieldY (press + release). */
    private function openDropdownNearBottom(float $fieldY): void
    {
        $options = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'];
        $fy = $fieldY + 5.0; // cursor inside the field row

        // Frame 1: press over the field.
        $this->input->handleCursorPosEvent(120.0, $fy);
        $this->input->handleMouseButtonEvent(0, 1);
        $this->ctx->begin(100.0, $fieldY, 200.0);
        $this->ctx->dropdown('dd', $options, 0, 180.0, 8);
        $this->ctx->end();

        // Frame 2: release over the field → toggles the dropdown open.
        $this->input->unsuppress();
        $this->input->endFrame();
        $this->input->handleCursorPosEvent(120.0, $fy);
        $this->input->handleMouseButtonEvent(0, 0);
        $this->ctx->begin(100.0, $fieldY, 200.0);
        $this->ctx->dropdown('dd', $options, 0, 180.0, 8);
        $this->ctx->end();
    }

    /** Open the dropdown, render one more frame with it open, and return the Y of
     *  the option-list background (the tall rounded rect drawn by the overlay). */
    private function openDropdownAndCaptureListY(float $fieldY): float
    {
        $this->openDropdownNearBottom($fieldY);

        // Frame 3: list is open → the overlay is deferred; flush + capture.
        $this->input->unsuppress();
        $this->input->endFrame();
        $this->input->handleCursorPosEvent(120.0, $fieldY + 5.0);
        $this->drawCalls = [];
        $this->ctx->begin(100.0, $fieldY, 200.0);
        $this->ctx->dropdown('dd', ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j'], 0, 180.0, 8);
        $this->ctx->end();
        $this->ctx->flushOverlays();

        // The list background is the tall rounded rect (height >> a single row).
        $listYs = [];
        foreach ($this->drawCalls as $c) {
            if ($c['method'] === 'drawRoundedRect' && (float) $c['args'][3] > 100.0) {
                $listYs[] = (float) $c['args'][1];
            }
        }
        $this->assertNotEmpty($listYs, 'the open dropdown should draw its option-list background');
        return $listYs[0];
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

    public function testPressAndReleaseOnSameButtonFiresExactlyOnce(): void
    {
        // Frame 1: press over button A's rect — must NOT fire yet.
        $this->input->handleCursorPosEvent(15.0, 15.0);
        $this->input->handleMouseButtonEvent(0, 1); // PRESS edge

        $this->ctx->begin(10.0, 10.0, 300.0);
        $pressFrame = $this->ctx->button('A', 'A');
        $this->ctx->end();

        $this->assertFalse($pressFrame, 'button must not fire on the press frame');

        // Frame 2: release over A's rect — must fire exactly once.
        $this->input->unsuppress();
        $this->input->endFrame();
        $this->input->handleCursorPosEvent(15.0, 15.0);
        $this->input->handleMouseButtonEvent(0, 0); // RELEASE edge

        $this->ctx->begin(10.0, 10.0, 300.0);
        $releaseFrame = $this->ctx->button('A', 'A');
        $this->ctx->end();

        $this->assertTrue($releaseFrame, 'button fires once on release when press began on it');
    }

    public function testPressOnAReleaseOverBDoesNotFireB(): void
    {
        // The ghost-click bug (game issue #181): press starts on button A (drawn
        // first), cursor moves over button B (drawn later) before release. B must
        // NOT fire, because the press never began on B.
        //
        // Two buttons stacked vertically in the same region:
        //   A occupies roughly y=10..~30 (fontSize+padding*2 tall)
        //   B is drawn right after A, lower on screen.

        // Frame 1: press while hovering A's rect.
        $this->input->handleCursorPosEvent(15.0, 15.0); // over A
        $this->input->handleMouseButtonEvent(0, 1); // PRESS edge

        $this->ctx->begin(10.0, 10.0, 300.0);
        $aPress = $this->ctx->button('A', 'A');
        $bYTop = $this->ctx->getCursorY(); // top of B after A advanced
        $bPress = $this->ctx->button('B', 'B');
        $this->ctx->end();

        $this->assertFalse($aPress, 'A must not fire on press frame');
        $this->assertFalse($bPress, 'B must not fire on press frame');

        // Frame 2: move cursor over B's rect and release there.
        $this->input->unsuppress();
        $this->input->endFrame();
        $bCenterY = $bYTop + 10.0; // inside B's rect
        $this->input->handleCursorPosEvent(15.0, $bCenterY); // now over B, not A
        $this->input->handleMouseButtonEvent(0, 0); // RELEASE edge

        $this->ctx->begin(10.0, 10.0, 300.0);
        $aRelease = $this->ctx->button('A', 'A');
        $bRelease = $this->ctx->button('B', 'B');
        $this->ctx->end();

        $this->assertFalse($bRelease, 'B must NOT fire: press began on A, not B (issue #181)');
        $this->assertFalse($aRelease, 'A must NOT fire: release was not over A');
    }

    public function testPlainPressReleaseStillFires(): void
    {
        // Guard against over-restriction: a normal press+release on a single
        // button must still fire.
        $this->input->handleCursorPosEvent(15.0, 15.0);
        $this->input->handleMouseButtonEvent(0, 1); // PRESS
        $this->ctx->begin(10.0, 10.0, 300.0);
        $this->ctx->button('only', 'Only');
        $this->ctx->end();

        $this->input->unsuppress();
        $this->input->endFrame();
        $this->input->handleCursorPosEvent(15.0, 15.0);
        $this->input->handleMouseButtonEvent(0, 0); // RELEASE

        $this->ctx->begin(10.0, 10.0, 300.0);
        $result = $this->ctx->button('only', 'Only');
        $this->ctx->end();

        $this->assertTrue($result, 'plain press+release on one button still fires');
    }

    public function testSetInteractiveBlocksClicks(): void
    {
        // Same setup as testButtonReturnsTrueOnClick — but interactive=false on
        // the release frame should drop the click on the floor.
        $this->input->handleCursorPosEvent(15.0, 15.0);
        $this->input->handleMouseButtonEvent(0, 1); // PRESS
        $this->ctx->begin(10.0, 10.0, 300.0);
        $this->ctx->button('btn1', 'Click me');
        $this->ctx->end();

        $this->input->unsuppress();
        $this->input->endFrame();
        $this->input->handleCursorPosEvent(15.0, 15.0);
        $this->input->handleMouseButtonEvent(0, 0); // RELEASE

        $this->ctx->setInteractive(false);
        $this->ctx->begin(10.0, 10.0, 300.0);
        $result = $this->ctx->button('btn1', 'Click me');
        $this->ctx->end();

        $this->assertFalse($result, 'click must be blocked when interactive=false');
    }

    public function testSetInteractiveDefaultsTrue(): void
    {
        $this->assertTrue($this->ctx->isInteractive());
    }

    public function testSetInteractiveRoundtrip(): void
    {
        $this->ctx->setInteractive(false);
        $this->assertFalse($this->ctx->isInteractive());

        $this->ctx->setInteractive(true);
        $this->assertTrue($this->ctx->isInteractive());
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

    public function testBeginScrollPushesAndEndScrollPopsScissor(): void
    {
        $this->ctx->beginScroll('sc1', 10.0, 20.0, 300.0, 200.0, 500.0);
        $this->ctx->label('Item');
        $this->ctx->endScroll();

        $push = array_values(array_filter($this->drawCalls, fn($c) => $c['method'] === 'pushScissor'));
        $pop = array_values(array_filter($this->drawCalls, fn($c) => $c['method'] === 'popScissor'));
        $this->assertCount(1, $push, 'beginScroll pushes exactly one scissor');
        $this->assertCount(1, $pop, 'endScroll pops exactly one scissor');
        // Clip rect matches the region (offset 0, scale 1).
        $this->assertEquals([10.0, 20.0, 300.0, 200.0], $push[0]['args']);
    }

    public function testScrollOffsetClampsToContentMinusHeight(): void
    {
        // Hover the region and wheel down hard; offset must clamp to maxOffset.
        $this->input->handleCursorPosEvent(100.0, 100.0);
        $this->input->handleScrollEvent(0.0, -100.0); // big downward wheel

        $this->ctx->beginScroll('sc2', 10.0, 20.0, 300.0, 200.0, 500.0);
        // maxOffset = 500 - 200 = 300. Content drawn at y = 20 - offset.
        $drawnY = $this->ctx->getCursorY();
        $this->ctx->endScroll();

        // Cursor started at regionY (20) minus clamped offset (300) => -280.
        $this->assertEqualsWithDelta(20.0 - 300.0, $drawnY, 0.001);
    }

    public function testScrollOffsetNeverNegative(): void
    {
        // Wheel up while already at the top must not push offset below zero.
        $this->input->handleCursorPosEvent(100.0, 100.0);
        $this->input->handleScrollEvent(0.0, 100.0); // big upward wheel

        $this->ctx->beginScroll('sc3', 10.0, 20.0, 300.0, 200.0, 500.0);
        $drawnY = $this->ctx->getCursorY();
        $this->ctx->endScroll();

        // Offset clamped to 0 → content draws at the region's top (y = 20).
        $this->assertEqualsWithDelta(20.0, $drawnY, 0.001);
    }

    public function testNoScrollbarWhenContentFits(): void
    {
        // contentHeight <= height → no overflow, no scrollbar thumb drawn.
        $before = count($this->drawCalls);
        $this->ctx->beginScroll('sc4', 10.0, 20.0, 300.0, 200.0, 150.0);
        $this->ctx->endScroll();

        $rounded = array_filter(
            array_slice($this->drawCalls, $before),
            fn($c) => $c['method'] === 'drawRoundedRect',
        );
        $this->assertCount(0, $rounded, 'no scrollbar thumb when content fits');
    }

    public function testScrollbarDrawnWhenContentOverflows(): void
    {
        $before = count($this->drawCalls);
        $this->ctx->beginScroll('sc5', 10.0, 20.0, 300.0, 200.0, 800.0);
        $this->ctx->endScroll();

        $rounded = array_filter(
            array_slice($this->drawCalls, $before),
            fn($c) => $c['method'] === 'drawRoundedRect',
        );
        $this->assertNotEmpty($rounded, 'scrollbar thumb drawn when content overflows');
    }
}
