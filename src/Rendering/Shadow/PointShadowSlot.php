<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Shadow;

use PHPolygon\Rendering\Command\AddPointLight;

/** One shadowed point light of a frame: the six layers `slot * 6 .. slot * 6 + 5` hold its cube. */
final readonly class PointShadowSlot
{
    public function __construct(
        public int $slot,
        public AddPointLight $light,
        /** Face size in texels the cube is rendered at (the layers may be larger). */
        public int $size,
        /** {@see PointShadowPlanner::lightKey()} */
        public string $key,
    ) {}
}
