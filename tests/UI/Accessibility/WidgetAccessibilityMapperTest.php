<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Accessibility;

use PHPUnit\Framework\TestCase;
use PHPolygon\Math\Rect;
use PHPolygon\Runtime\Input;
use PHPolygon\Tests\UI\Widget\WidgetTestHelper;
use PHPolygon\UI\Accessibility\AccessibilitySnapshot;
use PHPolygon\UI\Accessibility\WidgetAccessibilityMapper;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\Checkbox;
use PHPolygon\UI\Widget\Dropdown;
use PHPolygon\UI\Widget\HBox;
use PHPolygon\UI\Widget\Image;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Panel;
use PHPolygon\UI\Widget\ProgressBar;
use PHPolygon\UI\Widget\Sizing;
use PHPolygon\UI\Widget\Slider;
use PHPolygon\UI\Widget\TabView;
use PHPolygon\UI\Widget\TextArea;
use PHPolygon\UI\Widget\TextInput;
use PHPolygon\UI\Widget\Toggle;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\UI\Widget\Widget;
use PHPolygon\UI\Widget\WidgetTree;

class WidgetAccessibilityMapperTest extends TestCase
{
    private function tree(Widget $root): WidgetTree
    {
        $tree = new WidgetTree($root, new WidgetTestHelper(), new Input(), 800, 600, UIStyle::dark());
        $tree->performLayout();

        return $tree;
    }

    private function map(Widget $root, ?callable $transform = null): AccessibilitySnapshot
    {
        return (new WidgetAccessibilityMapper())->map($this->tree($root), 'Window title', $transform);
    }

    /** @return array<string, mixed> */
    private function nodeOf(AccessibilitySnapshot $snapshot, Widget $widget): array
    {
        foreach ($snapshot->targets as $id => [$w, $part]) {
            if ($w === $widget && $part === 'widget') {
                return $snapshot->nodes[$id];
            }
        }
        $this->fail('widget has no node');
    }

    public function testRootIsTheWindowAndLayoutContainersAddNoNodes(): void
    {
        $inner = new HBox();
        $button = new Button('OK');
        $inner->addChild($button);
        $root = new VBox();
        $root->addChild($inner);

        $snap = $this->map($root);

        $this->assertSame('root', $snap->rootId);
        $this->assertSame(['id' => 'root', 'role' => 'window', 'label' => 'Window title', 'children' => [$this->nodeOf($snap, $button)['id']]], $snap->nodes['root']);
        $this->assertCount(2, $snap->nodes, 'only the window and the button');
    }

    public function testButtonNode(): void
    {
        $button = (new Button('Save'))->size(Sizing::fixed(80, 24));
        $button->tooltip = "Saves the file\nWrites it to disk.";
        $root = new VBox();
        $root->addChild($button);

        $node = $this->nodeOf($this->map($root), $button);

        $this->assertSame('button', $node['role']);
        $this->assertSame('Save', $node['label']);
        $this->assertSame(['click', 'focus'], $node['actions']);
        $this->assertSame("Saves the file\nWrites it to disk.", $node['description'], 'the tooltip becomes the description');
        $this->assertSame([0.0, 0.0, 80.0, 24.0], $node['bounds']);
        $this->assertArrayNotHasKey('disabled', $node);
    }

    public function testAccessibleLabelAndDescriptionOverrideTheWidgetsOwnText(): void
    {
        $button = new Button('X');
        $button->accessibleLabel = 'Close the dialog';
        $button->accessibleDescription = 'Discards your changes';
        $button->tooltip = 'ignored for the description';
        $root = (new VBox())->addChild($button);

        $node = $this->nodeOf($this->map($root), $button);

        $this->assertSame('Close the dialog', $node['label']);
        $this->assertSame('Discards your changes', $node['description']);
    }

    public function testUnlabelledButtonFallsBackToTheTooltipHeading(): void
    {
        $button = new Button('');
        $button->tooltip = "Open the map\nShows the whole world.";
        $root = (new VBox())->addChild($button);

        $node = $this->nodeOf($this->map($root), $button);

        $this->assertSame('Open the map', $node['label']);
        $this->assertSame('Shows the whole world.', $node['description']);
    }

