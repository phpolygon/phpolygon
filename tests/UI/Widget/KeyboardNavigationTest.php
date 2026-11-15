<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPUnit\Framework\TestCase;
use PHPolygon\Runtime\Input;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\Checkbox;
use PHPolygon\UI\Widget\Dropdown;
use PHPolygon\UI\Widget\FocusState;
use PHPolygon\UI\Widget\FocusStyle;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Panel;
use PHPolygon\UI\Widget\ScrollView;
use PHPolygon\UI\Widget\Sizing;
use PHPolygon\UI\Widget\Slider;
use PHPolygon\UI\Widget\TabView;
use PHPolygon\UI\Widget\TextInput;
use PHPolygon\UI\Widget\Toggle;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\UI\Widget\Widget;
use PHPolygon\UI\Widget\WidgetSerializer;
use PHPolygon\UI\Widget\WidgetTree;

/**
 * Keyboard focus traversal, activation and the focus ring. All headless: keys
 * are fed to a real Input, the renderer is the recording test double.
 */
class KeyboardNavigationTest extends TestCase
{
    private const TAB = 258;
    private const ESC = 256;
    private const ENTER = 257;
    private const SPACE = 32;
    private const RIGHT = 262;
    private const LEFT = 263;
    private const DOWN = 264;
    private const UP = 265;
    private const END = 269;

    private Input $input;
    private WidgetTestHelper $renderer;

    protected function setUp(): void
    {
        $this->input = new Input();
        $this->renderer = new WidgetTestHelper();
        // Keep the pointer off every control so hover never suppresses input here.
        $this->input->handleCursorPosEvent(799.0, 599.0);
    }

    private function tree(Widget $root, bool $navigation = true, ?FocusState $state = null): WidgetTree
    {
        $tree = new WidgetTree($root, $this->renderer, $this->input, 800, 600, UIStyle::dark(), 0.0, 0.0, $state);
        $tree->setKeyboardNavigation($navigation);
        $tree->performLayout();

        return $tree;
    }

    /** One frame in which $key goes down (with Shift when asked) and is processed. */
    private function press(WidgetTree $tree, int $key, bool $shift = false): void
    {
        $this->input->unsuppress();
        if ($shift) {
            $this->input->handleKeyEvent(340, 1);
        }
        $this->input->handleKeyEvent($key, 1);
        $tree->processInput();
        $this->input->handleKeyEvent($key, 0);
        if ($shift) {
            $this->input->handleKeyEvent(340, 0);
        }
        $this->input->endFrame();
    }

    private function typeChar(WidgetTree $tree, string $char): void
    {
        $this->input->unsuppress();
        $this->input->handleCharEvent(mb_ord($char));
        $tree->processInput();
        $this->input->endFrame();
    }

    /** @return array{VBox, Button, Button, Checkbox} */
    private function simpleForm(): array
    {
        $root = new VBox();
        $a = new Button('A');
        $b = new Button('B');
        $c = new Checkbox('C');
        $root->addChild($a)->addChild(new Label('not interactive'))->addChild($b)->addChild($c);

        return [$root, $a, $b, $c];
    }

    public function testNavigationIsOffByDefault(): void
    {
        [$root] = $this->simpleForm();
        $tree = $this->tree($root, false);

        $this->input->unsuppress();
        $this->input->handleKeyEvent(self::TAB, 1);
        $tree->processInput();

        $this->assertNull($tree->getFocusedWidget());
        $this->assertFalse($tree->isFocusRingVisible());
        $this->assertFalse($this->input->isSuppressed(), 'the tree took no key');
        $this->assertTrue($this->input->isKeyPressed(self::TAB), 'the game still sees Tab');
    }

    public function testTabVisitsFocusableWidgetsInDocumentOrderAndWraps(): void
    {
        [$root, $a, $b, $c] = $this->simpleForm();
        $tree = $this->tree($root);

        $seen = [];
        for ($i = 0; $i < 4; $i++) {
            $this->press($tree, self::TAB);
            $seen[] = $tree->getFocusedWidget();
        }

        $this->assertSame([$a, $b, $c, $a], $seen, 'label is skipped, order wraps back to the first');
    }

