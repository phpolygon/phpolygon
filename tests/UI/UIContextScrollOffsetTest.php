<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPolygon\Runtime\Input;
use PHPolygon\UI\UIContext;
use PHPolygon\UI\UIStyle;
use PHPUnit\Framework\TestCase;

/**
 * scrollOffset() / setScrollOffset(), and widgets of a scroll region responding
 * only where they can be seen.
 */
final class UIContextScrollOffsetTest extends TestCase
{
    private Input $input;
    private RecordingRenderer2D $renderer;
    private UIContext $ui;

    protected function setUp(): void
    {
        $this->input = new Input();
        $this->renderer = new RecordingRenderer2D(1280, 720);
        $this->ui = new UIContext($this->renderer, $this->input, new UIStyle(fontSize: 10.0, padding: 5.0, itemSpacing: 0.0));
    }

    /** Region at y 100..300; returns the content's top y. */
    private function region(string $id = 'r', float $content = 600.0): float
    {
        $this->ui->beginScroll($id, 10.0, 100.0, 300.0, 200.0, $content);
        $y = $this->ui->getCursorY();
        $this->ui->endScroll();

        return $y;
    }

    private function nextFrame(): void
    {
        $this->input->unsuppress();
        $this->input->endFrame();
    }

    /**
     * Press in one frame, release in the next, cursor at (x, y); $frame draws
     * the widgets and returns whether the one under test reported a click.
     *
     * @param callable(): bool $frame
     */
    private function clickAt(float $x, float $y, callable $frame): bool
    {
        $this->input->handleCursorPosEvent($x, $y);
        $this->input->handleMouseButtonEvent(0, 1);
        $frame();
        $this->nextFrame();
        $this->input->handleCursorPosEvent($x, $y);
        $this->input->handleMouseButtonEvent(0, 0);

        return $frame();
    }

    /** Twenty buttons of 20 px from the region's top; true if button $index fired. */
    private function buttonList(int $index, float $offset): callable
    {
        return function () use ($index, $offset): bool {
            $this->ui->setScrollOffset('list', $offset);
            $this->ui->beginScroll('list', 10.0, 100.0, 300.0, 200.0, 400.0);
            $hit = false;
            for ($i = 0; $i < 20; $i++) {
                $clicked = $this->ui->button("b$i", "B$i");
                $hit = $hit || ($clicked && $i === $index);
            }
            $this->ui->endScroll();

            return $hit;
        };
    }

    public function testANeverScrolledRegionReadsZero(): void
    {
        self::assertSame(0.0, $this->ui->scrollOffset('nothing'));
    }

    public function testTheOffsetAppliesAtTheNextBeginScroll(): void
    {
        $this->ui->setScrollOffset('r', 120.0);

        self::assertSame(100.0 - 120.0, $this->region());
        self::assertSame(120.0, $this->ui->scrollOffset('r'));
    }

    public function testTooFarClampsToTheContentEnd(): void
    {
        $this->ui->setScrollOffset('r', 5000.0);

        self::assertSame(100.0 - 400.0, $this->region(), 'max = content 600 - height 200');
        self::assertSame(400.0, $this->ui->scrollOffset('r'));
    }

    public function testInfinityMeansTheEnd(): void
    {
        $this->ui->setScrollOffset('r', INF);
        $this->region();

        self::assertSame(400.0, $this->ui->scrollOffset('r'));
    }

    public function testNegativeClampsToTheTop(): void
    {
        $this->ui->setScrollOffset('r', -50.0);

        self::assertSame(100.0, $this->region());
        self::assertSame(0.0, $this->ui->scrollOffset('r'));
    }

    public function testShrinkingContentPullsTheOffsetBack(): void
    {
        $this->ui->setScrollOffset('r', 400.0);
        $this->region(content: 600.0);

        $this->region(content: 250.0);

        self::assertSame(50.0, $this->ui->scrollOffset('r'), 'a collapsed section leaves no gap at the bottom');
    }

