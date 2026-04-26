<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

/**
 * Clears the depth buffer without touching the color buffer.
 *
 * Used between render layers so that later layers (e.g. HUD)
 * are not occluded by earlier 3D geometry.
 */
readonly class ClearDepthBuffer
{
    public function __construct() {}
}
