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
}
