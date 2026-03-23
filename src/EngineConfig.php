<?php

declare(strict_types=1);

namespace PHPolygon;

class EngineConfig
{
    public function __construct(
        public readonly string $title = 'PHPolygon',
        public readonly int $width = 1280,
        public readonly int $height = 720,
        public readonly bool $vsync = true,
        public readonly bool $resizable = true,
        public readonly float $targetTickRate = 60.0,
        public readonly string $assetsPath = '',
    ) {}
}