    public function testShiftTabWalksBackwardsAndStartsAtTheEnd(): void
    {
        [$root, $a, $b, $c] = $this->simpleForm();
        $tree = $this->tree($root);

        $this->press($tree, self::TAB, true);
        $this->assertSame($c, $tree->getFocusedWidget());
        $this->press($tree, self::TAB, true);
        $this->assertSame($b, $tree->getFocusedWidget());
        $this->press($tree, self::TAB, true);
        $this->assertSame($a, $tree->getFocusedWidget());
        $this->press($tree, self::TAB, true);
        $this->assertSame($c, $tree->getFocusedWidget(), 'wraps to the last');
    }

    public function testDisabledHiddenAndOptedOutWidgetsAreSkipped(): void
    {
        $root = new VBox();
        $first = new Button('first');
        $disabled = (new Button('disabled'))->disable();
        $hidden = (new Button('hidden'))->hide();
        $optedOut = new Button('opted out');
        $optedOut->focusable = false;
        $forced = new Label('forced');
        $forced->focusable = true;
        $last = new Toggle('last');
        $root->addChild($first)->addChild($disabled)->addChild($hidden)->addChild($optedOut)->addChild($forced)->addChild($last);
        $tree = $this->tree($root);

        $this->assertSame([$first, $forced, $last], $tree->getFocusOrder());
    }

    public function testFocusOrderOverrideMovesWidgetsToTheFront(): void
    {
        [$root, $a, $b, $c] = $this->simpleForm();
        $c->focusOrder = 1;
        $b->focusOrder = 2;
        $tree = $this->tree($root);

        $this->assertSame([$c, $b, $a], $tree->getFocusOrder());
    }

    public function testFocusScopeKeepsTraversalInsideASubtree(): void
    {
        $root = new VBox();
        $outside = new Button('outside');
        $dialog = new VBox();
        $in1 = new Button('in1');
        $in2 = new Button('in2');
        $dialog->addChild($in1)->addChild($in2);
        $root->addChild($outside)->addChild($dialog);
        $tree = $this->tree($root);
        $tree->setFocusScope($dialog);

        $this->press($tree, self::TAB);
        $this->press($tree, self::TAB);
        $this->press($tree, self::TAB);

        $this->assertSame($in1, $tree->getFocusedWidget(), 'wrapped inside the scope, never reached the outside button');
    }

    public function testEnterAndSpaceActivateAButtonWithTheMouseEvents(): void
    {
        [$root, $a] = $this->simpleForm();
        $log = [];
        $a->on('press', function () use (&$log) { $log[] = 'press'; });
        $a->on('click', function () use (&$log) { $log[] = 'click'; });
        $tree = $this->tree($root);
        $this->press($tree, self::TAB);

        $this->press($tree, self::ENTER);
        $this->press($tree, self::SPACE);

        $this->assertSame(['press', 'click', 'press', 'click'], $log);
    }

    public function testActivatingCheckboxAndToggleFlipsAndEmitsChange(): void
    {
        $root = new VBox();
        $check = new Checkbox('c', false);
        $toggle = new Toggle('t', false);
        $root->addChild($check)->addChild($toggle);
        $changes = [];
        $check->on('change', function (bool $v) use (&$changes) { $changes[] = ['check', $v]; });
        $toggle->on('change', function (bool $v) use (&$changes) { $changes[] = ['toggle', $v]; });
        $tree = $this->tree($root);

        $this->press($tree, self::TAB);
        $this->press($tree, self::SPACE);
        $this->press($tree, self::TAB);
        $this->press($tree, self::ENTER);

        $this->assertTrue($check->checked);
        $this->assertTrue($toggle->on);
        $this->assertSame([['check', true], ['toggle', true]], $changes);
    }

