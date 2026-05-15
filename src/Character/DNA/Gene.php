<?php

declare(strict_types=1);

namespace PHPolygon\Character\DNA;

use PHPolygon\Character\DNA\Mapping\GeneMapping;

/**
 * Marks a property or constructor parameter as a gene bound to a DNA locus.
 * Consumed by GeneDecoder via reflection to populate trait classes from a CharacterDNA.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final readonly class Gene
{
    public function __construct(
        public int $locus,
        public GeneMapping $mapping,
    ) {}
}
