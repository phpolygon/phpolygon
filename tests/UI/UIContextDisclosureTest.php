<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPolygon\Rendering\Color;
use PHPolygon\Runtime\Input;
use PHPolygon\UI\UIContext;
use PHPolygon\UI\UIStyle;
use PHPUnit\Framework\TestCase;

/**
 * disclosure(): click frame, chevron direction, states, wrapping and measuring.
 * With size 10 and padding 5 a row is 20 high, a text line 12 (see
 * RecordingRenderer2D).
 */
final class UIContextDisclosureTest extends TestCase
{
    private Input $input;
    private RecordingRenderer2D $renderer;
    private UIContext $ui;
    private UIStyle $style;

    protected function setUp(): void
    {
        $this->input = new Input();
        $this->renderer = new RecordingRenderer2D(1280, 720);
        $this->style = new UIStyle(fontSize: 10.0, padding: 5.0, fontName: 'base');
        $this->ui = new UIContext($this->renderer, $this->input, $this->style);
    }

    private function draw(string $label = 'Head', bool $open = true, float $width = 200.0): bool
    {
        $this->ui->begin(10.0, 20.0, $width);
        $clicked = $this->ui->disclosure('d', $label, $open);
        $this->ui->end();

        return $clicked;
    }

    private function nextFrame(): void
    {
        $this->input->unsuppress();
        $this->input->endFrame();
    }

    public function testItReportsTheClickOnTheReleaseFrameOnly(): void
    {
        $this->input->handleCursorPosEvent(50.0, 25.0);
        $this->input->handleMouseButtonEvent(0, 1);
        self::assertFalse($this->draw(), 'the press alone does nothing');

        $this->nextFrame();
        $this->input->handleCursorPosEvent(50.0, 25.0);
        $this->input->handleMouseButtonEvent(0, 0);
        self::assertTrue($this->draw());

        $this->nextFrame();
        self::assertFalse($this->draw(), 'one click, one frame');
    }

    public function testThePressMustBeginOnIt(): void
    {
        $this->input->handleCursorPosEvent(50.0, 300.0);
        $this->input->handleMouseButtonEvent(0, 1);
        $this->draw();

        $this->nextFrame();
        $this->input->handleCursorPosEvent(50.0, 25.0);
        $this->input->handleMouseButtonEvent(0, 0);

        self::assertFalse($this->draw());
    }

    public function testTheWholeRegionWidthIsClickable(): void
    {
        $this->input->handleCursorPosEvent(205.0, 25.0);
        $this->draw();

        self::assertSame('d', $this->ui->hoveredWidgetId());
    }

    public function testChevronPointsDownOpenAndUpClosed(): void
    {
        $this->draw(open: true);
        [$left, $right] = $this->renderer->lines;
        self::assertGreaterThan($left['from']->y, $left['to']->y, 'tip below the ends');
        self::assertSame($left['to']->x, $right['from']->x, 'two strokes meet at the tip');
        self::assertSame(10.0 + 200.0 - 12.0, $left['to']->x);
        self::assertSame(20.0 + 10.0 + 3.0, $left['to']->y, 'in the middle of the row');

        $this->renderer->reset();
        $this->draw(open: false);
        self::assertLessThan($this->renderer->lines[0]['from']->y, $this->renderer->lines[0]['to']->y, 'tip above the ends');
    }

    public function testNoBackgroundAtRestHoverColourUnderTheMouse(): void
    {
        $this->draw();
        self::assertSame([], $this->renderer->fills, 'the plate behind it shows');

        $this->input->handleCursorPosEvent(50.0, 25.0);
        $this->draw();
        self::assertCount(1, $this->renderer->fills);
        self::assertSame($this->style->hoverColor, $this->renderer->fills[0]['color']);
        self::assertSame(200.0, $this->renderer->fills[0]['w']);
    }

    public function testChevronBrightensUnderTheMouse(): void
    {
        $dim = new Color(0.5, 0.5, 0.5, 1.0);
        $frame = function () use ($dim): void {
            $this->ui->begin(10.0, 20.0, 200.0);
            $this->ui->disclosure('d', 'Head', true, null, '', $dim);
            $this->ui->end();
        };

        $frame();
        self::assertSame($dim, $this->renderer->lines[0]['color']);

        $this->renderer->reset();
        $this->input->handleCursorPosEvent(50.0, 25.0);
        $frame();
        self::assertSame($this->style->textColor, $this->renderer->lines[0]['color']);
    }

