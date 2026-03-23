<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat3;
use PHPolygon\Math\Rect;
use PHPolygon\Math\Vec2;

interface Renderer2DInterface extends RenderContextInterface
{
    public function drawRect(float $x, float $y, float $w, float $h, Color $color): void;

    public function drawRectOutline(float $x, float $y, float $w, float $h, Color $color, float $lineWidth = 1.0): void;

    public function drawRoundedRect(float $x, float $y, float $w, float $h, float $radius, Color $color): void;

    public function drawCircle(float $cx, float $cy, float $r, Color $color): void;

    public function drawCircleOutline(float $cx, float $cy, float $r, Color $color, float $lineWidth = 1.0): void;

    public function drawLine(Vec2 $from, Vec2 $to, Color $color, float $width = 1.0): void;

    public function drawText(string $text, float $x, float $y, float $size, Color $color): void;

    public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, Color $color): void;

    public function drawSprite(Texture $texture, ?Rect $srcRegion, float $x, float $y, float $w, float $h, float $opacity = 1.0): void;

    public function pushTransform(Mat3 $matrix): void;

    public function popTransform(): void;

    public function pushScissor(float $x, float $y, float $w, float $h): void;

    public function popScissor(): void;

    public function loadFont(string $name, string $path): void;

    public function setFont(string $name): void;
}
