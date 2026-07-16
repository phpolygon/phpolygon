<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPUnit\Framework\TestCase;
use PHPolygon\Runtime\Input;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Dropdown;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\UI\Widget\WidgetTree;

/**
 * An open dropdown's option list must float above siblings drawn after it (a
 * filter row sits above a ScrollView / tab content). WidgetTree draws the list
 * in a final top-most pass, so its options are recorded AFTER the later
 * sibling's content rather than being painted over by it.
 */
class DropdownOverlayTest extends TestCase
{
    /** Index of the first drawText call whose text equals $text, or -1. */
    private function textIndex(WidgetTestHelper $r, string $text): int
    {
        foreach ($r->calls as $i => $c) {
            if ($c['method'] === 'drawText' && ($c['args'][0] ?? null) === $text) {
                return $i;
            }
        }
        return -1;
    }

    private function drawTree(Dropdown $dd): WidgetTestHelper
    {
        $root = new VBox();
        $root->addChild($dd);            // filter row
        $root->addChild(new Label('CONTENT')); // "tab content" below, drawn after

        $renderer = new WidgetTestHelper();
        $tree = new WidgetTree($root, $renderer, new Input(), 800, 600, UIStyle::dark());
        $tree->performLayout();
        $tree->draw();
        return $renderer;
    }

    public function testOpenListDrawsAboveLaterSiblings(): void
    {
        $dd = new Dropdown('', ['Alpha', 'Beta'], 0);
        $dd->open = true;

        $r = $this->drawTree($dd);

        $content = $this->textIndex($r, 'CONTENT');
        $beta    = $this->textIndex($r, 'Beta'); // only the open list renders 'Beta'

        $this->assertGreaterThanOrEqual(0, $content, 'the later sibling must be drawn');
        $this->assertGreaterThanOrEqual(0, $beta, 'the open list must be drawn');
        $this->assertGreaterThan($content, $beta, 'the open list must draw AFTER the later sibling');
    }

    public function testClosedDropdownDrawsNoOptionList(): void
    {
        $dd = new Dropdown('', ['Alpha', 'Beta'], 0);
        $dd->open = false;

        $r = $this->drawTree($dd);

        // 'Beta' is a non-selected option: it only appears when the list is open.
        $this->assertSame(-1, $this->textIndex($r, 'Beta'), 'a closed dropdown draws no list');
    }

    public function testDropdownFieldDoesNotDrawTheListInline(): void
    {
        // draw() alone (no WidgetTree overlay pass) must NOT paint the list, else
        // it would be covered by later siblings again.
        $dd = new Dropdown('', ['Alpha', 'Beta'], 0);
        $dd->open = true;
        $dd->setBounds(new \PHPolygon\Math\Rect(0, 0, 160, 28));

        $renderer = new WidgetTestHelper();
        $dd->draw($renderer, UIStyle::dark());

        $this->assertSame(-1, $this->textIndex($renderer, 'Beta'), 'draw() must not render the open list');
    }

    /** @return array{0: WidgetTree, 1: Input, 2: Dropdown} */
    private function interactiveTree(Dropdown $dd, float $viewportHeight = 600.0): array
    {
        $input = new Input();
        $root = new VBox();
        $root->addChild($dd);                  // filter row
        $root->addChild(new Label('CONTENT')); // sibling the open list overlaps
        $tree = new WidgetTree($root, new WidgetTestHelper(), $input, 800, $viewportHeight, UIStyle::dark());
        $tree->performLayout();
        return [$tree, $input, $dd];
    }

    public function testClickingAnOptionSelectsItThroughAnOverlappingSibling(): void
    {
        $dd = new Dropdown('', ['Alpha', 'Beta', 'Gamma'], 0);
        $dd->open = true;
        [$tree, $input] = $this->interactiveTree($dd);

        // Cursor over the 'Beta' option (index 1) — screen space owned by the
        // sibling in the layout tree, but the floating list must win.
        $rect = $dd->getOptionRect(1);
        $input->handleMouseButtonEvent(0, 1);
        $input->endFrame();                    // mousePrev = down, so release edges
        $input->handleMouseButtonEvent(0, 0);
        $input->handleCursorPosEvent($rect->x + $rect->width / 2.0, $rect->y + $rect->height / 2.0);
        $tree->processInput();

        $this->assertSame(1, $dd->selectedIndex, 'the clicked option must be selected');
        $this->assertFalse($dd->open, 'selecting an option closes the list');
    }

    public function testPressInsideListDoesNotToggleItShut(): void
    {
        $dd = new Dropdown('', ['Alpha', 'Beta'], 0);
        $dd->open = true;
        [$tree, $input] = $this->interactiveTree($dd);

        $rect = $dd->getOptionRect(0);
        $input->handleCursorPosEvent($rect->x + 2.0, $rect->y + 2.0);
        $input->handleMouseButtonEvent(0, 1); // press only
        $tree->processInput();

        $this->assertTrue($dd->open, 'a press inside the list must not toggle it shut');
    }

    public function testPressOutsideDismissesOpenDropdown(): void
    {
        $dd = new Dropdown('', ['Alpha', 'Beta'], 0);
        $dd->open = true;
        [$tree, $input] = $this->interactiveTree($dd);

        $input->handleCursorPosEvent(700.0, 550.0); // clear of the field and list
        $input->handleMouseButtonEvent(0, 1);
        $tree->processInput();

        $this->assertFalse($dd->open, 'clicking outside dismisses the open dropdown');
    }

    /** A tall option list is clamped to the viewport and scrolls instead of overflowing. */
    public function testLongListIsClampedAndScrolls(): void
    {
        $options = [];
        for ($i = 0; $i < 60; $i++) {
            $options[] = 'Employee ' . $i;
        }
        $dd = new Dropdown('', $options, 0);
        $dd->open = true;
        // 200px-tall viewport: the 60-row list can't possibly fit, so it must clamp.
        [$tree, $input] = $this->interactiveTree($dd, 200);

        // processInput() runs the per-frame viewport clamp.
        $input->handleCursorPosEvent(700.0, 190.0); // clear of field and list
        $tree->processInput();

        $bounds = $dd->listBounds();
        $this->assertNotNull($bounds);
        $this->assertLessThanOrEqual(200.0, $bounds->y + $bounds->height, 'clamped list must fit the viewport');
        $this->assertGreaterThan(0.0, $dd->maxListScroll(), 'a clamped-away list must be scrollable');
    }

    public function testWheelScrollsTheOpenList(): void
    {
        $options = [];
        for ($i = 0; $i < 60; $i++) {
            $options[] = 'Employee ' . $i;
        }
        $dd = new Dropdown('', $options, 0);
        $dd->open = true;
        [$tree, $input] = $this->interactiveTree($dd, 200);

        // Point the cursor into the open list, then scroll the wheel down.
        $list = $dd->listBounds();
        $this->assertNotNull($list);
        $input->handleCursorPosEvent($list->x + 4.0, $list->y + 4.0);
        $input->handleScrollEvent(0.0, -3.0); // wheel down
        $tree->processInput();

        $this->assertGreaterThan(0.0, $dd->listScrollY, 'the wheel must scroll the open list down');
        $this->assertLessThanOrEqual($dd->maxListScroll(), $dd->listScrollY, 'scroll stays within range');
    }
}