    public function testLabelColourAndFontApplyAndTheStyleFontReturns(): void
    {
        $gold = new Color(1.0, 0.8, 0.2, 1.0);
        $this->ui->begin(10.0, 20.0, 200.0);
        $this->ui->disclosure('d', 'Head', true, $gold, 'bold');
        $this->ui->end();

        $box = $this->renderer->box('Head');
        self::assertSame($gold, $box['color']);
        self::assertSame('bold', $box['font']);
        self::assertSame('base', $this->renderer->font);
        self::assertSame(15.0, $box['x']);
        self::assertSame(20.0 + (20.0 - 12.0) * 0.5, $box['y'], 'one line centred in the row');
    }

    public function testAOneLineLabelIsExactlyOneRow(): void
    {
        $this->ui->begin(0.0, 0.0, 200.0);
        $this->ui->disclosure('d', 'Head', true);
        $y = $this->ui->getCursorY();
        $this->ui->end();

        self::assertSame($this->ui->rowHeight(), $this->ui->disclosureHeight('Head', 200.0));
        self::assertSame($this->ui->rowHeight() + $this->style->itemSpacing, $y, 'advances like a button');
    }

    public function testALongLabelWrapsAroundTheChevronAndGrows(): void
    {
        // Room 200 - 5 - 24 = 171 px = 28 characters.
        $label = 'A rather long heading that has to wrap somewhere';
        $this->draw($label);

        $text = $this->renderer->boxes[0]['text'];
        self::assertStringContainsString("\n", $text);
        self::assertSame($label, str_replace("\n", ' ', $text), 'nothing cut');
        foreach (explode("\n", $text) as $line) {
            self::assertLessThanOrEqual(171.0, strlen($line) * 6.0);
        }
        self::assertSame(20.0 + 12.0, $this->ui->disclosureHeight($label, 200.0), 'one more line');
    }

    public function testMeasuringMatchesTheCursorAtEveryWidth(): void
    {
        $label = 'A rather long heading that has to wrap somewhere';
        foreach ([60.0, 90.0, 140.0, 200.0, 400.0] as $width) {
            $this->ui->begin(0.0, 0.0, $width);
            $this->ui->disclosure('d', $label, true);
            $y = $this->ui->getCursorY();
            $this->ui->end();

            self::assertSame($this->ui->disclosureHeight($label, $width) + $this->style->itemSpacing, $y, "width $width");
        }
    }

    public function testAWordWiderThanTheRoomIsSplitNotCut(): void
    {
        $word = str_repeat('x', 40);
        $this->draw($word, width: 100.0);

        $text = $this->renderer->boxes[0]['text'];
        self::assertSame($word, str_replace("\n", '', $text));
        self::assertGreaterThan(1, substr_count($text, "\n"));
    }

    public function testTheRegionWidthIsTheDefaultForMeasuring(): void
    {
        $label = 'A rather long heading that has to wrap somewhere';
        $this->ui->begin(0.0, 0.0, 120.0);
        $height = $this->ui->disclosureHeight($label);
        $this->ui->end();

        self::assertSame($this->ui->disclosureHeight($label, 120.0), $height);
    }

    public function testMeasuringHappensOnce(): void
    {
        $this->draw();
        $measures = $this->renderer->measures;
        $this->draw();
        $this->ui->disclosureHeight('Head', 200.0);

        self::assertSame($measures, $this->renderer->measures);
    }

    public function testNonInteractiveDrawsButDoesNotRespond(): void
    {
        $this->ui->setInteractive(false);
        $this->input->handleCursorPosEvent(50.0, 25.0);
        $this->input->handleMouseButtonEvent(0, 0);

        self::assertFalse($this->draw());
        self::assertSame([], $this->renderer->fills);
        self::assertCount(2, $this->renderer->lines);
    }

    public function testScrolledOutOfARegionItDoesNotRespond(): void
    {
        $this->ui->setScrollOffset('r', 100.0);
        $this->input->handleCursorPosEvent(50.0, 25.0);
        $this->ui->beginScroll('r', 10.0, 120.0, 200.0, 100.0, 300.0);
        // Drawn at y 20..40, the region starts at 120.
        $this->ui->disclosure('d', 'Head', true);
        $this->ui->endScroll();

        self::assertSame('', $this->ui->hoveredWidgetId());
        self::assertSame([], array_filter($this->renderer->fills, fn(array $f): bool => $f['color'] === $this->style->hoverColor));
    }

    public function testTextFieldFocusIsNotTouched(): void
    {
        $this->ui->focusTextField('code', 'text', 2);
        $this->input->handleCursorPosEvent(50.0, 25.0);
        $this->input->handleMouseButtonEvent(0, 1);
        $this->draw();
        $this->nextFrame();
        $this->input->handleCursorPosEvent(50.0, 25.0);
        $this->input->handleMouseButtonEvent(0, 0);
        $this->draw();

        self::assertSame('code', $this->ui->focusedTextFieldId());
        self::assertSame(2, $this->ui->focusedTextFieldCaret());
    }
}
