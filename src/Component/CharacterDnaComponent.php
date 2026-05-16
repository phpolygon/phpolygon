<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\Character\DNA\CharacterDNA;
use PHPolygon\Character\DNA\GeneDecoder;
use PHPolygon\Character\DNA\PlayerProportions;
use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Attaches a 72-base DNA strand to an entity. The strand is the canonical state;
 * the decoded {@see PlayerProportions} and underlying {@see CharacterDNA} are
 * cached on first access.
 *
 * Save-game compatible: only the 72-character ACGT string is serialised.
 *
 * Typical usage:
 *
 *     $entity->attach(new Transform3D(position: $pos))
 *            ->attach(new CharacterDnaComponent(CharacterDNA::random()));
 *
 *     CharacterMeshBuilder::buildOn($world, $entity);
 */
#[Serializable]
#[Category('Character')]
class CharacterDnaComponent extends AbstractComponent
{
    #[Property(description: '72-character ACGT strand')]
    public string $acgt = '';

    private ?CharacterDNA $dnaCache = null;
    private ?PlayerProportions $proportionsCache = null;

    public function __construct(string|CharacterDNA|null $dna = null)
    {
        if ($dna === null) {
            $dna = CharacterDNA::random();
        }
        if ($dna instanceof CharacterDNA) {
            $this->dnaCache = $dna;
            $this->acgt = $dna->toAcgt();
        } else {
            $this->acgt = $dna;
        }
    }

    /** Decoded DNA strand. Cached on first call. */
    public function dna(): CharacterDNA
    {
        return $this->dnaCache ??= CharacterDNA::fromAcgt($this->acgt);
    }

    /** Decoded player proportions. Cached on first call. */
    public function proportions(?GeneDecoder $decoder = null): PlayerProportions
    {
        return $this->proportionsCache ??= ($decoder ?? new GeneDecoder())
            ->decode($this->dna(), PlayerProportions::class);
    }

    /**
     * Decode the strand into a custom trait class with #[Gene] attributes.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function decodeAs(string $class, ?GeneDecoder $decoder = null): object
    {
        return ($decoder ?? new GeneDecoder())->decode($this->dna(), $class);
    }

    /**
     * Replace the strand. Clears the decoded caches.
     */
    public function setDna(string|CharacterDNA $dna): void
    {
        $this->dnaCache = $dna instanceof CharacterDNA ? $dna : null;
        $this->acgt = $dna instanceof CharacterDNA ? $dna->toAcgt() : $dna;
        $this->proportionsCache = null;
    }
}
