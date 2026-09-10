<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Color;

/**
 * Draw a screen-space outline around one mesh instance.
 *
 * All outline commands of a frame are drawn together after the scene: first
 * every mesh is written into the stencil buffer, then every mesh is redrawn
 * pushed outward by $widthPx pixels wherever the stencil is still empty. Parts
 * of one object therefore share a single outline without seams between them.
 * Backends without a stencil buffer skip the pass.
 */
readonly class DrawOutline
{
    public function __construct(
        public string $meshId,
        public Mat4 $modelMatrix,
        public Color $color,
        public float $widthPx = 3.0,
    ) {}
}
