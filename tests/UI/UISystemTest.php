<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI;

use PHPUnit\Framework\TestCase;
use PHPolygon\ECS\World;
use PHPolygon\UI\UIContext;
use PHPolygon\UI\UISystem;

class UISystemTest extends TestCase
{
    public function testAddAndRemoveLayer(): void
    {
        $system = $this->createUISystem();

        $system->addLayer('hud', fn(UIContext $ctx) => null);
        $this->assertTrue($system->hasLayer('hud'));

        $system->removeLayer('hud');
        $this->assertFalse($system->hasLayer('hud'));
    }

    public function testLayersCalledInOrder(): void
    {
        $system = $this->createUISystem();
        $calls = [];

        $system->addLayer('overlay', function () use (&$calls) { $calls[] = 'overlay'; }, 10);
        $system->addLayer('hud', function () use (&$calls) { $calls[] = 'hud'; }, 0);
        $system->addLayer('debug', function () use (&$calls) { $calls[] = 'debug'; }, 20);

        $system->render(new World());

        $this->assertEquals(['hud', 'overlay', 'debug'], $calls);
    }

    public function testRenderWithNoLayers(): void
    {
        $system = $this->createUISystem();
        // Should not throw
        $system->render(new World());
        $this->assertTrue(true);
    }

    public function testGetContext(): void
    {
        $system = $this->createUISystem();
        $this->assertInstanceOf(UIContext::class, $system->getContext());
    }

    private function createUISystem(): UISystem
    {
        // Use a minimal mock — UISystem only calls render methods on layers,
        // and the mock Renderer2DInterface satisfies the constructor.
        $renderer = new class implements \PHPolygon\Rendering\Renderer2DInterface {
            public function beginFrame(): void {}
            public function endFrame(): void {}
            public function clear(\PHPolygon\Rendering\Color $color): void {}
            public function setViewport(int $x, int $y, int $width, int $height): void {}
            public function getWidth(): int { return 1280; }
            public function getHeight(): int { return 720; }
            public function drawRect(float $x, float $y, float $w, float $h, \PHPolygon\Rendering\Color $color): void {}
            public function drawRectOutline(float $x, float $y, float $w, float $h, \PHPolygon\Rendering\Color $color, float $lineWidth = 1.0): void {}
            public function drawRoundedRect(float $x, float $y, float $w, float $h, float $radius, \PHPolygon\Rendering\Color $color): void {}
            public function drawCircle(float $cx, float $cy, float $r, \PHPolygon\Rendering\Color $color): void {}
            public function drawCircleOutline(float $cx, float $cy, float $r, \PHPolygon\Rendering\Color $color, float $lineWidth = 1.0): void {}
            public function drawLine(\PHPolygon\Math\Vec2 $from, \PHPolygon\Math\Vec2 $to, \PHPolygon\Rendering\Color $color, float $width = 1.0): void {}
            public function drawText(string $text, float $x, float $y, float $size, \PHPolygon\Rendering\Color $color): void {}
            public function drawTextCentered(string $text, float $cx, float $cy, float $size, \PHPolygon\Rendering\Color $color): void {}
            public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, \PHPolygon\Rendering\Color $color): void {}
            public function drawSprite(\PHPolygon\Rendering\Texture $texture, ?\PHPolygon\Math\Rect $srcRegion, float $x, float $y, float $w, float $h, float $opacity = 1.0): void {}
            public function pushTransform(\PHPolygon\Math\Mat3 $matrix): void {}
            public function popTransform(): void {}
            public function pushScissor(float $x, float $y, float $w, float $h): void {}
            public function popScissor(): void {}
            public function loadFont(string $name, string $path): void {}
            public function setFont(string $name): void {}
        };

        return new UISystem($renderer, new \PHPolygon\Runtime\Input());
    }
}
