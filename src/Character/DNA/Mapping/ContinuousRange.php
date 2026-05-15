<?php

declare(strict_types=1);

namespace PHPolygon\Character\DNA\Mapping;

/**
 * Linear gene mapping that interpolates a codon value across a continuous float range [min, max].
 * Codon 0 maps to min, codon 63 to max.
 */
final readonly class ContinuousRange extends GeneMapping
{
    public function __construct(
        public float $min,
        public float $max,
    ) {}

    public function map(int $codon): float
    {
        return $this->min + ($codon / 63.0) * ($this->max - $this->min);
    }
}