    public function testContentThatFitsNeverScrolls(): void
    {
        $this->ui->setScrollOffset('r', 80.0);

        self::assertSame(100.0, $this->region(content: 150.0));
    }

    public function testSettingWhileOpenShowsNextFrame(): void
    {
        $this->ui->beginScroll('r', 10.0, 100.0, 300.0, 200.0, 600.0);
        $this->ui->setScrollOffset('r', 60.0);
        $y = $this->ui->getCursorY();
        $this->ui->endScroll();

        self::assertSame(100.0, $y);
        self::assertSame(40.0, $this->region());
    }

    public function testNeedsNoHoverAndKeepsOtherRegionsApart(): void
    {
        $this->input->handleCursorPosEvent(900.0, 600.0);
        $this->ui->setScrollOffset('a', 30.0);

        self::assertSame(70.0, $this->region('a'));
        self::assertSame(100.0, $this->region('b'));
    }

    public function testTheWheelContinuesFromASetOffset(): void
    {
        $this->ui->setScrollOffset('r', 100.0);
        $this->region();

        $this->input->handleCursorPosEvent(100.0, 150.0);
        $this->input->handleScrollEvent(0.0, -1.0);
        $this->region();

        self::assertSame(120.0, $this->ui->scrollOffset('r'), 'one notch = 2 * font size');
    }

    public function testAVisibleButtonInAScrolledRegionClicks(): void
    {
        // Button 10 sits at 200 in the content; offset 100 puts it at screen y 200.
        self::assertTrue($this->clickAt(50.0, 210.0, $this->buttonList(10, 100.0)));
    }

    public function testAButtonScrolledAboveTheClipDoesNotClick(): void
    {
        // Button 0 is drawn at y 0..20: above the region, invisible, yet under the cursor.
        self::assertFalse($this->clickAt(50.0, 10.0, $this->buttonList(0, 100.0)));
        self::assertSame('', $this->ui->hoveredWidgetId());
    }

    public function testAButtonRunningBelowTheClipDoesNotClick(): void
    {
        // Offset 0: button 15 is drawn at y 400..420, the region ends at 300.
        self::assertFalse($this->clickAt(50.0, 410.0, $this->buttonList(15, 0.0)));
    }

    public function testAButtonCutByTheClipEdgeOnlyClicksInItsVisiblePart(): void
    {
        // Offset 10: button 10 is at y 290..310, the region ends at 300.
        self::assertTrue($this->clickAt(50.0, 295.0, $this->buttonList(10, 10.0)));
        $this->nextFrame();
        self::assertFalse($this->clickAt(50.0, 305.0, $this->buttonList(10, 10.0)));
    }

    public function testAButtonBesideTheRegionKeepsWorking(): void
    {
        $clicked = $this->clickAt(50.0, 50.0, function (): bool {
            $this->region();
            $this->ui->begin(10.0, 40.0, 100.0);
            $hit = $this->ui->button('outside', 'Go');
            $this->ui->end();

            return $hit;
        });

        self::assertTrue($clicked, 'the clip ends with its region');
    }

    public function testNestedRegionsClipAgainstEveryParent(): void
    {
        $frame = function (): bool {
            $this->ui->beginScroll('outer', 10.0, 100.0, 300.0, 100.0, 100.0);
            $this->ui->beginScroll('inner', 10.0, 100.0, 300.0, 400.0, 400.0);
            $this->ui->setCursorPosition(10.0, 250.0);
            $hit = $this->ui->button('b', 'B');
            $this->ui->endScroll();
            $this->ui->endScroll();

            return $hit;
        };

        self::assertFalse($this->clickAt(50.0, 260.0, $frame), 'inside the inner region, outside the outer one');
    }

