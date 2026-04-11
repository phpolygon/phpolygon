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

    public function drawRoundedRectOutline(float $x, float $y, float $w, float $h, float $radius, Color $color, float $lineWidth = 1.0): void;

    public function drawCircle(float $cx, float $cy, float $r, Color $color): void;

    public function drawCircleOutline(float $cx, float $cy, float $r, Color $color, float $lineWidth = 1.0): void;

    public function drawLine(Vec2 $from, Vec2 $to, Color $color, float $width = 1.0): void;

    public function drawText(string $text, float $x, float $y, float $size, Color $color): void;

    public function drawTextCentered(string $text, float $cx, float $cy, float $size, Color $color): void;

    public function drawTextBox(string $text, float $x, float $y, float $breakWidth, float $size, Color $color): void;

    public function drawSprite(Texture $texture, ?Rect $srcRegion, float $x, float $y, float $w, float $h, float $opacity = 1.0): void;

    public function pushTransform(Mat3 $matrix): void;

    public function popTransform(): void;

    public function pushScissor(float $x, float $y, float $w, float $h): void;

    public function popScissor(): void;

    public function loadFont(string $name, string $path): void;

    public function setFont(string $name): void;

    /**
     * Sets the text alignment for subsequent drawText/drawTextCentered/drawTextBox calls.
     * Use TextAlign constants combined with bitwise OR, e.g. TextAlign::CENTER | TextAlign::MIDDLE.
     */
    public function setTextAlign(int $align): void;

    /**
     * Measures the width and height of a single line of text without drawing it.
     */
    public function measureText(string $text, float $size): TextMetrics;

    /**
     * Measures the bounding box of text wrapped at the given breakWidth.
     */
    public function measureTextBox(string $text, float $breakWidth, float $size): TextMetrics;

    /**
     * Registers a fallback font to be used when glyphs are missing from the base font.
     * Primarily used for CJK character support.
     */
    public function addFallbackFont(string $baseFont, string $fallbackFont): void;

    /**
     * Sets the global alpha (opacity) applied to all subsequent draw calls.
     * Value should be between 0.0 (fully transparent) and 1.0 (fully opaque).
     */
    public function setGlobalAlpha(float $alpha): void;

    /**
     * Draws a filled arc (pie slice).
     *
     * @param float $cx Center X
     * @param float $cy Center Y
     * @param float $r Radius
     * @param float $startAngle Start angle in radians
     * @param float $endAngle End angle in radians
     * @param int $direction Winding direction: 0 = counter-clockwise (CCW), 1 = clockwise (CW)
     */
    public function drawArc(float $cx, float $cy, float $r, float $startAngle, float $endAngle, Color $color, int $direction = 0): void;

    /**
     * Saves the current drawing state (transform, scissor, font, alpha, text alignment)
     * onto an internal stack.
     */
    public function saveState(): void;

    /**
     * Restores the most recently saved drawing state from the internal stack.
     */
    public function restoreState(): void;
}
