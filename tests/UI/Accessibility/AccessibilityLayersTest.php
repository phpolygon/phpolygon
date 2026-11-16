<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Accessibility;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Rect;
use PHPolygon\Runtime\Input;
use PHPolygon\Testing\RecordingAccessibilitySink;
use PHPolygon\Tests\UI\Widget\WidgetTestHelper;
use PHPolygon\UI\Accessibility\AccessibilityBridge;
use PHPolygon\UI\Accessibility\AccessibilityLayer;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\UI\Widget\Widget;
use PHPolygon\UI\Widget\WidgetTree;

class AccessibilityLayersTest extends TestCase
{
    private RecordingAccessibilitySink $sink;

    protected function setUp(): void
    {
        $this->sink = new RecordingAccessibilitySink();
    }

    private function tree(Widget $root): WidgetTree
    {
        $tree = new WidgetTree($root, new WidgetTestHelper(), new Input(), 800, 600, UIStyle::dark());
        $tree->performLayout();

        return $tree;
    }

    /** @return array{0: WidgetTree, 1: Button} */
    private function panel(string $label = 'Hire'): array
    {
        $button = new Button($label);

        return [$this->tree((new VBox())->addChild($button)), $button];
    }

    /** @return list<string> */
    private function rootChildren(): array
    {
        $children = $this->sink->tree['root']['children'] ?? [];
        $this->assertIsArray($children);

        return array_values(array_filter($children, static fn ($c) => is_string($c) && !str_starts_with($c, 'announce:')));
    }

    public function testTwoLayersShareOneWindowRootInZOrder(): void
    {
        [$panel] = $this->panel('Hire');
        [$overlay] = $this->panel('Toast');
        $bridge = new AccessibilityBridge($this->sink, 'Game');

        $this->assertTrue($bridge->syncLayers([
            new AccessibilityLayer($panel, 'panel'),
            new AccessibilityLayer($overlay, 'overlay', label: 'Notices'),
        ]));

        $this->assertSame(['layer:panel', 'layer:overlay'], $this->rootChildren());
        $this->assertSame('pane', $this->sink->tree['layer:panel']['role']);
        $this->assertSame('Notices', $this->sink->tree['layer:overlay']['label']);
        $this->assertSame('Hire', $this->sink->tree[$this->sink->tree['layer:panel']['children'][0]]['label']);
        $this->assertSame('Toast', $this->sink->tree[$this->sink->tree['layer:overlay']['children'][0]]['label']);
        $this->assertCount(1, $this->sink->updates);
    }

    public function testModalLayerHidesTheLayersBelowIt(): void
    {
        [$panel] = $this->panel('Hire');
        [$dialog] = $this->panel('Confirm');
        $bridge = new AccessibilityBridge($this->sink);

        $bridge->syncLayers([new AccessibilityLayer($panel, 'panel'), new AccessibilityLayer($dialog, 'dialog', modal: true)]);

        $this->assertSame(['layer:dialog'], $this->rootChildren());
        $this->assertSame('dialog', $this->sink->tree['layer:dialog']['role']);
        $this->assertArrayNotHasKey('layer:panel', $this->sink->tree, 'a hidden layer is not even sent');
    }

    public function testLayersAboveTheTopmostModalStayVisible(): void
    {
        [$a] = $this->panel('A');
        [$b] = $this->panel('B');
        [$c] = $this->panel('C');
        $bridge = new AccessibilityBridge($this->sink);

        $bridge->syncLayers([
            new AccessibilityLayer($a, 'a'),
            new AccessibilityLayer($b, 'b', modal: true),
            new AccessibilityLayer($c, 'c'),
        ]);

        $this->assertSame(['layer:b', 'layer:c'], $this->rootChildren());
    }

    public function testRemovingALayerDetachesItAndOnlyTheRootIsResent(): void
    {
        [$panel] = $this->panel('Hire');
        [$dialog] = $this->panel('Confirm');
        $bridge = new AccessibilityBridge($this->sink);
        $layers = [new AccessibilityLayer($panel, 'panel'), new AccessibilityLayer($dialog, 'dialog')];
        $bridge->syncLayers($layers);

        $bridge->syncLayers([$layers[0]]);

        $this->assertCount(2, $this->sink->updates);
        $this->assertSame(['root'], $this->sink->sentIds(1));
        $this->assertSame(['layer:panel'], $this->rootChildren());
    }

