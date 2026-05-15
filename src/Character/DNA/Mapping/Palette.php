<?php

declare(strict_types=1);

namespace PHPolygon\Character\DNA\Mapping;

/**
 * Gene mapping that selects from a fixed list of values by codon modulo count.
 * Useful for arbitrary value sets that are not backed by an enum.
 */
final readonly class Palette extends GeneMapping
{
    /** @var list<mixed> */
    public array $values;

    /**
     * @param array<array-key, mixed> $values Non-empty list of palette values.
     */
    public function __construct(array $values)
    {
        if ($values === []) {
            throw new \InvalidArgumentException('Palette must not be empty');
        }
        $this->values = array_values($values);
    }

    public function map(int $codon): mixed
    {
        return $this->values[$codon % count($this->values)];
    }
}
