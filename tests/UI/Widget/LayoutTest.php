<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Rect;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\EdgeInsets;
use PHPolygon\UI\Widget\HBox;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Separator;
use PHPolygon\UI\Widget\Sizing;
use PHPolygon\UI\Widget\Spacer;
use PHPolygon\UI\Widget\Stack;
use PHPolygon\UI\Widget\VBox;

class LayoutTest extends TestCase
{
    private UIStyle $style;

    protected function setUp(): void
    {
        $this->style = UIStyle::dark();
    }

    // ── Stack ────────────────────────────────────────────────────

    public function testStackSizesToIntrinsicChildNotFillOverlay(): void
    {
        // A full-size overlay (e.g. a card-wide click target) fills the stack
        // and must NOT drive its measured size — otherwise it would balloon to
        // the full available height. The intrinsic content sets the extent.
        $stack = new Stack();
        $content = (new Label('content'))->size(Sizing::fixed(120, 40));
        $overlay = (new Label('overlay'))->size(Sizing::fill());
        $stack->addChild($content)->addChild($overlay);

        $stack->measure(800, 600, $this->style);

        $this->assertEquals(120.0, $stack->getMeasuredWidth());
        $this->assertEquals(40.0, $stack->getMeasuredHeight());

        // In layout the fill overlay still stretches to the stack's bounds.
        $stack->setBounds(new Rect(0, 0, 120, 40));
        $stack->layout($this->style);
        $this->assertEquals(120.0, $overlay->getBounds()->width);
        $this->assertEquals(40.0, $overlay->getBounds()->height);
    }

    public function testWrappingLabelGrowsHeightWithLineCount(): void
    {
        // A single-line label reserves one line; the same text wrapped to a
        // narrow width reserves several — height scales with the wrapped lines.
        $text = 'the quick brown fox jumps over the lazy dog again and again';

        $single = (new Label($text))->size(Sizing::fillWidth());
        $single->measure(120, 400, $this->style);

        $wrapped = new Label($text);
        $wrapped->wrap = true;
        $wrapped->size(Sizing::fillWidth());
        $wrapped->measure(120, 400, $this->style);

        $this->assertGreaterThan($single->getMeasuredHeight(), $wrapped->getMeasuredHeight());
    }

    public function testWrappingLabelHonoursHardBreaks(): void
    {
        $label = new Label("line one\nline two\nline three");
        $label->wrap = true;
        $label->size(Sizing::fillWidth());
        $label->measure(4000, 400, $this->style); // wide enough that only \n breaks

        // 3 hard-broken lines at fontSize * lineHeight each.
        $expected = 3 * $this->style->fontSize * $label->lineHeight;
        $this->assertEqualsWithDelta($expected, $label->getMeasuredHeight(), 0.01);
    }

    // ── VBox ─────────────────────────────────────────────────────

    public function testVBoxStacksVertically(): void
    {
        $vbox = new VBox(spacing: 0.0);
        $a = (new Label('A'))->size(Sizing::fixed(100, 20));
        $b = (new Label('B'))->size(Sizing::fixed(100, 30));
        $vbox->addChild($a)->addChild($b);

        $vbox->measure(400, 400, $this->style);
        $vbox->setBounds(new Rect(0, 0, $vbox->getMeasuredWidth(), $vbox->getMeasuredHeight()));
        $vbox->layout($this->style);

        $this->assertEquals(0.0, $a->getBounds()->y);
        $this->assertEquals(20.0, $b->getBounds()->y);
    }

    public function testVBoxSpacing(): void
    {
        $vbox = new VBox(spacing: 10.0);
        $a = (new Label('A'))->size(Sizing::fixed(100, 20));
        $b = (new Label('B'))->size(Sizing::fixed(100, 20));
        $vbox->addChild($a)->addChild($b);

        $vbox->measure(400, 400, $this->style);
        $vbox->setBounds(new Rect(0, 0, 400, 400));
        $vbox->layout($this->style);

        $this->assertEquals(0.0, $a->getBounds()->y);
        $this->assertEquals(30.0, $b->getBounds()->y); // 20 + 10 spacing
    }

    public function testVBoxFillWidth(): void
    {
        $vbox = new VBox(spacing: 0.0);
        $child = (new Label('Wide'))->size(Sizing::fillWidth(20.0));
        $vbox->addChild($child);

        $vbox->measure(300, 400, $this->style);
        $vbox->setBounds(new Rect(0, 0, 300, 400));
        $vbox->layout($this->style);

        $this->assertEquals(300.0, $child->getBounds()->width);
    }

    public function testVBoxPadding(): void
    {
        $vbox = new VBox(spacing: 0.0);
        $vbox->pad(EdgeInsets::all(10.0));
        $child = (new Label('X'))->size(Sizing::fixed(50, 20));
        $vbox->addChild($child);

        $vbox->measure(400, 400, $this->style);
        $vbox->setBounds(new Rect(0, 0, 400, 400));
        $vbox->layout($this->style);

        $this->assertEquals(10.0, $child->getBounds()->x);
        $this->assertEquals(10.0, $child->getBounds()->y);
    }

    // ── HBox ─────────────────────────────────────────────────────

    public function testHBoxStacksHorizontally(): void
    {
        $hbox = new HBox(spacing: 0.0);
        $a = (new Label('A'))->size(Sizing::fixed(50, 20));
        $b = (new Label('B'))->size(Sizing::fixed(60, 20));
        $hbox->addChild($a)->addChild($b);

        $hbox->measure(400, 400, $this->style);
        $hbox->setBounds(new Rect(0, 0, $hbox->getMeasuredWidth(), $hbox->getMeasuredHeight()));
        $hbox->layout($this->style);

        $this->assertEquals(0.0, $a->getBounds()->x);
        $this->assertEquals(50.0, $b->getBounds()->x);
    }

    public function testHBoxSpacing(): void
    {
        $hbox = new HBox(spacing: 8.0);
        $a = (new Label('A'))->size(Sizing::fixed(40, 20));
        $b = (new Label('B'))->size(Sizing::fixed(40, 20));
        $hbox->addChild($a)->addChild($b);

        $hbox->measure(400, 400, $this->style);
        $hbox->setBounds(new Rect(0, 0, 400, 400));
        $hbox->layout($this->style);

        $this->assertEquals(0.0, $a->getBounds()->x);
        $this->assertEquals(48.0, $b->getBounds()->x); // 40 + 8 spacing
    }

    // ── Spacer ───────────────────────────────────────────────────

    public function testSpacerFixedSize(): void
    {
        $spacer = new Spacer(0.0, 20.0);
        $spacer->measure(400, 400, $this->style);
        $this->assertEquals(20.0, $spacer->getMeasuredHeight());
    }

    public function testSpacerFillExpands(): void
    {
        $spacer = new Spacer();
        $spacer->measure(400, 400, $this->style);
        $this->assertEquals(400.0, $spacer->getMeasuredWidth());
        $this->assertEquals(400.0, $spacer->getMeasuredHeight());
    }

    // ── Separator ────────────────────────────────────────────────

    public function testSeparatorMeasure(): void
    {
        $sep = new Separator(2.0);
        $sep->measure(300, 300, $this->style);
        $this->assertEquals(2.0, $sep->getMeasuredHeight());
        $this->assertEquals(300.0, $sep->getMeasuredWidth());
    }
}