    public function testReAddedLayerIsSentAgain(): void
    {
        [$panel] = $this->panel('Hire');
        [$dialog] = $this->panel('Confirm');
        $bridge = new AccessibilityBridge($this->sink);
        $layers = [new AccessibilityLayer($panel, 'panel'), new AccessibilityLayer($dialog, 'dialog')];
        $bridge->syncLayers($layers);
        $bridge->syncLayers([$layers[0]]);

        $bridge->syncLayers($layers);

        $this->assertEqualsCanonicalizing(['root', 'layer:dialog', 'layer:dialog/path:0'], $this->sink->sentIds(2));
    }

    public function testChangingTheOrderOnlyResendsTheRoot(): void
    {
        [$a] = $this->panel('A');
        [$b] = $this->panel('B');
        $bridge = new AccessibilityBridge($this->sink);
        $la = new AccessibilityLayer($a, 'a');
        $lb = new AccessibilityLayer($b, 'b');
        $bridge->syncLayers([$la, $lb]);

        $bridge->syncLayers([$lb, $la]);

        $this->assertSame(['root'], $this->sink->sentIds(1));
        $this->assertSame(['layer:b', 'layer:a'], $this->rootChildren());
    }

    public function testIdsAreStableAcrossFramesAndRebuiltTrees(): void
    {
        $bridge = new AccessibilityBridge($this->sink);
        for ($frame = 0; $frame < 3; $frame++) {
            [$panel] = $this->panel('Hire');
            [$dialog] = $this->panel('Confirm');
            $bridge->syncLayers([new AccessibilityLayer($panel, 'panel'), new AccessibilityLayer($dialog, 'dialog')]);
        }

        $this->assertCount(1, $this->sink->updates, 'nothing changed, nothing was sent again');
    }

    public function testOnlyTheChangedNodeOfOneLayerIsSent(): void
    {
        [$panel, $button] = $this->panel('Hire');
        [$overlay] = $this->panel('Toast');
        $bridge = new AccessibilityBridge($this->sink);
        $layers = [new AccessibilityLayer($panel, 'panel'), new AccessibilityLayer($overlay, 'overlay')];
        $bridge->syncLayers($layers);

        $button->label = 'Fire';
        $bridge->syncLayers($layers);

        $this->assertSame(['layer:panel/path:0'], $this->sink->sentIds(1));
    }

    public function testFocusInTheLowerLayerIsReported(): void
    {
        [$panel, $button] = $this->panel('Hire');
        [$overlay] = $this->panel('Toast');
        $panel->setKeyboardNavigation(true);
        $panel->setFocus($button, true);
        $bridge = new AccessibilityBridge($this->sink);

        $bridge->syncLayers([new AccessibilityLayer($panel, 'panel'), new AccessibilityLayer($overlay, 'overlay')]);

        $this->assertSame('layer:panel/path:0', $this->sink->focus);
    }

    public function testTopmostLayerWithFocusWins(): void
    {
        [$panel, $a] = $this->panel('A');
        [$dialog, $b] = $this->panel('B');
        $panel->setKeyboardNavigation(true);
        $dialog->setKeyboardNavigation(true);
        $panel->setFocus($a, true);
        $dialog->setFocus($b, true);
        $bridge = new AccessibilityBridge($this->sink);

        $bridge->syncLayers([new AccessibilityLayer($panel, 'panel'), new AccessibilityLayer($dialog, 'dialog')]);

        $this->assertSame('layer:dialog/path:0', $this->sink->focus);
    }

    public function testFocusInAHiddenLayerFallsBackToTheWindow(): void
    {
        [$panel, $a] = $this->panel('A');
        [$dialog] = $this->panel('B');
        $panel->setKeyboardNavigation(true);
        $panel->setFocus($a, true);
        $bridge = new AccessibilityBridge($this->sink);

        $bridge->syncLayers([new AccessibilityLayer($panel, 'panel'), new AccessibilityLayer($dialog, 'dialog', modal: true)]);

        $this->assertSame('root', $this->sink->focus);
    }

    public function testActionIsAppliedToTheTreeOfItsLayer(): void
    {
        [$panel, $a] = $this->panel('A');
        [$dialog, $b] = $this->panel('B');
        $clicked = [];
        $a->on('click', function () use (&$clicked): void { $clicked[] = 'a'; });
        $b->on('click', function () use (&$clicked): void { $clicked[] = 'b'; });
        $bridge = new AccessibilityBridge($this->sink);
        $layers = [new AccessibilityLayer($panel, 'panel'), new AccessibilityLayer($dialog, 'dialog')];
        $bridge->syncLayers($layers);

        $this->sink->request('layer:panel/path:0', 'click');
        $this->sink->request('layer:dialog/path:0', 'click');
        $bridge->syncLayers($layers);

        $this->assertSame(['a', 'b'], $clicked);
    }

