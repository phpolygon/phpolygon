<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Character\DNA\Mapping;

use PHPolygon\Character\DNA\CharacterDNA;
use PHPolygon\Character\DNA\Gene;
use PHPolygon\Character\DNA\GeneDecoder;
use PHPolygon\Character\DNA\Mapping\Palette;
use PHPUnit\Framework\TestCase;

class PaletteTest extends TestCase
{
    public function testRejectsEmptyValueList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Palette([]);
    }

    public function testReindexesAssociativeKeysToList(): void
    {
        $palette = new Palette(['x' => 'foo', 'y' => 'bar']);

        $this->assertSame(['foo', 'bar'], $palette->values);
    }

    public function testWrapsCodonAroundValueCount(): void
    {
        $palette = new Palette(['a', 'b', 'c']);

        $this->assertSame('a', $palette->map(0));
        $this->assertSame('b', $palette->map(1));
        $this->assertSame('c', $palette->map(2));
        $this->assertSame('a', $palette->map(3));   // wraps
        $this->assertSame('b', $palette->map(4));
        $this->assertSame('a', $palette->map(63));  // 63 mod 3 == 0
    }

    public function testSingleEntryAlwaysReturnsThatEntry(): void
    {
        $palette = new Palette(['only']);

        for ($codon = 0; $codon < 64; $codon++) {
            $this->assertSame('only', $palette->map($codon));
        }
    }

    public function testSupportsMixedValueTypes(): void
    {
        $palette = new Palette([42, 'string', 3.14, true]);

        $this->assertSame(42, $palette->map(0));
        $this->assertSame('string', $palette->map(1));
        $this->assertSame(3.14, $palette->map(2));
        $this->assertSame(true, $palette->map(3));
    }

    public function testIntegratesWithGeneDecoderForCustomTraits(): void
    {
        // Demonstrates the canonical use case: a game defines its own trait
        // class that pulls one or more loci off the DNA strand through a
        // Palette mapping. The palette values are not constrained to enums
        // - here we use plain strings (e.g. mesh IDs that a game would feed
        // straight into MeshRegistry::get()).
        $dna = CharacterDNA::random();
        $trait = (new GeneDecoder())->decode($dna, OutfitTraitFixture::class);

        $this->assertContains($trait->outfit, ['rags', 'tunic', 'armor', 'robe']);
    }
}

/**
 * Test-only trait class demonstrating Palette + GeneDecoder integration.
 * Not part of the engine surface.
 */
final readonly class OutfitTraitFixture
{
    public function __construct(
        #[Gene(0, new Palette(['rags', 'tunic', 'armor', 'robe']))]
        public string $outfit,
    ) {}
}