    public function testCheckboxAndToggleReportCheckedState(): void
    {
        $check = new Checkbox('Sound', true);
        $toggle = new Toggle('Music', false);
        $root = (new VBox())->addChild($check)->addChild($toggle);
        $snap = $this->map($root);

        $c = $this->nodeOf($snap, $check);
        $t = $this->nodeOf($snap, $toggle);

        $this->assertSame(['check_box', true], [$c['role'], $c['checked']]);
        $this->assertSame(['switch', false], [$t['role'], $t['checked']]);
    }

    public function testSliderCarriesValueRangeAndValueText(): void
    {
        $plain = new Slider('Volume', 0.4, 0.0, 1.0);
        $worded = new Slider('Speed', 2.0, 1.0, 3.0);
        $worded->valueText = 'Fast';
        $root = (new VBox())->addChild($plain)->addChild($worded);
        $snap = $this->map($root);

        $a = $this->nodeOf($snap, $plain);
        $b = $this->nodeOf($snap, $worded);

        $this->assertSame('slider', $a['role']);
        $this->assertSame(0.4, $a['value']);
        $this->assertSame([0.0, 1.0], $a['range']);
        $this->assertContains('set_value', $a['actions']);
        $this->assertSame('Fast', $b['value'], 'the host-formatted text is what is read out');
    }

    public function testClosedDropdownHasNoOptionsOpenOneListsThem(): void
    {
        $dd = new Dropdown('Size', ['small', 'large'], 1);
        $root = (new VBox())->addChild($dd);

        $closed = $this->nodeOf($this->map($root), $dd);
        $this->assertSame(['combo_box', 'large'], [$closed['role'], $closed['value']]);
        $this->assertContains('expand', $closed['actions']);
        $this->assertArrayNotHasKey('children', $closed);

        $dd->open = true;
        $snap = $this->map($root);
        $open = $this->nodeOf($snap, $dd);
        $this->assertContains('collapse', $open['actions']);
        $this->assertCount(2, $open['children']);
        $second = $snap->nodes[$open['children'][1]];
        $this->assertSame(['list_box_option', 'large', true], [$second['role'], $second['label'], $second['checked']]);
        $this->assertSame([$dd, 'option', 1], $snap->targets[$open['children'][1]]);
    }

    public function testTextInputAndTextArea(): void
    {
        $field = new TextInput('Name', 'Ada', 'your name');
        $area = new TextArea('Notes');
        $bare = new TextInput('', '', 'search here');
        $root = (new VBox())->addChild($field)->addChild($area)->addChild($bare);
        $snap = $this->map($root);

        $f = $this->nodeOf($snap, $field);
        $a = $this->nodeOf($snap, $area);
        $b = $this->nodeOf($snap, $bare);

        $this->assertSame(['text_input', 'Name', 'Ada'], [$f['role'], $f['label'], $f['value']]);
        $this->assertSame(['focus', 'set_value'], $f['actions']);
        $this->assertSame('multiline_text_input', $a['role']);
        $this->assertSame('search here', $b['label'], 'the placeholder names a field with no label');
    }

    public function testTabViewMapsTabsAndOnlyTheShownContent(): void
    {
        $tabs = new TabView();
        $tabs->tabs = ['One', 'Two'];
        $first = new Button('first page');
        $second = new Button('second page');
        $tabs->addChild((new VBox())->addChild($first))->addChild((new VBox())->addChild($second));
        $tabs->selectedIndex = 1;
        $root = (new VBox())->addChild($tabs);
        $snap = $this->map($root);

        $group = $this->nodeOf($snap, $tabs);
        $list = $snap->nodes[$group['children'][0]];
        $this->assertSame('tab_list', $list['role']);
        $this->assertCount(2, $list['children']);
        $this->assertSame(['tab', 'One', false], [$snap->nodes[$list['children'][0]]['role'], $snap->nodes[$list['children'][0]]['label'], $snap->nodes[$list['children'][0]]['checked']]);
        $this->assertTrue($snap->nodes[$list['children'][1]]['checked']);

        $labels = array_column(array_filter($snap->nodes, fn (array $n) => $n['role'] === 'button'), 'label');
        $this->assertSame(['second page'], array_values($labels));
    }

