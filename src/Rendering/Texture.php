<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

class Texture
{
    public function __construct(
        public readonly int $glId,
        public readonly int $width,
        public readonly int $height,
        public readonly string $path = '',
        public int $nvgImageId = 0,
    ) {}
}
