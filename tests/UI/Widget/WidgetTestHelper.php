<?php

declare(strict_types=1);

namespace PHPolygon\Tests\UI\Widget;

use PHPolygon\Math\Mat3;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\Texture;

/**
 * Null renderer for widget tests — records draw calls.
 */
class WidgetTestHelper implements Renderer2DInterface
{
    /** @var list<array{method: string, args: array}> */
    public array $calls = [];

    public function reset(): void { $this->calls = []; }

    public function beginFrame(): void {}
    public function endFrame(): void {}
    public function clear(Color $color): void {}
    public function setViewport(int $x, int $y, int $width, int $height): void {}
    public function getWidth(): int { return 1280; }
    public function getHeight(): int { return 720; }
    public function drawRect(float $x, float $y, float $w, float $h, Color $color): void { $this->calls[] = ['method' => 'drawRect', 'args' => func_get_args()]; }
    public function drawRectOutline(float $x, float $y, float $w, float $h, Color $color, float $lineWidth = 1.0): void { $this->calls[] = ['method' => 'drawRectOutline', 'args' => func_get_args()]; }
    public function drawRoundedRect(float $x, float $y, float $w, float $h, float $radius, Color $color): void { $this->calls[] = ['method' => 'drawRoundedRect', 'args' => func_get_args()]; }
    public function drawCircle(float $cx, float $cy, float $r, Color $color): void { $this->calls[] = ['method' => 'drawCircle', 'args' => func_get_args()]; }
    public function drawCircleOutline(float $cx, float $cy, float $r, Color $color, float $lineWidth = 1.0): void {}
    public function drawLine(Vec2 $from, Vec2 $to, Color $color, float $width = 1.0): void {}
    public function drawText(string $text, float $x, float $y, float $size, Color $color): void { $this->calls[] = ['method' => 'drawText', 'args' => func_get_args()]; }
    public function drawTextCentered(string $text, float $cx, float $cy, float $size, Color $color): void { $this->calls[] = ['method' => 'drawTextCentered', 'args' => func_get_args()]; }
    public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, Color $color): void {}
    public function drawSprite(Texture $texture, ?Rect $srcRegion, float $x, float $y, float $w, float $h, float $opacity = 1.0): void { $this->calls[] = ['method' => 'drawSprite', 'args' => func_get_args()]; }
    public function pushTransform(Mat3 $matrix): void {}
    public function popTransform(): void {}
    public function pushScissor(float $x, float $y, float $w, float $h): void { $this->calls[] = ['method' => 'pushScissor', 'args' => func_get_args()]; }
    public function popScissor(): void { $this->calls[] = ['method' => 'popScissor', 'args' => []]; }
    public function loadFont(string $name, string $path): void {}
    public function setFont(string $name): void {}
}
