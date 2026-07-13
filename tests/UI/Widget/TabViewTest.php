<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\DataWidgetContext;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Panel;
use PHPolygon\UI\Widget\Sizing;
use PHPolygon\UI\Widget\TabView;
use PHPolygon\UI\Widget\WidgetBinder;
use PHPolygon\UI\Widget\WidgetSerializer;
use PHPUnit\Framework\TestCase;

class TabViewTest extends TestCase
{
    private UIStyle $style;
    private WidgetTestHelper $renderer;

    protected function setUp(): void
    {
        $this->style = UIStyle::dark();
        $this->renderer = new WidgetTestHelper();
    }

    private function sample(): TabView
    {
        $tabs = new TabView();
        $tabs->tabs = ['One', 'Two'];
        $tabs->sizing = Sizing::fixed(300, 200);
        $tabs->addChild((new Label('first'))->size(Sizing::fixed(100, 20)));
        $tabs->addChild((new Label('second'))->size(Sizing::fixed(100, 20)));

        return $tabs;
    }

    private function laidOut(TabView $tabs): TabView
    {
        $tabs->measure(400, 400, $this->style);
        $tabs->setBounds(new Rect(0, 0, 300, 200));
        $tabs->layout($this->style);

        return $tabs;
    }

    public function testSelectedChildFollowsIndex(): void
    {
        $tabs = $this->sample();

        $this->assertSame($tabs->getChildren()[0], $tabs->selectedChild());

        $tabs->selectedIndex = 1;
        $this->assertSame($tabs->getChildren()[1], $tabs->selectedChild());
    }

    public function testSelectTabEmitsChangeAndUpdatesIndex(): void
    {
        $tabs = $this->sample();
        $received = null;
        $tabs->on('change', function (int $i) use (&$received): void {
            $received = $i;
        });

        $tabs->selectTab(1);

        $this->assertSame(1, $tabs->selectedIndex);
        $this->assertSame(1, $received);
    }

    public function testSelectTabNoOpWhenUnchanged(): void
    {
        $tabs = $this->sample();
        $count = 0;
        $tabs->on('change', function () use (&$count): void {
            $count++;
        });

        $tabs->selectTab(0); // already selected

        $this->assertSame(0, $count, 'change is not emitted when the index does not move');
    }

    public function testSelectTabClampsOutOfRange(): void
    {
        $tabs = $this->sample();
        $tabs->selectTab(99);
        $this->assertSame(1, $tabs->selectedIndex);
    }

    public function testTabIndexAtHitsTheBar(): void
    {
        $tabs = $this->laidOut($this->sample());

        $firstRect = $tabs->getTabRect(0);
        $secondRect = $tabs->getTabRect(1);

        $hitFirst = new Vec2($firstRect->x + $firstRect->width * 0.5, $firstRect->y + 2.0);
        $hitSecond = new Vec2($secondRect->x + $secondRect->width * 0.5, $secondRect->y + 2.0);

        $this->assertSame(0, $tabs->tabIndexAt($hitFirst));
        $this->assertSame(1, $tabs->tabIndexAt($hitSecond));
        $this->assertNull($tabs->tabIndexAt(new Vec2(1000, 1000)), 'point off the bar hits no tab');
    }

    public function testClickingTabInBarSwitchesViaWidgetAt(): void
    {
        $tabs = $this->laidOut($this->sample());

        $secondRect = $tabs->getTabRect(1);
        $point = new Vec2($secondRect->x + 2.0, $secondRect->y + 2.0);

        // widgetAt returns the TabView itself for the tab bar, so the input
        // layer routes the click to selectTab().
        $this->assertSame($tabs, $tabs->widgetAt($point));
    }

    public function testWidgetAtDescendsOnlyIntoSelectedChild(): void
    {
        $tabs = $this->laidOut($this->sample());

        $selected = $tabs->getChildren()[0];
        $point = new Vec2(
            $selected->getBounds()->x + 1.0,
            $selected->getBounds()->y + 1.0,
        );

        // Content region hit lands in the selected child's subtree.
        $this->assertSame($selected, $tabs->widgetAt($point));
    }

    public function testOnlySelectedChildIsDrawn(): void
    {
        $tabs = $this->laidOut($this->sample());
        $tabs->draw($this->renderer, $this->style);

        $texts = array_map(
            fn ($c) => $c['args'][0],
            array_filter($this->renderer->calls, fn ($c) => $c['method'] === 'drawText'),
        );

        $this->assertContains('first', $texts, 'selected child content is drawn');
        $this->assertNotContains('second', $texts, 'unselected child is not drawn');
        $this->assertContains('One', $texts, 'every tab button title is drawn');
        $this->assertContains('Two', $texts);
    }

    public function testTabTitlesFallBackToChildTitle(): void
    {
        $tabs = new TabView();
        $tabs->sizing = Sizing::fixed(300, 200);
        $tabs->addChild(new Panel('Alpha'));
        $tabs->addChild(new Panel('Beta'));
        $tabs = $this->laidOut($tabs);
        $tabs->draw($this->renderer, $this->style);

        $texts = array_map(
            fn ($c) => $c['args'][0],
            array_filter($this->renderer->calls, fn ($c) => $c['method'] === 'drawText'),
        );

        $this->assertContains('Alpha', $texts, 'tab title falls back to child title');
        $this->assertContains('Beta', $texts);
    }

    public function testTwoWayBindingReadsSelectedIndex(): void
    {
        $tabs = $this->sample();
        $tabs->bindings['selectedIndex'] = 'activeTab';

        (new WidgetBinder)->bind($tabs, new DataWidgetContext((object) ['activeTab' => 1]));

        $this->assertSame(1, $tabs->selectedIndex, 'read binding applied from context');
    }

    public function testTwoWayBindingWritesBackOnChange(): void
    {
        $vm = new class {
            public int $activeTab = 0;
        };
        $tabs = $this->sample();
        $tabs->bindings['selectedIndex'] = 'activeTab';

        (new WidgetBinder)->bind($tabs, new DataWidgetContext($vm));

        // A tab click mutates the widget then emits 'change' (as the tree does).
        $tabs->selectTab(1);

        $this->assertSame(1, $vm->activeTab, 'active tab persisted back to the VM');
    }

    public function testSerializationRoundTrip(): void
    {
        $tabs = $this->sample();
        $tabs->selectedIndex = 1;

        $serializer = new WidgetSerializer;
        $restored = $serializer->fromArray($serializer->toArray($tabs));

        $this->assertInstanceOf(TabView::class, $restored);
        $this->assertSame(1, $restored->selectedIndex);
        $this->assertSame(['One', 'Two'], $restored->tabs);
        $this->assertCount(2, $restored->getChildren());
    }

    public function testBoundIndexEmittedAsBindNotLiteral(): void
    {
        $tabs = $this->sample();
        $tabs->bindings['selectedIndex'] = 'activeTab';

        $array = (new WidgetSerializer)->toArray($tabs);

        $this->assertSame(['$bind' => 'activeTab'], $array['selectedIndex']);
    }

    public function testEmptyTabViewLaysOutWithoutError(): void
    {
        $tabs = new TabView();
        $tabs->measure(400, 400, $this->style);
        $tabs->setBounds(new Rect(0, 0, 200, 100));
        $tabs->layout($this->style);

        $this->assertNull($tabs->selectedChild());
        $this->assertNull($tabs->tabIndexAt(new Vec2(5, 5)));
    }
}
