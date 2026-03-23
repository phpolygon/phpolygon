<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

class AudioClip
{
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly float $duration = 0.0,
    ) {}
}
