<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPolygon\UI\Widget\Button;
use PHPolygon\UI\Widget\Panel;
use PHPolygon\UI\Widget\VBox;
use PHPolygon\UI\Widget\WidgetLayout;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class WidgetLayoutTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/phpolygon_layout_'.uniqid().'.ui.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function test_save_and_load_round_trip(): void
    {
        $root = new VBox(spacing: 8.0);
        $panel = new Panel('Menu');
        $panel->addChild(new Button('Play'));
        $root->addChild($panel);

        WidgetLayout::saveFile('main_menu', $root, $this->path);
        $this->assertFileExists($this->path);

        $restored = WidgetLayout::loadFile($this->path);
        $this->assertInstanceOf(VBox::class, $restored);
        $this->assertSame(8.0, $restored->spacing);

        $restoredPanel = $restored->getChildren()[0];
        $this->assertInstanceOf(Panel::class, $restoredPanel);
        $this->assertSame('Menu', $restoredPanel->title);

        $button = $restoredPanel->getChildren()[0];
        $this->assertInstanceOf(Button::class, $button);
        $this->assertSame('Play', $button->label);
    }

    public function test_from_array_accepts_bare_node(): void
    {
        $widget = WidgetLayout::fromArray(['_widget' => Button::class, 'label' => 'X']);
        $this->assertInstanceOf(Button::class, $widget);
        $this->assertSame('X', $widget->label);
    }

    public function test_missing_file_throws(): void
    {
        $this->expectException(RuntimeException::class);
        WidgetLayout::loadFile(sys_get_temp_dir().'/nope_'.uniqid().'.ui.json');
    }
}
