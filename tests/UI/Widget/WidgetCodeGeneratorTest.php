<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPolygon\Rendering\Color;
use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\EdgeInsets;
use PHPolygon\UI\Widget\Label;
use PHPolygon\UI\Widget\Panel;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\UI\Widget\Widget;
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
}
