<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Rendering\Color;

/**
 * Render command for text at a screen-projected 3D position.
 *
 * The 3D system projects the world position and emits this command.
 * The 2D renderer consumes it to draw text on screen.
 *
 * @deprecated No renderer consumes this command. Text on an object is a
 *             {@see \PHPolygon\Component\SurfaceText}, a marker drawn in 2D
 *             projects itself with {@see \PHPolygon\Rendering\ScreenProjection}.
 */
readonly class DrawWorldText
{
    public function __construct(
        public string $text,
        public float $screenX,
        public float $screenY,
        public float $fontSize,
        public Color $color,
        public string $fontId,
        public int $textAlign,
    ) {}
}
