<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

readonly class SetSnowCover
{
    public function __construct(
        public float $cover = 0.0,
    ) {}
}
