<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Math\Mat4;

readonly class SetCamera
{
    public function __construct(
        public Mat4 $viewMatrix,
        public Mat4 $projectionMatrix,
        /**
         * The view does not continue the previous frame (first frame, teleport,
         * camera switch): temporal passes must drop their history instead of
         * reprojecting it. See {@see \PHPolygon\Rendering\CameraCutDetector}.
         */
        public bool $cut = false,
    ) {}
}
