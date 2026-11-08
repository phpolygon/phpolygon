<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * A 2D renderer that can draw a GPU image the engine made - a minimap
 * ({@see VioRenderer3D::minimapTexture()}), a render target's texture - in the 2D
 * draw order (shader plan 8.1). VioRenderer2D draws it; ScaledRenderer2D forwards it
 * in its virtual coordinates. Games test for the interface and skip the image elsewhere.
 */
interface GpuImageRenderer2DInterface
{
    /** srcW / srcH = the image's pixel size. */
    public function drawGpuImage(\VioTexture $texture, float $srcW, float $srcH, float $x, float $y, float $w, float $h, float $opacity = 1.0): void;
}
