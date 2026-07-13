<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Anchor;
use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\Checkbox;
use PHPolygon\UI\Widget\Dropdown;
use PHPolygon\UI\Widget\EdgeInsets;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Panel;
use PHPolygon\UI\Widget\ProgressBar;
use PHPolygon\UI\Widget\Sizing;
use PHPolygon\UI\Widget\Slider;
use PHPolygon\UI\Widget\Stack;
use PHPolygon\UI\Widget\TextInput;
use PHPolygon\UI\Widget\Toggle;
use PHPolygon\UI\Widget\VBox;

class WidgetTest extends TestCase
{
    private UIStyle $style;
    private WidgetTestHelper $renderer;

    protected function setUp(): void
    {
        $this->style = UIStyle::dark();
        $this->renderer = new WidgetTestHelper();
    }

    // ── Label ────────────────────────────────────────────────────

    public function testLabelMeasure(): void
    {
        $label = new Label('Hello');
        $label->measure(400, 400, $this->style);

        $this->assertGreaterThan(0.0, $label->getMeasuredWidth());
        $this->assertGreaterThan(0.0, $label->getMeasuredHeight());
    }

    public function testLabelDraw(): void
    {
        $label = new Label('Test');
        $label->setBounds(new Rect(10, 20, 100, 20));
        $label->draw($this->renderer, $this->style);

        $textCalls = array_filter($this->renderer->calls, fn($c) => $c['method'] === 'drawText');
        $this->assertNotEmpty($textCalls);
        $this->assertEquals('Test', array_values($textCalls)[0]['args'][0]);
    }

    public function testLabelCustomFontSize(): void
    {
        $label = new Label('Big');
        $label->fontSize = 32.0;
        $label->measure(400, 400, $this->style);

        // With 32px font, width should be larger than default 16px
        $defaultLabel = new Label('Big');
        $defaultLabel->measure(400, 400, $this->style);

        $this->assertGreaterThan($defaultLabel->getMeasuredWidth(), $label->getMeasuredWidth());
    }

    // ── Button ───────────────────────────────────────────────────

    public function testButtonMeasure(): void
    {
        $btn = new Button('Click');
        $btn->measure(400, 400, $this->style);

        $this->assertGreaterThan(0.0, $btn->getMeasuredWidth());
    }

    public function testButtonDrawStates(): void
    {
        $btn = new Button('OK');
        $btn->setBounds(new Rect(0, 0, 100, 30));

        $rounded = fn () => array_filter($this->renderer->calls, fn ($c) => $c['method'] === 'drawRoundedRect');

        // Ghost: resting draws NO fill (transparent) — only the label.
        $btn->draw($this->renderer, $this->style);
        $this->assertEmpty($rounded(), 'resting button is transparent');

        // Hovered: filled.
        $this->renderer->reset();
        $btn->hovered = true;
        $btn->draw($this->renderer, $this->style);
        $this->assertNotEmpty($rounded(), 'hovered button is filled');

        // Pressed: filled.
        $this->renderer->reset();
        $btn->pressed = true;
        $btn->draw($this->renderer, $this->style);
        $this->assertNotEmpty($rounded(), 'pressed button is filled');
    }

    // ── Checkbox ─────────────────────────────────────────────────

    public function testCheckboxMeasure(): void
    {
        $cb = new Checkbox('Enable audio', true);
        $cb->measure(400, 400, $this->style);

        $this->assertGreaterThan(0.0, $cb->getMeasuredWidth());
    }

    public function testCheckboxDrawChecked(): void
    {
        $cb = new Checkbox('On', true);
        $cb->setBounds(new Rect(0, 0, 200, 20));
        $cb->draw($this->renderer, $this->style);

        // Should draw accent color rect for check mark
        $roundedCalls = array_filter($this->renderer->calls, fn($c) => $c['method'] === 'drawRoundedRect');
        $this->assertGreaterThanOrEqual(2, count($roundedCalls)); // box + check
    }

    public function testCheckboxDrawUnchecked(): void
    {
        $cb = new Checkbox('Off', false);
        $cb->setBounds(new Rect(0, 0, 200, 20));
        $cb->draw($this->renderer, $this->style);

        // Only box, no inner check
        $roundedCalls = array_filter($this->renderer->calls, fn($c) => $c['method'] === 'drawRoundedRect');
        $this->assertCount(1, $roundedCalls);
    }

    // ── Toggle ───────────────────────────────────────────────────

    public function testToggleMeasure(): void
    {
        $toggle = new Toggle('Dark mode', true);
        $toggle->measure(400, 400, $this->style);
        $this->assertGreaterThan(0.0, $toggle->getMeasuredWidth());
    }

    public function testToggleDraw(): void
    {
        $toggle = new Toggle('Test', false);
        $toggle->setBounds(new Rect(0, 0, 200, 24));
        $toggle->draw($this->renderer, $this->style);

        $circleCalls = array_filter($this->renderer->calls, fn($c) => $c['method'] === 'drawCircle');
        $this->assertNotEmpty($circleCalls); // thumb
    }

