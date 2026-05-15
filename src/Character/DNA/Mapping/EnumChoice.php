<?php

declare(strict_types=1);

namespace PHPolygon\Character\DNA\Mapping;

/**
 * Gene mapping that selects an enum case by codon modulo case-count.
 * Note: non-power-of-two case counts produce slight bias; acceptable for v1.
 */
final readonly class EnumChoice extends GeneMapping
{
    /** @param class-string<\UnitEnum> $enumClass */
    public function __construct(public string $enumClass)
    {
        if (!enum_exists($enumClass)) {
            throw new \InvalidArgumentException("Not an enum: {$enumClass}");
        }
    }

    public function map(int $codon): \UnitEnum
    {
        $cases = ($this->enumClass)::cases();
        return $cases[$codon % count($cases)];
    }
}
