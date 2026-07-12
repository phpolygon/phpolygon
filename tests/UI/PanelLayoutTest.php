<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPolygon\Math\Rect;
use PHPolygon\UI\PanelLayout;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PanelLayoutTest extends TestCase
{
    private function sample(): PanelLayout
    {
        return PanelLayout::fromArray([
            'name' => 'main_menu',
            'elements' => [
                'play' => ['x' => 540, 'y' => 300, 'width' => 200, 'height' => 48, 'label' => 'menu.play'],
                'quit' => ['x' => 540, 'y' => 360, 'width' => 200, 'height' => 48, 'enabled' => false],
            ],
        ]);
    }

    public function test_reads_rect_and_props(): void
    {
        $layout = $this->sample();

        $r = $layout->rect('play');
        $this->assertSame(540.0, $r->x);
        $this->assertSame(48.0, $r->height);
        $this->assertSame('menu.play', $layout->str('play', 'label'));
        $this->assertFalse($layout->bool('quit', 'enabled', true));
        $this->assertTrue($layout->has('play'));
        $this->assertEqualsCanonicalizing(['play', 'quit'], $layout->ids());
    }

    public function test_unknown_element_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->sample()->rect('nope');
    }

    public function test_missing_prop_returns_default(): void
    {
        $layout = $this->sample();
        $this->assertSame('x', $layout->str('play', 'style', 'x'));
        $this->assertSame(5, $layout->int('play', 'z', 5));
    }

    public function test_mutation_set_move_rename_remove(): void
    {
        $layout = $this->sample();

        $layout->set('play', ['label' => 'menu.start']);
        $this->assertSame('menu.start', $layout->str('play', 'label'));

        $layout->setRect('play', new Rect(10, 20, 100, 40));
        $this->assertSame(10.0, $layout->rect('play')->x);

        $layout->rename('play', 'start');
        $this->assertFalse($layout->has('play'));
        $this->assertTrue($layout->has('start'));

        $layout->remove('quit');
        $this->assertFalse($layout->has('quit'));
    }

    public function test_file_round_trip(): void
    {
        $path = sys_get_temp_dir().'/phpolygon_panel_'.uniqid().'.layout.json';
        try {
            $this->sample()->saveFile($path);
            $reloaded = PanelLayout::loadFile($path);

            $this->assertSame('main_menu', $reloaded->getName());
            $this->assertSame(300.0, $reloaded->rect('play')->y);
            $this->assertSame('menu.play', $reloaded->str('play', 'label'));
        } finally {
            @unlink($path);
        }
    }

    public function test_load_missing_file_throws(): void
    {
        $this->expectException(RuntimeException::class);
        PanelLayout::loadFile(sys_get_temp_dir().'/nope_'.uniqid().'.layout.json');
    }
}
