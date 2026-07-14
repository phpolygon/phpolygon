<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPolygon\Rendering\Color;
use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\DataWidgetContext;
use PHPolygon\UI\Widget\EdgeInsets;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Panel;
use PHPolygon\UI\Widget\Repeater;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\UI\Widget\Widget;
use PHPolygon\UI\Widget\WidgetBinder;
use PHPolygon\UI\Widget\WidgetCodeGenerator;
use PHPolygon\UI\Widget\WidgetSerializer;
use PHPUnit\Framework\TestCase;

class WidgetCodeGeneratorTest extends TestCase
{
    private function sampleTree(): VBox
    {
        $root = new VBox(spacing: 10.0);
        $root->padding = EdgeInsets::all(12.0);

        $panel = new Panel('Menu');
        $label = new Label('Hi');
        $label->fontSize = 20.0;
        $label->color = new Color(0.9, 0.8, 0.1, 1.0);
        $button = new Button('Play');
        $button->enabled = false;

        $panel->addChild($label);
        $panel->addChild($button);
        $root->addChild($panel);

        return $root;
    }

    public function test_generates_a_build_factory(): void
    {
        $tree = (new WidgetSerializer)->toArray($this->sampleTree());
        $php = (new WidgetCodeGenerator)->generate($tree, 'MainMenuLayout', 'Acme\\Ui');

        $this->assertStringContainsString('namespace Acme\\Ui;', $php);
        $this->assertStringContainsString('final class MainMenuLayout', $php);
        $this->assertStringContainsString('public static function build(): Widget', $php);
        $this->assertStringContainsString("new Panel(title: 'Menu')", $php);
        $this->assertStringContainsString('->addChild(', $php);
    }

    public function test_generated_php_rebuilds_the_same_tree(): void
    {
        $serializer = new WidgetSerializer;
        $tree = $serializer->toArray($this->sampleTree());

        // Generate into a unique namespace, eval it, and rebuild.
        $ns = 'Gen\\Wc'.substr(md5(uniqid('', true)), 0, 10);
        $php = (new WidgetCodeGenerator)->generate($tree, 'Layout', $ns);

        $file = tempnam(sys_get_temp_dir(), 'phpolygon-wc-').'.php';
        file_put_contents($file, $php);
        try {
            require $file;
            /** @var class-string $fqcn */
            $fqcn = $ns.'\\Layout';
            $rebuilt = $fqcn::build();
        } finally {
            @unlink($file);
        }

        $this->assertInstanceOf(Widget::class, $rebuilt);
        // Re-serializing the rebuilt tree must match the original exactly.
        $this->assertSame($tree, $serializer->toArray($rebuilt));
    }

    public function test_generated_php_preserves_bindings_and_events(): void
    {
        $tree = [
            '_widget' => VBox::class,
            'spacing' => 6.0,
            'children' => [
                ['_widget' => Label::class, 'text' => ['$bind' => 'greeting'], 'fontSize' => 18.0],
                ['_widget' => Button::class, 'label' => ['$bind' => 'goLabel'], '$on' => ['click' => 'go']],
            ],
        ];

        $root = $this->buildFromArray($tree);

        // The generated tree carries the same bindings/events as the JSON path:
        // compare both through the serializer so default-compaction is symmetric.
        $serializer = new WidgetSerializer;
        $this->assertSame(
            $serializer->toArray($serializer->fromArray($tree)),
            $serializer->toArray($root),
        );

        // And they resolve when bound.
        (new WidgetBinder)->bind($root, new DataWidgetContext(['greeting' => 'Hi', 'goLabel' => 'Go']));
        $label = $root->getChildren()[0];
        $this->assertInstanceOf(Label::class, $label);
        $this->assertSame('Hi', $label->text);
    }

    public function test_repeater_builds_rows_via_factory(): void
    {
        $tree = [
            '_widget' => Repeater::class,
            '$each' => 'items',
            'template' => ['_widget' => Label::class, 'text' => ['$bind' => 'name']],
        ];

        $root = $this->buildFromArray($tree);
        $this->assertInstanceOf(Repeater::class, $root);
        $this->assertSame('items', $root->each);
        $this->assertNotNull($root->templateFactory);
        $this->assertSame([], $root->template, 'template stays empty — the factory builds rows');

        (new WidgetBinder)->bind($root, new DataWidgetContext(['items' => [['name' => 'Alpha'], ['name' => 'Beta']]]));

        $rows = $root->getChildren();
        $this->assertCount(2, $rows);
        $this->assertInstanceOf(Label::class, $rows[0]);
        $this->assertSame('Alpha', $rows[0]->text);
        $this->assertSame('Beta', $rows[1]->text);
    }

    public function test_factory_repeater_recycles_rows_on_stable_count(): void
    {
        $root = $this->buildFromArray([
            '_widget' => Repeater::class,
            '$each' => 'items',
            'template' => ['_widget' => Label::class, 'text' => ['$bind' => 'name']],
        ]);
        $this->assertInstanceOf(Repeater::class, $root);

        $binder = new WidgetBinder;
        $binder->bind($root, new DataWidgetContext(['items' => [['name' => 'A'], ['name' => 'B']]]));
        $first = $root->getChildren();

        // Same count, new data: rows are recycled (same instances) and re-bound.
        $binder->bind($root, new DataWidgetContext(['items' => [['name' => 'X'], ['name' => 'Y']]]));
        $second = $root->getChildren();
        $this->assertSame($first[0], $second[0]);
        $this->assertInstanceOf(Label::class, $second[0]);
        $this->assertSame('X', $second[0]->text);

        // Different count: rebuilt.
        $binder->bind($root, new DataWidgetContext(['items' => [['name' => 'Z']]]));
        $this->assertCount(1, $root->getChildren());
    }

    /**
     * Generate PHP from a serialized tree, eval it, and return the built root.
     *
     * @param  array<string, mixed>  $tree
     */
    private function buildFromArray(array $tree): Widget
    {
        $ns = 'Gen\\Wc'.substr(md5(uniqid('', true)), 0, 10);
        $php = (new WidgetCodeGenerator)->generate($tree, 'Layout', $ns);

        $file = tempnam(sys_get_temp_dir(), 'phpolygon-wc-').'.php';
        file_put_contents($file, $php);
        try {
            require $file;
            /** @var class-string $fqcn */
            $fqcn = $ns.'\\Layout';

            return $fqcn::build();
        } finally {
            @unlink($file);
        }
    }
}