    public function testDisabledWidgetsAreNotActivatedThroughTheTreeApi(): void
    {
        $button = (new Button('x'))->disable();
        $clicked = false;
        $button->on('click', function () use (&$clicked) { $clicked = true; });
        $tree = $this->tree((new VBox())->addChild($button));

        $this->assertFalse($tree->activate($button));
        $this->assertFalse($clicked);
    }

    public function testSliderArrowKeysStepAndClamp(): void
    {
        $slider = new Slider('s', 0.5, 0.0, 1.0);
        $slider->keyStep = 0.25;
        $values = [];
        $slider->on('change', function (float $v) use (&$values) { $values[] = $v; });
        $tree = $this->tree((new VBox())->addChild($slider));
        $this->press($tree, self::TAB);

        $this->press($tree, self::RIGHT);
        $this->press($tree, self::RIGHT);
        $this->press($tree, self::RIGHT);
        $this->press($tree, self::LEFT);
        $this->press($tree, self::END);

        $this->assertSame([0.75, 1.0, 0.75], array_slice($values, 0, 3), 'the third right press is clamped and emits nothing');
        $this->assertSame(1.0, $slider->value);
    }

    public function testSliderDefaultStepIsATwentiethOfTheRange(): void
    {
        $slider = new Slider('s', 0.0, 0.0, 10.0);
        $tree = $this->tree((new VBox())->addChild($slider));
        $this->press($tree, self::TAB);

        $this->press($tree, self::UP);

        $this->assertEqualsWithDelta(0.5, $slider->value, 1e-9);
    }

    public function testDropdownOpensMovesHighlightCommitsAndCloses(): void
    {
        $dd = new Dropdown('d', ['one', 'two', 'three'], 0);
        $changes = [];
        $dd->on('change', function (int $i) use (&$changes) { $changes[] = $i; });
        $tree = $this->tree((new VBox())->addChild($dd));
        $this->press($tree, self::TAB);

        $this->press($tree, self::ENTER);
        $this->assertTrue($dd->open);
        $this->assertSame(0, $dd->highlightedIndex, 'the cursor starts on the selection');

        $this->press($tree, self::DOWN);
        $this->press($tree, self::DOWN);
        $this->press($tree, self::DOWN);
        $this->assertSame(2, $dd->highlightedIndex, 'clamped at the last option');
        $this->assertSame(0, $dd->selectedIndex, 'moving the cursor does not select');

        $this->press($tree, self::ENTER);

        $this->assertFalse($dd->open);
        $this->assertSame(2, $dd->selectedIndex);
        $this->assertSame([2], $changes);
        $this->assertSame($dd, $tree->getFocusedWidget(), 'focus stays on the dropdown');
    }

    public function testEscapeClosesAnOpenDropdownFirstThenLeavesFocus(): void
    {
        $dd = new Dropdown('d', ['one', 'two'], 1);
        $tree = $this->tree((new VBox())->addChild($dd));
        $this->press($tree, self::TAB);
        $this->press($tree, self::SPACE);
        $this->assertTrue($dd->open);

        $this->press($tree, self::ESC);
        $this->assertFalse($dd->open);
        $this->assertSame(1, $dd->selectedIndex, 'Esc cancels, it does not select');
        $this->assertSame($dd, $tree->getFocusedWidget());

        $this->press($tree, self::ESC);
        $this->assertNull($tree->getFocusedWidget());
    }

    public function testTabClosesAnOpenDropdownOnTheWayOut(): void
    {
        $dd = new Dropdown('d', ['one', 'two'], 0);
        $next = new Button('next');
        $tree = $this->tree((new VBox())->addChild($dd)->addChild($next));
        $this->press($tree, self::TAB);
        $this->press($tree, self::ENTER);

        $this->press($tree, self::TAB);

        $this->assertFalse($dd->open);
        $this->assertSame($next, $tree->getFocusedWidget());
    }