    public function testADropdownListHangingOutOfTheRegionStaysClickable(): void
    {
        $selected = 0;
        $frame = function () use (&$selected): bool {
            // Field at y 280..300, at the region's bottom edge; the list opens below it.
            $this->ui->beginScroll('opts', 10.0, 100.0, 300.0, 200.0, 200.0);
            $this->ui->setCursorPosition(10.0, 280.0);
            $selected = $this->ui->dropdown('d', ['one', 'two', 'three'], $selected);
            $this->ui->endScroll();

            return true;
        };

        $this->clickAt(50.0, 285.0, $frame);
        self::assertTrue($this->ui->hasOpenDropdown(), 'the field itself is visible and opens');

        $this->nextFrame();
        // Row height 20: "three" at 342..362, well outside the clip that ends at 300.
        $this->clickAt(50.0, 350.0, $frame);

        self::assertSame(2, $selected, 'the overlay is above the clip, so is its hit-test');
        self::assertFalse($this->ui->hasOpenDropdown());
    }

    public function testTheWheelScrollsADropdownListHangingOutOfTheRegion(): void
    {
        $selected = 0;
        $frame = function () use (&$selected): bool {
            $this->ui->beginScroll('opts', 10.0, 100.0, 300.0, 200.0, 200.0);
            $this->ui->setCursorPosition(10.0, 280.0);
            $selected = $this->ui->dropdown('d', ['a', 'b', 'c', 'd'], $selected, 0.0, 2);
            $this->ui->endScroll();

            return true;
        };

        $this->clickAt(50.0, 285.0, $frame);
        $this->nextFrame();

        // Over the list, below the clip: the list shows a, b; one notch down shows b, c.
        $this->input->handleCursorPosEvent(50.0, 310.0);
        $this->input->handleScrollEvent(0.0, -1.0);
        $frame();
        $this->nextFrame();

        $this->clickAt(50.0, 310.0, $frame);

        self::assertSame(1, $selected);
    }

    public function testAMissedEndScrollBlocksHoverOnlyUntilTheNextFrame(): void
    {
        $this->ui->beginScroll('leak', 10.0, 100.0, 300.0, 200.0, 600.0);
        // ... and the panel returns early: no endScroll().

        $this->ui->beginFrame();

        $this->input->handleCursorPosEvent(700.0, 50.0);
        $this->ui->begin(690.0, 40.0, 100.0);
        $this->ui->button('b', 'Go');
        $this->ui->end();

        self::assertSame('b', $this->ui->hoveredWidgetId());
    }

    public function testBeginFrameAlsoDropsTheLayoutRegionOfALeakedScroll(): void
    {
        $this->ui->begin(50.0, 60.0, 100.0);
        $this->ui->beginScroll('leak', 10.0, 100.0, 300.0, 200.0, 600.0);

        $this->ui->beginFrame();
        $this->ui->setCursorPosition(1.0, 2.0);
        $this->ui->end();

        self::assertSame(1.0, $this->ui->getCursorX(), 'nothing open: end() has nothing to restore');
        self::assertSame(2.0, $this->ui->getCursorY());
    }

    public function testBeginFrameKeepsTheScrollPosition(): void
    {
        $this->ui->setScrollOffset('r', 70.0);
        $this->region();

        $this->ui->beginFrame();

        self::assertSame(70.0, $this->ui->scrollOffset('r'));
        self::assertSame(30.0, $this->region());
    }

    public function testBeginFrameLeavesTheRendererClipAlone(): void
    {
        // Renderers reset their clip in their own beginFrame(); php-vio warns
        // about a pop on the emptied stack.
        $renderer = new class(1280, 720) extends \PHPolygon\Rendering\NullRenderer2D {
            public int $pops = 0;

            public function popScissor(): void
            {
                $this->pops++;
            }
        };
        $ui = new UIContext($renderer, $this->input);
        $ui->beginScroll('leak', 10.0, 100.0, 300.0, 200.0, 600.0);

        $ui->beginFrame();

        self::assertSame(0, $renderer->pops);
    }

    public function testNonInteractiveStaysNonInteractive(): void
    {
        $this->ui->setInteractive(false);

        self::assertFalse($this->clickAt(50.0, 210.0, $this->buttonList(10, 100.0)));
    }
}