    // ── Slider ───────────────────────────────────────────────────

    public function testSliderValueFromMouseX(): void
    {
        $slider = new Slider('Vol', 0.5, 0.0, 1.0);
        $slider->padding = EdgeInsets::symmetric(horizontal: 4.0, vertical: 4.0);
        $slider->setBounds(new Rect(100, 50, 200, 30));

        // Mouse at start
        $this->assertEqualsWithDelta(0.0, $slider->valueFromMouseX(104.0), 0.02);
        // Mouse at end
        $this->assertEqualsWithDelta(1.0, $slider->valueFromMouseX(296.0), 0.02);
        // Mouse at middle
        $this->assertEqualsWithDelta(0.5, $slider->valueFromMouseX(200.0), 0.05);
    }

    // ── TextInput ────────────────────────────────────────────────

    public function testTextInputInsertChars(): void
    {
        $ti = new TextInput('Name', 'Hel');
        $ti->cursorPos = 3;
        $ti->insertChars(['l', 'o']);

        $this->assertEquals('Hello', $ti->text);
        $this->assertEquals(5, $ti->cursorPos);
    }

    public function testTextInputBackspace(): void
    {
        $ti = new TextInput('', 'ABC');
        $ti->cursorPos = 3;
        $ti->backspace();

        $this->assertEquals('AB', $ti->text);
        $this->assertEquals(2, $ti->cursorPos);
    }

    public function testTextInputDelete(): void
    {
        $ti = new TextInput('', 'ABC');
        $ti->cursorPos = 1;
        $ti->delete();

        $this->assertEquals('AC', $ti->text);
        $this->assertEquals(1, $ti->cursorPos);
    }

    public function testTextInputCursorMovement(): void
    {
        $ti = new TextInput('', 'ABCD');
        $ti->cursorPos = 2;

        $ti->moveCursorLeft();
        $this->assertEquals(1, $ti->cursorPos);

        $ti->moveCursorRight();
        $ti->moveCursorRight();
        $this->assertEquals(3, $ti->cursorPos);
    }

    public function testTextInputCursorBounds(): void
    {
        $ti = new TextInput('', 'AB');
        $ti->cursorPos = 0;
        $ti->moveCursorLeft();
        $this->assertEquals(0, $ti->cursorPos);

        $ti->cursorPos = 2;
        $ti->moveCursorRight();
        $this->assertEquals(2, $ti->cursorPos);
    }

    // ── ProgressBar ──────────────────────────────────────────────

    public function testProgressBarDraw(): void
    {
        $bar = new ProgressBar('Loading', 0.75);
        $bar->setBounds(new Rect(0, 0, 200, 30));
        $bar->draw($this->renderer, $this->style);

        $roundedCalls = array_filter($this->renderer->calls, fn($c) => $c['method'] === 'drawRoundedRect');
        $this->assertGreaterThanOrEqual(2, count($roundedCalls)); // bg + fill
    }

    // ── Dropdown ─────────────────────────────────────────────────

    public function testDropdownSelectedValue(): void
    {
        $dd = new Dropdown('Size', ['Small', 'Medium', 'Large'], 1);
        $this->assertEquals('Medium', $dd->getSelectedValue());
    }

    public function testDropdownEmptyOptions(): void
    {
        $dd = new Dropdown('Empty', []);
        $this->assertNull($dd->getSelectedValue());
    }

    // ── Panel ────────────────────────────────────────────────────

    public function testPanelLayoutsChildren(): void
    {
        $panel = new Panel('Settings');
        $label = (new Label('Item'))->size(Sizing::fixed(100, 20));
        $panel->addChild($label);

        $panel->measure(400, 400, $this->style);
        $panel->setBounds(new Rect(50, 50, 300, 200));
        $panel->layout($this->style);

        // Child should be inside the panel, below the title bar
        $childBounds = $label->getBounds();
        $this->assertGreaterThan(50.0, $childBounds->x); // panel x + padding
        $this->assertGreaterThan(50.0 + $this->style->fontSize, $childBounds->y); // below title
    }

    // ── Stack ────────────────────────────────────────────────────

    public function testStackAnchoring(): void
    {
        $stack = (new Stack())->size(Sizing::fixed(400, 300));
        $child = (new Label('X'))->size(Sizing::fixed(50, 20));
        $stack->addAnchored($child, Anchor::BottomRight);

        $stack->measure(400, 300, $this->style);
        $stack->setBounds(new Rect(0, 0, 400, 300));
        $stack->layout($this->style);

        $cb = $child->getBounds();
        $this->assertEqualsWithDelta(350.0, $cb->x, 1.0);
        $this->assertEqualsWithDelta(280.0, $cb->y, 1.0);
    }