    public function testActionOnAHiddenLayerIsIgnored(): void
    {
        [$panel, $a] = $this->panel('A');
        [$dialog] = $this->panel('B');
        $clicked = 0;
        $a->on('click', function () use (&$clicked): void { $clicked++; });
        $bridge = new AccessibilityBridge($this->sink);
        $bridge->syncLayers([new AccessibilityLayer($panel, 'panel')]);

        $this->sink->request('layer:panel/path:0', 'click');
        $bridge->syncLayers([new AccessibilityLayer($panel, 'panel'), new AccessibilityLayer($dialog, 'dialog', modal: true)]);

        $this->assertSame(0, $clicked);
    }

    public function testLayerAndBridgeTransformsCompose(): void
    {
        [$panel] = $this->panel('A');
        $bridge = new AccessibilityBridge($this->sink, '', static fn (Rect $r): Rect => new Rect($r->x * 2, $r->y * 2, $r->width * 2, $r->height * 2));
        $layer = new AccessibilityLayer($panel, 'panel', boundsTransform: static fn (Rect $r): Rect => new Rect($r->x + 10, $r->y, $r->width, $r->height));
        $plain = new AccessibilityLayer($this->panel('B')[0], 'plain');

        $bridge->syncLayers([$layer, $plain]);

        $bare = $this->tree((new VBox())->addChild(new Button('A')))->getRoot()->getFocusChildren()[0]->getBounds();
        $this->assertSame(round(($bare->x + 10) * 2, 1), $this->sink->tree['layer:panel/path:0']['bounds'][0]);
        $this->assertSame(round($bare->x * 2, 1), $this->sink->tree['layer:plain/path:0']['bounds'][0]);
    }

    public function testBareTreesAreNamedByPosition(): void
    {
        [$a] = $this->panel('A');
        [$b] = $this->panel('B');
        $bridge = new AccessibilityBridge($this->sink);

        $bridge->syncLayers([$a, $b]);

        $this->assertSame(['layer:layer0', 'layer:layer1'], $this->rootChildren());
    }

    public function testSameNameTwiceStillGivesUniqueIds(): void
    {
        [$a] = $this->panel('A');
        [$b] = $this->panel('B');
        $bridge = new AccessibilityBridge($this->sink);

        $this->assertTrue($bridge->syncLayers([new AccessibilityLayer($a, 'x'), new AccessibilityLayer($b, 'x')]));

        $this->assertSame(['layer:x', 'layer:x#2'], $this->rootChildren());
    }

    public function testAnnouncementsStayGlobal(): void
    {
        [$a] = $this->panel('A');
        $bridge = new AccessibilityBridge($this->sink);
        $bridge->syncLayers([new AccessibilityLayer($a, 'a')]);

        $bridge->announce('Saved');
        $bridge->syncLayers([new AccessibilityLayer($a, 'a')]);

        $this->assertSame('Saved', $this->sink->tree['announce:polite']['label']);
        $this->assertContains('announce:polite', $this->sink->tree['root']['children']);
    }

    public function testEmptyLayerListIsAWindowWithoutChildren(): void
    {
        $bridge = new AccessibilityBridge($this->sink);

        $this->assertTrue($bridge->syncLayers([]));

        $this->assertSame([], $this->rootChildren());
    }

    public function testSingleTreeSyncIsUnchanged(): void
    {
        [$panel] = $this->panel('Hire');
        $bridge = new AccessibilityBridge($this->sink, 'Game');

        $bridge->sync($panel);

        $this->assertSame(['path:0'], array_slice($this->rootChildren(), 0, 1), 'ids without a layer prefix');
        $this->assertArrayNotHasKey('layer:layer0', $this->sink->tree);
    }

    public function testRefusingSinkSwitchesTheBridgeOff(): void
    {
        [$panel] = $this->panel('Hire');
        $this->sink->refuse = true;
        $bridge = new AccessibilityBridge($this->sink);

        $this->assertFalse($bridge->syncLayers([new AccessibilityLayer($panel, 'panel')]));
        $this->assertFalse($bridge->isAvailable());
        $this->assertSame([], $this->sink->updates);
    }
}
