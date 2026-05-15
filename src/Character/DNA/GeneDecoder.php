<?php

declare(strict_types=1);

namespace PHPolygon\Character\DNA;

/**
 * Reflection-based decoder that builds a trait class instance from a CharacterDNA strand.
 * Constructor parameters marked with #[Gene] are populated via their mapping;
 * parameters without the attribute are skipped (must therefore have defaults).
 */
final class GeneDecoder
{
    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function decode(CharacterDNA $dna, string $class): object
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            throw new \LogicException("{$class} has no constructor");
        }

        $args = [];
        foreach ($constructor->getParameters() as $param) {
            $attrs = $param->getAttributes(Gene::class);
            if ($attrs === []) {
                continue;
            }
            $gene = $attrs[0]->newInstance();
            $args[$param->getName()] = $gene->mapping->map($dna->codon($gene->locus));
        }

        return $reflection->newInstance(...$args);
    }
}
