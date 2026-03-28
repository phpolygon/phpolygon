<?php

declare(strict_types=1);

namespace PHPolygon;

use PHPolygon\Thread\ThreadingMode;

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
        public readonly string $defaultLocale = 'en',
        public readonly string $fallbackLocale = 'en',
        public readonly string $savePath = 'saves',
        public readonly int $maxSaveSlots = 10,
        public readonly bool $headless = false,
        public readonly bool $is3D = false,
        public readonly string $renderBackend3D = 'opengl',
        public readonly ?ThreadingMode $threadingMode = null,
    ) {}
}
