<?php

declare(strict_types=1);

namespace PHPolygon\Character\DNA\Mapping;

/**
 * Strategy that decodes a codon (0..63) into a typed property value.
 * Concrete subclasses define the codomain (continuous range, palette, enum).
 */
abstract readonly class GeneMapping
{
    /** Map a codon value (0..63) to a property value. */
    abstract public function map(int $codon): mixed;
}
