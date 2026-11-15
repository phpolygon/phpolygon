<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Accessibility;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Rect;
use PHPolygon\Runtime\Input;
use PHPolygon\Tests\UI\Widget\WidgetTestHelper;
use PHPolygon\UI\Accessibility\AccessibilityBridge;
use PHPolygon\UI\Accessibility\VioAccessibilitySink;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\Checkbox;
use PHPolygon\UI\Widget\Dropdown;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Slider;
use PHPolygon\UI\Widget\TabView;
use PHPolygon\UI\Widget\TextInput;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\UI\Widget\Widget;
use PHPolygon\UI\Widget\WidgetTree;

class AccessibilityBridgeTest extends TestCase
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

    public function testFirstSyncSendsTheWholeTreeWithTheRootAndFocusOnTheWindow(): void
    {
        $button = new Button('OK');
        $root = (new VBox())->addChild($button)->addChild(new Label('hello'));
        $bridge = new AccessibilityBridge($this->sink, 'Game');

        $this->assertTrue($bridge->sync($this->tree($root)));

        $this->assertCount(1, $this->sink->updates);
        $update = $this->sink->updates[0];
        $this->assertSame('root', $update['root']);
        $this->assertCount(3, $update['nodes']);
        $this->assertSame('root', $update['nodes'][0]['id']);
        $this->assertSame('root', $update['focus']);
    }

    public function testUnchangedFrameSendsNothing(): void
    {
        $root = (new VBox())->addChild(new Button('OK'));
        $bridge = new AccessibilityBridge($this->sink);
        $tree = $this->tree($root);

        $bridge->sync($tree);
        $bridge->sync($tree);
        $bridge->sync($this->tree($root));

        $this->assertCount(1, $this->sink->updates);
    }

    public function testOnlyChangedNodesAreResent(): void
    {
        $a = new Button('one');
        $b = new Checkbox('two', false);
        $root = (new VBox())->addChild($a)->addChild($b);
        $bridge = new AccessibilityBridge($this->sink);
        $tree = $this->tree($root);
        $bridge->sync($tree);

        $b->checked = true;
        $bridge->sync($tree);

        $this->assertCount(2, $this->sink->updates);
        $this->assertCount(1, $this->sink->updates[1]['nodes']);
        $node = $this->sink->updates[1]['nodes'][0];
        $this->assertSame('check_box', $node['role']);
        $this->assertTrue($node['checked']);
        $this->assertArrayNotHasKey('root', $this->sink->updates[1]);
        $this->assertArrayNotHasKey('focus', $this->sink->updates[1]);
    }

    public function testFocusChangeSendsOnlyTheFocus(): void
    {
        $a = new Button('one');
        $b = new Button('two');
        $root = (new VBox())->addChild($a)->addChild($b);
        $bridge = new AccessibilityBridge($this->sink);
        $tree = $this->tree($root);
        $bridge->sync($tree);

        $tree->setFocus($b, true);
        $bridge->sync($tree);

        $this->assertSame(['focus' => $tree->focusKeyOf($b)], $this->sink->updates[1]);
        $this->assertSame($tree->focusKeyOf($b), $this->sink->focus);

        $tree->setFocus(null);
        $bridge->sync($tree);
        $this->assertSame(['focus' => 'root'], $this->sink->updates[2], 'no focused widget: focus rests on the window');
    }

    public function testAWidgetThatAppearsAndDisappearsKeepsTheTreeValid(): void
    {
        $root = new VBox();
        $root->addChild(new Button('stays'));
        $bridge = new AccessibilityBridge($this->sink);
        $tree = $this->tree($root);
        $bridge->sync($tree);

        $extra = new Button('extra');
        $root->addChild($extra);
        $tree->performLayout();
        $bridge->sync($tree);
        $root->removeChild($extra);
        $bridge->sync($tree);

        $this->assertCount(3, $this->sink->updates);
        $this->assertCount(1, $this->sink->tree['root']['children'], 'the window lists only the button that stayed');
    }

    public function testUnavailableSinkIsASilentNoOp(): void
    {
        $sink = new RecordingAccessibilitySink(false);
        $bridge = new AccessibilityBridge($sink, 'Game');
        $root = (new VBox())->addChild(new Button('OK'));

        $this->assertFalse($bridge->isAvailable());
        $this->assertFalse($bridge->sync($this->tree($root)));
        $bridge->handleAction('x', 'click');
        $this->assertSame([], $sink->updates);
        $this->assertNull($sink->handler, 'no action handler is registered where there is no accessibility');
    }

    public function testContractErrorSwitchesTheBridgeOffInsteadOfThrowingEveryFrame(): void
    {
        $sink = new class extends RecordingAccessibilitySink {
            public function update(array $update): bool
            {
                throw new \ValueError('node "x" needs a known "role"');
            }
        };
        $bridge = new AccessibilityBridge($sink);
        $tree = $this->tree((new VBox())->addChild(new Button('OK')));

        $this->assertFalse($bridge->sync($tree));
        $this->assertFalse($bridge->isAvailable());
        $this->assertSame('node "x" needs a known "role"', $bridge->getLastError());
        $this->assertFalse($bridge->sync($tree));
    }

    public function testInvalidateResendsEverything(): void
    {
        $root = (new VBox())->addChild(new Button('OK'));
        $bridge = new AccessibilityBridge($this->sink);
        $tree = $this->tree($root);
        $bridge->sync($tree);

        $bridge->invalidate();
        $bridge->sync($tree);

        $this->assertCount(2, $this->sink->updates);
        $this->assertSame('root', $this->sink->updates[1]['root']);
        $this->assertCount(2, $this->sink->updates[1]['nodes']);
    }

    public function testBoundsTransformIsApplied(): void
    {
        $root = (new VBox())->addChild(new Button('OK'));
        $bridge = new AccessibilityBridge($this->sink, '', fn (Rect $r) => new Rect($r->x + 100, $r->y + 50, $r->width, $r->height));

        $bridge->sync($this->tree($root));

        $button = array_values(array_filter($this->sink->updates[0]['nodes'], fn (array $n) => $n['role'] === 'button'))[0];
        $this->assertSame(100.0, $button['bounds'][0]);
        $this->assertSame(50.0, $button['bounds'][1]);
    }

    // ── actions from assistive technology ────────────────────────

    public function testClickActionPressesTheButtonAtTheNextSync(): void
    {
        $button = new Button('OK');
        $clicked = 0;
        $button->on('click', function () use (&$clicked) { $clicked++; });
        $root = (new VBox())->addChild($button);
        $bridge = new AccessibilityBridge($this->sink);
        $tree = $this->tree($root);
        $bridge->sync($tree);

        $this->sink->request($tree->focusKeyOf($button), 'click');
        $this->assertSame(0, $clicked, 'queued, applied on the game loop');
        $bridge->sync($tree);

        $this->assertSame(1, $clicked);
    }

    public function testActionsReachTheRebuiltWidgetsOfTheNewFrame(): void
    {
        // The host rebuilds its widgets each frame: the action is applied to the live one.
        $first = new Button('OK');
        $bridge = new AccessibilityBridge($this->sink);
        $bridge->sync($this->tree((new VBox())->addChild($first)));
        $id = 'path:0';

        $second = new Button('OK');
        $clicked = false;
        $second->on('click', function () use (&$clicked) { $clicked = true; });
        $this->sink->request($id, 'click');
        $bridge->sync($this->tree((new VBox())->addChild($second)));

        $this->assertTrue($clicked);
    }

    public function testFocusAndBlurActions(): void
    {
        $field = new TextInput('Name');
        $root = (new VBox())->addChild($field);
        $bridge = new AccessibilityBridge($this->sink);
        $tree = $this->tree($root);
        $bridge->sync($tree);
        $id = $tree->focusKeyOf($field);

        $this->sink->request($id, 'focus');
        $bridge->sync($tree);
        $this->assertSame($field, $tree->getFocusedWidget());
        $this->assertSame($id, $this->sink->focus, 'the platform is told about the new focus in the same sync');

        $this->sink->request($id, 'blur');
        $bridge->sync($tree);
        $this->assertNull($tree->getFocusedWidget());
    }

    public function testSetValueIncrementAndDecrement(): void
    {
        $field = new TextInput('Name');
        $slider = new Slider('Vol', 0.5, 0.0, 1.0);
        $slider->keyStep = 0.1;
        $texts = [];
        $field->on('input', function (string $t) use (&$texts) { $texts[] = $t; });
        $root = (new VBox())->addChild($field)->addChild($slider);
        $bridge = new AccessibilityBridge($this->sink);
        $tree = $this->tree($root);
        $bridge->sync($tree);

        $this->sink->request($tree->focusKeyOf($field), 'set_value', 'Ada');
        $this->sink->request($tree->focusKeyOf($slider), 'set_value', 2.5);
        $bridge->sync($tree);
        $this->assertSame('Ada', $field->text);
        $this->assertSame(['Ada'], $texts);
        $this->assertSame(1.0, $slider->value, 'clamped to the range');

        $this->sink->request($tree->focusKeyOf($slider), 'decrement');
        $bridge->sync($tree);
        $this->assertEqualsWithDelta(0.9, $slider->value, 1e-9);

        $last = end($this->sink->updates);
        $sliderNode = array_values(array_filter($last['nodes'], fn (array $n) => $n['role'] === 'slider'))[0];
        $this->assertEqualsWithDelta(0.9, $sliderNode['value'], 1e-9, 'the new state goes out in the same sync');
    }

    public function testDropdownExpandCollapseAndOptionClick(): void
    {
        $dd = new Dropdown('Size', ['small', 'large'], 0);
        $changed = [];
        $dd->on('change', function (int $i) use (&$changed) { $changed[] = $i; });
        $root = (new VBox())->addChild($dd);
        $bridge = new AccessibilityBridge($this->sink);
        $tree = $this->tree($root);
        $bridge->sync($tree);
        $id = $tree->focusKeyOf($dd);

        $this->sink->request($id, 'expand');
        $bridge->sync($tree);
        $this->assertTrue($dd->open);
        $this->assertArrayHasKey($id . '/option/1', $this->sink->tree);

        $this->sink->request($id . '/option/1', 'click');
        $bridge->sync($tree);
        $this->assertFalse($dd->open);
        $this->assertSame(1, $dd->selectedIndex);
        $this->assertSame([1], $changed);

        $this->sink->request($id, 'collapse');
        $bridge->sync($tree);
        $this->assertFalse($dd->open, 'collapsing a closed dropdown does nothing');
    }

    public function testTabClickSelectsTheTab(): void
    {
        $tabs = new TabView();
        $tabs->tabs = ['One', 'Two'];
        $tabs->addChild(new VBox())->addChild(new VBox());
        $root = (new VBox())->addChild($tabs);
        $bridge = new AccessibilityBridge($this->sink);
        $tree = $this->tree($root);
        $bridge->sync($tree);

        $this->sink->request($tree->focusKeyOf($tabs) . '/tab/1', 'click');
        $bridge->sync($tree);

        $this->assertSame(1, $tabs->selectedIndex);
    }

    public function testActionsOnDisabledOrUnknownNodesAreIgnored(): void
    {
        $button = (new Button('OK'))->disable();
        $clicked = false;
        $button->on('click', function () use (&$clicked) { $clicked = true; });
        $root = (new VBox())->addChild($button);
        $bridge = new AccessibilityBridge($this->sink);
        $tree = $this->tree($root);
        $bridge->sync($tree);

        $this->sink->request($tree->focusKeyOf($button), 'click');
        $this->sink->request('no-such-node', 'click');
        $bridge->sync($tree);

        $this->assertFalse($clicked);
        $this->assertCount(1, $this->sink->updates);
    }

    // ── against the real vio contract, when the extension is here ──

    public function testRealVioContractAcceptsTheMappedTree(): void
    {
        if (!function_exists('vio_a11y_update') || !function_exists('vio_create')) {
            $this->markTestSkipped('php-vio with vio_a11y_update is not loaded');
        }
        // A headless window, as in php-vio's own accessibility test: the 'null' backend has no window to attach to.
        $ctx = @vio_create('auto', ['width' => 64, 'height' => 48, 'headless' => true]);
        if (!$ctx || !vio_platform_info()['accessibility']) {
            $this->markTestSkipped('no headless vio window with accessibility on this machine');
        }
        $sink = new VioAccessibilitySink($ctx);
        $bridge = new AccessibilityBridge($sink, 'vio contract test');

        $button = new Button('Save');
        $dd = new Dropdown('Size', ['a', 'b'], 0);
        $dd->open = true;
        $tabs = new TabView();
        $tabs->tabs = ['One', 'Two'];
        $tabs->addChild((new VBox())->addChild(new Label('page')))->addChild(new VBox());
        $root = (new VBox())
            ->addChild($button)
            ->addChild(new Checkbox('c', true))
            ->addChild(new Slider('s', 0.3, 0.0, 1.0))
            ->addChild($dd)
            ->addChild(new TextInput('n', 'x'))
            ->addChild($tabs);
        $tree = $this->tree($root);
        $tree->setFocus($button, true);

        // vio throws ValueError/TypeError for any contract violation; the bridge would
        // swallow that into getLastError(), so look at it as well.
        $result = $bridge->sync($tree);
        $this->assertNull($bridge->getLastError(), (string) $bridge->getLastError());
        $this->assertTrue($result, 'vio accepted the mapped tree');

        $tree->setFocus($dd, true);
        $dd->open = false;
        $this->assertTrue($bridge->sync($tree), 'and the follow-up update');
        $this->assertNull($bridge->getLastError());

        vio_destroy($ctx);
    }

    public function testVioSinkIsUnavailableWithoutTheFunctionOrFeature(): void
    {
        if (function_exists('vio_a11y_update')) {
            $ctx = vio_create('null', ['width' => 64, 'height' => 48]);
            $sink = new VioAccessibilitySink($ctx);
            $this->assertSame(vio_platform_info()['accessibility'], $sink->isAvailable());
            vio_destroy($ctx);

            return;
        }
        $this->assertFalse((new VioAccessibilitySink(new \stdClass()))->isAvailable());
    }
}