    public function testTabViewArrowKeysSwitchTabsAndOnlyTheShownTabIsReachable(): void
    {
        $tabs = new TabView();
        $tabs->tabs = ['One', 'Two'];
        $b1 = new Button('b1');
        $b2 = new Button('b2');
        $tabs->addChild((new VBox())->addChild($b1))->addChild((new VBox())->addChild($b2));
        $root = new VBox();
        $root->addChild($tabs);
        $changes = [];
        $tabs->on('change', function (int $i) use (&$changes) { $changes[] = $i; });
        $tree = $this->tree($root);

        $this->assertSame([$tabs, $b1], $tree->getFocusOrder());

        $this->press($tree, self::TAB);
        $this->assertSame($tabs, $tree->getFocusedWidget());
        $this->press($tree, self::RIGHT);
        $this->press($tree, self::RIGHT);
        $this->assertSame(1, $tabs->selectedIndex, 'no wrap at the end');
        $this->assertSame([1], $changes);
        $this->assertSame([$tabs, $b2], $tree->getFocusOrder());
        $this->press($tree, self::LEFT);
        $this->assertSame(0, $tabs->selectedIndex);
    }

    public function testTextInputKeepsItsKeysWhileEditing(): void
    {
        $field = new TextInput('name', 'ab');
        $root = new VBox();
        $root->addChild($field)->addChild(new Button('after'));
        $tree = $this->tree($root);
        $this->press($tree, self::TAB);
        $this->assertTrue($field->focused);

        $this->typeChar($tree, 'c');
        $this->press($tree, self::LEFT);
        $this->press($tree, self::SPACE);
        $this->press($tree, self::ENTER);
        $this->press($tree, self::DOWN);
        $this->typeChar($tree, 'x');

        $this->assertSame($field, $tree->getFocusedWidget(), 'navigation did not take focus away');
        $this->assertTrue($field->focused);
        $this->assertSame('abxc', $field->text, 'arrow keys moved only the caret; nothing activated');
    }

    public function testTabAndEscapeLeaveATextInput(): void
    {
        $field = new TextInput('name', 'ab');
        $button = new Button('after');
        $tree = $this->tree((new VBox())->addChild($field)->addChild($button));
        $this->press($tree, self::TAB);

        $this->press($tree, self::TAB);
        $this->assertFalse($field->focused);
        $this->assertSame($button, $tree->getFocusedWidget());

        $this->press($tree, self::TAB);
        $this->assertTrue($field->focused);
        $this->press($tree, self::ESC);
        $this->assertFalse($field->focused);
        $this->assertNull($tree->getFocusedWidget());
    }

    public function testArrowsMoveFocusBetweenPlainControlsWhenEnabled(): void
    {
        [$root, $a, $b, $c] = $this->simpleForm();
        $tree = $this->tree($root);
        $tree->setArrowsMoveFocus(true);
        $this->press($tree, self::TAB);

        $this->press($tree, self::DOWN);
        $this->assertSame($b, $tree->getFocusedWidget());
        $this->press($tree, self::DOWN);
        $this->assertSame($c, $tree->getFocusedWidget());
        $this->press($tree, self::UP);
        $this->assertSame($b, $tree->getFocusedWidget());
    }

    public function testArrowsDoNotMoveFocusByDefault(): void
    {
        [$root, $a, $b] = $this->simpleForm();
        $tree = $this->tree($root);
        $this->press($tree, self::TAB);

        $this->press($tree, self::DOWN);

        $this->assertSame($a, $tree->getFocusedWidget());
    }

    public function testFocusChangeListenerReceivesNewAndPreviousWidget(): void
    {
        [$root, $a, $b] = $this->simpleForm();
        $tree = $this->tree($root);
        $events = [];
        $tree->onFocusChange(function (?Widget $now, ?Widget $before) use (&$events) { $events[] = [$now, $before]; });

        $this->press($tree, self::TAB);
        $this->press($tree, self::TAB);
        $this->press($tree, self::ESC);

        $this->assertSame([[$a, null], [$b, $a], [null, $b]], $events);
    }

