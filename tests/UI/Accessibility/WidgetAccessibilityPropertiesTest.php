<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Accessibility;

use PHPUnit\Framework\TestCase;
use PHPolygon\Runtime\Input;
use PHPolygon\Tests\UI\Widget\WidgetTestHelper;
use PHPolygon\UI\Accessibility\AccessibilityBridge;
use PHPolygon\UI\Accessibility\AccessibilitySnapshot;
use PHPolygon\UI\Accessibility\WidgetAccessibilityMapper;
use PHPolygon\UI\UIStyle;
use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\HBox;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Panel;
use PHPolygon\UI\Widget\Repeater;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\UI\Widget\Widget;
use PHPolygon\UI\Widget\WidgetCodeGenerator;
use PHPolygon\UI\Widget\WidgetSerializer;
use PHPolygon\UI\Widget\WidgetTree;

class WidgetAccessibilityPropertiesTest extends TestCase
{
    private function snapshot(Widget $root): AccessibilitySnapshot
    {
        $tree = new WidgetTree($root, new WidgetTestHelper(), new Input(), 800, 600, UIStyle::dark());
        $tree->performLayout();

        return (new WidgetAccessibilityMapper())->map($tree);
    }

    /** @return list<class-string<Widget>> */
    private function widgetClasses(): array
    {
        $classes = [];
        foreach (glob(__DIR__ . '/../../../src/UI/Widget/*.php') ?: [] as $file) {
            $class = 'PHPolygon\\UI\\Widget\\' . basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, Widget::class) && !(new \ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }

    public function testEveryWidgetTypeRoundTripsTheAccessibilityProperties(): void
    {
        $serializer = new WidgetSerializer();
        $classes = $this->widgetClasses();
        $this->assertGreaterThan(15, count($classes));

        foreach ($classes as $class) {
            $widget = $serializer->fromArray([
                '_widget' => $class,
                'accessibleLabel' => 'Name',
                'accessibleDescription' => 'What it does',
                'accessibleRole' => 'group',
                'accessibleId' => 'the-id',
                'focusable' => true,
                'focusOrder' => 4,
            ]);
            $copy = $serializer->fromArray($serializer->toArray($widget));

            $this->assertSame('Name', $copy->accessibleLabel, $class);
            $this->assertSame('What it does', $copy->accessibleDescription, $class);
            $this->assertSame('group', $copy->accessibleRole, $class);
            $this->assertSame('the-id', $copy->accessibleId, $class);
            $this->assertTrue($copy->focusable, $class);
            $this->assertSame(4, $copy->focusOrder, $class);
        }
    }

    public function testGeneratedPhpKeepsTheAccessibilityProperties(): void
    {
        $button = new Button('X');
        $button->accessibleLabel = 'Close the dialog';
        $button->accessibleDescription = 'Discards your changes';
        $button->accessibleRole = 'link';
        $panel = (new Panel('p'))->addChild($button);
        $tree = (new WidgetSerializer())->toArray($panel);

        $ns = 'Gen\\A11y' . substr(md5(uniqid('', true)), 0, 10);
        $php = (new WidgetCodeGenerator())->generate($tree, 'Layout', $ns);
        $file = tempnam(sys_get_temp_dir(), 'phpolygon-a11y-') . '.php';
        file_put_contents($file, $php);
        try {
            require $file;
            /** @var class-string $fqcn */
            $fqcn = $ns . '\\Layout';
            $rebuilt = $fqcn::build();
        } finally {
            @unlink($file);
        }

        $copy = $rebuilt->getChildren()[0];
        $this->assertSame('Close the dialog', $copy->accessibleLabel);
        $this->assertSame('Discards your changes', $copy->accessibleDescription);
        $this->assertSame('link', $copy->accessibleRole);
    }

    public function testRoleOverrideChangesTheRoleAndKeepsTheLabel(): void
    {
        $title = new Label('Settings');
        $title->accessibleRole = 'heading';
        $snap = $this->snapshot((new VBox())->addChild($title));

        $node = array_values(array_filter($snap->nodes, fn (array $n) => $n['id'] !== 'root'))[0];

        $this->assertSame(['heading', 'Settings'], [$node['role'], $node['label']]);
    }

    public function testUnknownRoleIsIgnored(): void
    {
        $button = new Button('Go');
        $button->accessibleRole = 'spaceship';
        $snap = $this->snapshot((new VBox())->addChild($button));

        $this->assertSame('button', array_values(array_filter($snap->nodes, fn (array $n) => $n['id'] !== 'root'))[0]['role']);
    }

    public function testListContainerMapsItsRowsToListItems(): void
    {
        $list = new VBox();
        $list->accessibleRole = 'list';
        $list->accessibleLabel = 'Projects';
        $rowOne = (new HBox())->addChild(new Label('Alpha'))->addChild(new Button('Open'));
        $rowTwo = (new HBox())->addChild(new Label('Beta'));
        $list->addChild($rowOne)->addChild($rowTwo);
        $snap = $this->snapshot((new VBox())->addChild($list));

        $listNode = array_values(array_filter($snap->nodes, fn (array $n) => ($n['role'] ?? '') === 'list'))[0];
        $this->assertSame('Projects', $listNode['label']);
        $this->assertCount(2, $listNode['children']);
        $first = $snap->nodes[$listNode['children'][0]];
        $this->assertSame('list_item', $first['role']);
        $this->assertCount(2, $first['children'], 'the row keeps its label and button');
        $this->assertSame('list_item', $snap->nodes[$listNode['children'][1]]['role']);
    }

    public function testRepeaterCanBeMarkedAsAList(): void
    {
        $repeater = new Repeater();
        $repeater->accessibleRole = 'list';
        $repeater->addChild((new HBox())->addChild(new Label('row')));
        $snap = $this->snapshot((new VBox())->addChild($repeater));

        $roles = array_column($snap->nodes, 'role');
        $this->assertContains('list', $roles);
        $this->assertContains('list_item', $roles);
    }

    public function testToastStyleAnnouncementNeedsNoFocusChange(): void
    {
        // A status message such as "Research finished" goes through announce(); it must not touch focus.
        $sink = new RecordingAccessibilitySink();
        $bridge = new AccessibilityBridge($sink, 'Game');
        $tree = new WidgetTree((new VBox())->addChild(new Button('OK')), new WidgetTestHelper(), new Input(), 800, 600, UIStyle::dark());
        $tree->performLayout();
        $bridge->sync($tree);
        $focusBefore = $sink->focus;

        $bridge->announce('Research finished: Compilers');

        $this->assertSame($focusBefore, $sink->focus);
        $this->assertSame('Research finished: Compilers', $sink->tree[AccessibilityBridge::LIVE_POLITE]['label']);
    }
}