    public function testStackCenterAnchor(): void
    {
        $stack = (new Stack())->size(Sizing::fixed(400, 300));
        $child = (new Label('C'))->size(Sizing::fixed(100, 40));
        $stack->addAnchored($child, Anchor::Center);

        $stack->measure(400, 300, $this->style);
        $stack->setBounds(new Rect(0, 0, 400, 300));
        $stack->layout($this->style);

        $cb = $child->getBounds();
        $this->assertEqualsWithDelta(150.0, $cb->x, 1.0);
        $this->assertEqualsWithDelta(130.0, $cb->y, 1.0);
    }

    // ── Widget tree ──────────────────────────────────────────────

    public function testHitTest(): void
    {
        $label = new Label('Click me');
        $label->setBounds(new Rect(10, 10, 100, 20));

        $this->assertTrue($label->hitTest(new Vec2(50, 15)));
        $this->assertFalse($label->hitTest(new Vec2(200, 200)));
    }

    public function testWidgetAtFindsDeepest(): void
    {
        $vbox = new VBox();
        $vbox->setBounds(new Rect(0, 0, 400, 400));

        $btn = new Button('OK');
        $btn->setBounds(new Rect(10, 10, 100, 30));
        $vbox->addChild($btn);

        $found = $vbox->widgetAt(new Vec2(50, 20));
        $this->assertSame($btn, $found);
    }

    public function testWidgetAtReturnsNullOnMiss(): void
    {
        $label = new Label('X');
        $label->setBounds(new Rect(10, 10, 50, 20));

        $this->assertNull($label->widgetAt(new Vec2(200, 200)));
    }

    // ── Tree operations ──────────────────────────────────────────

    public function testAddRemoveChildren(): void
    {
        $vbox = new VBox();
        $a = new Label('A');
        $b = new Label('B');

        $vbox->addChild($a)->addChild($b);
        $this->assertCount(2, $vbox->getChildren());
        $this->assertSame($vbox, $a->getParent());

        $vbox->removeChild($a);
        $this->assertCount(1, $vbox->getChildren());
        $this->assertNull($a->getParent());
    }

    public function testClearChildren(): void
    {
        $vbox = new VBox();
        $vbox->addChild(new Label('A'));
        $vbox->addChild(new Label('B'));
        $vbox->clearChildren();

        $this->assertCount(0, $vbox->getChildren());
    }

    // ── Visibility ───────────────────────────────────────────────

    public function testHiddenWidgetNotHitTestable(): void
    {
        $label = new Label('Hidden');
        $label->setBounds(new Rect(0, 0, 100, 20));
        $label->hide();

        $this->assertFalse($label->hitTest(new Vec2(50, 10)));
    }

    public function testHiddenChildSkippedInLayout(): void
    {
        $vbox = new VBox(spacing: 0.0);
        $a = (new Label('A'))->size(Sizing::fixed(100, 20));
        $b = (new Label('B'))->size(Sizing::fixed(100, 20));
        $b->hide();
        $c = (new Label('C'))->size(Sizing::fixed(100, 20));
        $vbox->addChild($a)->addChild($b)->addChild($c);

        $vbox->measure(400, 400, $this->style);
        $vbox->setBounds(new Rect(0, 0, 400, 400));
        $vbox->layout($this->style);

        // C should be directly after A (B is hidden)
        $this->assertEquals(0.0, $a->getBounds()->y);
        $this->assertEquals(20.0, $c->getBounds()->y);
    }

    // ── Event system ─────────────────────────────────────────────

    public function testEventEmit(): void
    {
        $btn = new Button('Test');
        $clicked = false;
        $btn->on('click', function () use (&$clicked) { $clicked = true; });
        $btn->emit('click');

        $this->assertTrue($clicked);
    }

    public function testEventMultipleListeners(): void
    {
        $btn = new Button('Test');
        $calls = [];
        $btn->on('click', function () use (&$calls) { $calls[] = 'a'; });
        $btn->on('click', function () use (&$calls) { $calls[] = 'b'; });
        $btn->emit('click');

        $this->assertEquals(['a', 'b'], $calls);
    }

    public function testEventWithArgs(): void
    {
        $slider = new Slider('Vol', 0.5);
        $received = null;
        $slider->on('change', function (float $val) use (&$received) { $received = $val; });
        $slider->emit('change', 0.75);

        $this->assertEquals(0.75, $received);
    }

    // ── Fluent API ───────────────────────────────────────────────

    public function testFluentSetters(): void
    {
        $label = (new Label('X'))
            ->size(Sizing::fixed(100, 20))
            ->pad(EdgeInsets::all(5.0))
            ->margins(EdgeInsets::all(3.0))
            ->hide()
            ->disable();

        $this->assertFalse($label->visible);
        $this->assertFalse($label->enabled);
        $this->assertEquals(100.0, $label->sizing->width);
        $this->assertEquals(5.0, $label->padding->top);
        $this->assertEquals(3.0, $label->margin->left);

        $label->show()->enable();
        $this->assertTrue($label->visible);
        $this->assertTrue($label->enabled);
    }
}