    public function testFocusedButtonDoesNotSwallowGameKeysButAConsumedKeyDoes(): void
    {
        [$root, $a] = $this->simpleForm();
        $tree = $this->tree($root);

        $this->input->unsuppress();
        $this->input->handleKeyEvent(self::TAB, 1);
        $tree->processInput();
        $this->assertTrue($this->input->isSuppressed(), 'Tab was taken by the tree');
        $this->input->handleKeyEvent(self::TAB, 0);
        $this->input->endFrame();

        $this->input->unsuppress();
        $this->input->handleKeyEvent(70, 1); // an unrelated game key (F)
        $tree->processInput();
        $this->assertSame($a, $tree->getFocusedWidget());
        $this->assertFalse($this->input->isSuppressed(), 'a focused button leaves the game its keys');
        $this->assertTrue($this->input->isKeyPressed(70));
    }

    public function testFocusRingShowsOnlyForKeyboardFocusAndDoesNotMoveLayout(): void
    {
        [$root, $a] = $this->simpleForm();
        $tree = $this->tree($root);
        $before = $a->getBounds();

        $tree->draw();
        $plain = count(array_filter($this->renderer->calls, fn (array $c) => $c['method'] === 'drawRoundedRectOutline'));

        $tree->setFocus($a);
        $this->renderer->reset();
        $tree->draw();
        $afterClickFocus = count(array_filter($this->renderer->calls, fn (array $c) => $c['method'] === 'drawRoundedRectOutline'));

        $this->press($tree, self::TAB); // wraps onto B, focus via keyboard
        $this->renderer->reset();
        $tree->draw();
        $rings = array_values(array_filter($this->renderer->calls, fn (array $c) => $c['method'] === 'drawRoundedRectOutline'));

        $this->assertSame($plain, $afterClickFocus, 'focus that did not come from a key draws no ring');
        $this->assertCount($plain + 1, $rings);
        $this->assertTrue($tree->isFocusRingVisible());
        $this->assertEquals($before, $a->getBounds(), 'the ring is drawn off the widget geometry');
    }

    public function testFocusRingFollowsTheFocusStyle(): void
    {
        $button = new Button('x');
        $tree = $this->tree((new VBox())->addChild($button));
        $tree->setFocusStyle(new FocusStyle(width: 3.0, offset: 4.0, radius: 9.0));
        $this->press($tree, self::TAB);
        $this->renderer->reset();

        $tree->draw();

        $ring = null;
        foreach ($this->renderer->calls as $call) {
            if ($call['method'] === 'drawRoundedRectOutline') {
                $ring = $call['args'];
            }
        }
        $this->assertNotNull($ring);
        $bounds = $button->getBounds();
        $gap = 4.0 + 1.5;
        $this->assertEqualsWithDelta($bounds->x - $gap, $ring[0], 1e-6);
        $this->assertEqualsWithDelta($bounds->width + 2 * $gap, $ring[2], 1e-6);
        $this->assertSame(9.0, $ring[4]);
        $this->assertSame(3.0, $ring[6]);
    }

    public function testClickingElsewhereClearsKeyboardFocusAndRing(): void
    {
        [$root, $a] = $this->simpleForm();
        $tree = $this->tree($root);
        $this->press($tree, self::TAB);
        $this->assertTrue($tree->isFocusRingVisible());

        $this->input->unsuppress();
        $this->input->handleCursorPosEvent(790.0, 590.0);
        $this->input->handleMouseButtonEvent(0, 1);
        $tree->processInput();
        $this->input->endFrame();

        $this->assertNull($tree->getFocusedWidget());
        $this->assertFalse($tree->isFocusRingVisible());
    }

    public function testFocusSurvivesABrandNewTreeEveryFrame(): void
    {
        // The host builds a new tree (and, with a repeater, new widgets) each frame.
        $state = new FocusState();
        $buildRoot = function (): VBox {
            $root = new VBox();
            $root->addChild(new Button('one'))->addChild(new Button('two'))->addChild(new Button('three'));

            return $root;
        };

        $focusedLabels = [];
        for ($frame = 0; $frame < 3; $frame++) {
            $root = $buildRoot();
            $tree = $this->tree($root, true, $state);
            $this->press($tree, self::TAB);
            $widget = $tree->getFocusedWidget();
            $focusedLabels[] = $widget instanceof Button ? $widget->label : null;
        }

        $this->assertSame(['one', 'two', 'three'], $focusedLabels, 'Tab moved on instead of restarting at the first button');
    }

