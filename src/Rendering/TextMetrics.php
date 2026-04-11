<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

/**
 * Holds the result of a text measurement operation.
 */
final class TextMetrics
{
    public function __construct(
        public readonly float $width,
        public readonly float $height,
    ) {}
}
