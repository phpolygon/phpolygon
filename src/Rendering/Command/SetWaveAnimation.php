<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

readonly class SetWaveAnimation
{
    public function __construct(
        public bool $enabled = false,
        public float $amplitude = 0.3,
        public float $frequency = 0.5,
        public float $phase = 0.0,
    ) {}
}