    public function testFocusIsDroppedWhenItsWidgetDisappears(): void
    {
        $state = new FocusState();
        $root = new VBox();
        $a = new Button('a');
        $b = new Button('b');
        $root->addChild($a)->addChild($b);
        $tree = $this->tree($root, true, $state);
        $this->press($tree, self::TAB);
        $this->press($tree, self::TAB);
        $this->assertSame($b, $tree->getFocusedWidget());

        $b->hide();
        $tree->processInput();

        $this->assertNull($tree->getFocusedWidget());
        $this->assertFalse($tree->isFocusRingVisible());
    }

    public function testExplicitAccessibleIdKeepsFocusWhenSiblingsShift(): void
    {
        $state = new FocusState();
        $first = new VBox();
        $target = new Button('target');
        $target->accessibleId = 'ok';
        $first->addChild($target);
        $tree = $this->tree($first, true, $state);
        $this->press($tree, self::TAB);

        $second = new VBox();
        $second->addChild(new Button('new first'));
        $moved = new Button('target');
        $moved->accessibleId = 'ok';
        $second->addChild($moved);
        $tree2 = $this->tree($second, true, $state);
        $tree2->processInput();

        $this->assertSame($moved, $tree2->getFocusedWidget());
    }

    public function testFocusingAWidgetInsideAScrollViewScrollsItIntoView(): void
    {
        $scroll = new ScrollView();
        $scroll->size(Sizing::fixed(200, 60));
        $inner = new VBox();
        $buttons = [];
        for ($i = 0; $i < 8; $i++) {
            $buttons[$i] = (new Button("b$i"))->size(Sizing::fixed(100, 30));
            $inner->addChild($buttons[$i]);
        }
        $scroll->addChild($inner);
        $root = new VBox();
        $root->addChild($scroll);
        $tree = $this->tree($root);

        for ($i = 0; $i < 8; $i++) {
            $this->press($tree, self::TAB);
        }

        $last = $buttons[7];
        $this->assertSame($last, $tree->getFocusedWidget());
        $view = $scroll->getBounds();
        $this->assertLessThanOrEqual($view->bottom() + 0.01, $last->getBounds()->bottom());
        $this->assertGreaterThan(0.0, $scroll->getScrollY());
    }

    public function testAccessibilityPropertiesRoundTripThroughTheSerializer(): void
    {
        $button = new Button('Go');
        $button->accessibleLabel = 'Start the game';
        $button->accessibleDescription = 'Begins a new run';
        $button->accessibleId = 'start';
        $button->focusable = false;
        $button->focusOrder = 3;
        $panel = new Panel('p');
        $panel->addChild($button);

        $serializer = new WidgetSerializer();
        $data = $serializer->toArray($panel);
        $copy = $serializer->fromArray($data);
        $copyButton = $copy->getChildren()[0];

        $this->assertInstanceOf(Button::class, $copyButton);
        $this->assertSame('Start the game', $copyButton->accessibleLabel);
        $this->assertSame('Begins a new run', $copyButton->accessibleDescription);
        $this->assertSame('start', $copyButton->accessibleId);
        $this->assertFalse($copyButton->focusable);
        $this->assertSame(3, $copyButton->focusOrder);
    }

    public function testDefaultsStayOutOfTheSerializedLayout(): void
    {
        $data = (new WidgetSerializer())->toArray(new Button('x'));

        foreach (['accessibleLabel', 'accessibleDescription', 'accessibleId', 'focusable', 'focusOrder', 'highlightedIndex'] as $key) {
            $this->assertArrayNotHasKey($key, $data, "$key must not appear while at its default");
        }
        $dropdown = (new WidgetSerializer())->toArray(new Dropdown('d', ['a']), false);
        $this->assertArrayNotHasKey('highlightedIndex', $dropdown, 'keyboard cursor is runtime state, never persisted');
    }
}