    public function testTextWidgetsAndGroups(): void
    {
        $title = new Label('Welcome');
        $empty = new Label('');
        $bar = new ProgressBar('Loading', 0.25);
        $icon = new Image();
        $labelledIcon = new Image();
        $labelledIcon->accessibleLabel = 'Coin';
        $panel = new Panel('Settings');
        $panel->addChild($title);
        $root = (new VBox())->addChild($panel)->addChild($empty)->addChild($bar)->addChild($icon)->addChild($labelledIcon);
        $snap = $this->map($root);

        $p = $this->nodeOf($snap, $panel);
        $this->assertSame(['group', 'Settings'], [$p['role'], $p['label']]);
        $this->assertSame([$this->nodeOf($snap, $title)['id']], $p['children']);
        $this->assertSame(['label', 'Welcome'], [$this->nodeOf($snap, $title)['role'], $this->nodeOf($snap, $title)['label']]);
        $pb = $this->nodeOf($snap, $bar);
        $this->assertSame(['progress_indicator', 0.25, [0.0, 1.0]], [$pb['role'], $pb['value'], $pb['range']]);
        $this->assertSame('Coin', $this->nodeOf($snap, $labelledIcon)['label']);
        $roles = array_column($snap->nodes, 'role');
        $this->assertSame(1, count(array_filter($roles, fn ($r) => $r === 'image')), 'an unlabelled image and an empty label are left out');
    }

    public function testHiddenWidgetsAreLeftOutDisabledOnesAreMarked(): void
    {
        $hidden = (new Button('hidden'))->hide();
        $disabled = (new Button('off'))->disable();
        $root = (new VBox())->addChild($hidden)->addChild($disabled);
        $snap = $this->map($root);

        $this->assertCount(2, $snap->nodes);
        $this->assertTrue($this->nodeOf($snap, $disabled)['disabled']);
    }

    public function testIdsAreStableAcrossRebuildsAndSiblingOrder(): void
    {
        $build = function (): array {
            $a = new Button('a');
            $b = new Button('b');

            return [(new VBox())->addChild($a)->addChild($b), $a, $b];
        };
        [$rootOne, , $bOne] = $build();
        [$rootTwo, , $bTwo] = $build();

        $one = $this->nodeOf($this->map($rootOne), $bOne)['id'];
        $two = $this->nodeOf($this->map($rootTwo), $bTwo)['id'];

        $this->assertSame($one, $two, 'the id comes from the position, not the object');
    }

    public function testExplicitIdWinsAndDuplicatesGetASuffix(): void
    {
        $a = new Button('a');
        $a->accessibleId = 'ok';
        $b = new Button('b');
        $b->accessibleId = 'ok';
        $snap = $this->map((new VBox())->addChild($a)->addChild($b));

        $this->assertSame('id:ok', $this->nodeOf($snap, $a)['id']);
        $this->assertSame('id:ok#2', $this->nodeOf($snap, $b)['id'], 'vio rejects a repeated id');
    }

    public function testBoundsGoThroughTheWindowTransformAndAreRounded(): void
    {
        $button = (new Button('b'))->size(Sizing::fixed(100, 40));
        $root = (new VBox())->addChild($button);

        $snap = $this->map($root, fn (Rect $r) => new Rect($r->x * 1.5 + 10.04, $r->y * 1.5 + 5.0, $r->width * 1.5, $r->height * 1.5));

        $this->assertSame([10.0, 5.0, 150.0, 60.0], $this->nodeOf($snap, $button)['bounds']);
    }

    public function testFocusFollowsTheFocusedWidgetAndATabViewFocusesItsSelectedTab(): void
    {
        $button = new Button('b');
        $tabs = new TabView();
        $tabs->tabs = ['One', 'Two'];
        $tabs->addChild(new VBox())->addChild(new VBox());
        $tabs->selectedIndex = 1;
        $root = (new VBox())->addChild($button)->addChild($tabs);
        $tree = $this->tree($root);
        $mapper = new WidgetAccessibilityMapper();

        $this->assertNull($mapper->map($tree)->focusId);

        $tree->setFocus($button, true);
        $snap = $mapper->map($tree);
        $this->assertSame($this->nodeOf($snap, $button)['id'], $snap->focusId);

        $tree->setFocus($tabs, true);
        $snap = $mapper->map($tree);
        $this->assertSame([$tabs, 'tab', 1], $snap->targets[$snap->focusId]);
    }
}
