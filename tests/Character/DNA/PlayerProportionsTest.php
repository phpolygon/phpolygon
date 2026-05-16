<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Character\DNA;

use PHPolygon\Character\DNA\CharacterDNA;
use PHPolygon\Character\DNA\Enum\Accessory;
use PHPolygon\Character\DNA\Enum\EyeColor;
use PHPolygon\Character\DNA\Enum\EyeShape;
use PHPolygon\Character\DNA\Enum\FacialHair;
use PHPolygon\Character\DNA\Enum\HairColor;
use PHPolygon\Character\DNA\Enum\HairStyle;
use PHPolygon\Character\DNA\Enum\NoseShape;
use PHPolygon\Character\DNA\Enum\SkinTone;
use PHPolygon\Character\DNA\GeneDecoder;
use PHPolygon\Character\DNA\PlayerProportions;
use PHPUnit\Framework\TestCase;

/**
 * Pinned snapshot test - guards against accidental changes to the mapping math.
 * If this test starts failing after intentional mapping changes, update the pinned values.
 */
class PlayerProportionsTest extends TestCase
{
    public function testPinnedSnapshotForKnownDna(): void
    {
        // Pinned strand: alternating 0x55 / 0xAA bytes.
        // 0x55 = 01010101 -> bases C,C,C,C (1,1,1,1)
        // 0xAA = 10101010 -> bases G,G,G,G (2,2,2,2)
        $bytes = str_repeat("\x55\xAA", 9);
        $dna = new CharacterDNA($bytes);

        // Verify the building blocks first so the snapshot is interpretable:
        // - Codon 0 covers bases 0..2 = C,C,C = 1 | (1<<2) | (1<<4) = 21
        // - Codon 1 covers bases 3..5 = C,G,G = 1 | (2<<2) | (2<<4) = 41
        // - Codon 2 covers bases 6..8 = G,G,C = 2 | (2<<2) | (1<<4) = 26
        $this->assertSame(21, $dna->codon(0));
        $this->assertSame(41, $dna->codon(1));
        $this->assertSame(26, $dna->codon(2));

        $props = (new GeneDecoder())->decode($dna, PlayerProportions::class);

        // bodyHeight: ContinuousRange(0.85, 1.15), codon 0 = 21
        // 0.85 + (21/63) * 0.30 = 0.95
        $this->assertEqualsWithDelta(0.95, $props->bodyHeight, 1e-12);

        // shoulderWidth: ContinuousRange(0.70, 1.30), codon 1 = 41
        // 0.70 + (41/63) * 0.60 ~= 1.0904761904761905
        $this->assertEqualsWithDelta(1.0904761904761905, $props->shoulderWidth, 1e-12);

        // hipWidth: ContinuousRange(0.70, 1.20), codon 2 = 26
        // 0.70 + (26/63) * 0.50 ~= 0.9063492063492063
        $this->assertEqualsWithDelta(0.9063492063492063, $props->hipWidth, 1e-12);

        // Reserve loci 16..23 are now active. The alternating-byte pattern
        // produces predictable codons at each locus boundary - cover a few
        // to lock in the new mappings.

        // Locus 16 (FacialHair): bases 48..50 land entirely in a 0x55
        // byte, giving codon 21. 21 % 6 = 3 -> Goatee (case index 3).
        $this->assertSame(FacialHair::Goatee, $props->facialHair);

        // Locus 17 (eyebrowThickness): codon 41 -> 0.60 + (41/63) * 0.90.
        $this->assertEqualsWithDelta(
            0.60 + (41.0 / 63.0) * 0.90,
            $props->eyebrowThickness,
            1e-12,
        );

        // Locus 19 (NoseShape): codon 21. 21 % 5 = 1 -> Straight.
        $this->assertSame(NoseShape::Straight, $props->noseShape);

        // Locus 22 (buildBias): bases 66..68 straddle a 0x55/0xAA boundary,
        // giving codon 37. -1.0 + (37/63) * 2.0 ~= 0.1746031746031746.
        $this->assertEqualsWithDelta(
            -1.0 + (37.0 / 63.0) * 2.0,
            $props->buildBias,
            1e-12,
        );
    }

    public function testAcgtRoundtripPreservesProportions(): void
    {
        $rand = new \Random\Randomizer(new \Random\Engine\Mt19937(2024));
        $decoder = new GeneDecoder();

        for ($i = 0; $i < 10; $i++) {
            $dna = CharacterDNA::random($rand);
            $restored = CharacterDNA::fromAcgt($dna->toAcgt());
            $this->assertEquals(
                $decoder->decode($dna, PlayerProportions::class),
                $decoder->decode($restored, PlayerProportions::class),
            );
        }
    }

    public function testEnumValuesAreValidCases(): void
    {
        $rand = new \Random\Randomizer(new \Random\Engine\Mt19937(7));
        $decoder = new GeneDecoder();

        for ($i = 0; $i < 20; $i++) {
            $props = $decoder->decode(CharacterDNA::random($rand), PlayerProportions::class);
            $this->assertContains($props->skinTone, SkinTone::cases());
            $this->assertContains($props->hairColor, HairColor::cases());
            $this->assertContains($props->hairStyle, HairStyle::cases());
            $this->assertContains($props->eyeColor, EyeColor::cases());
            $this->assertContains($props->eyeShape, EyeShape::cases());
            $this->assertContains($props->facialHair, FacialHair::cases());
            $this->assertContains($props->noseShape, NoseShape::cases());
            $this->assertContains($props->accessory, Accessory::cases());
        }
    }

    public function testContinuousReserveLociStayInDeclaredRange(): void
    {
        $rand = new \Random\Randomizer(new \Random\Engine\Mt19937(11));
        $decoder = new GeneDecoder();

        for ($i = 0; $i < 30; $i++) {
            $props = $decoder->decode(CharacterDNA::random($rand), PlayerProportions::class);
            $this->assertGreaterThanOrEqual(0.60, $props->eyebrowThickness);
            $this->assertLessThanOrEqual(1.50, $props->eyebrowThickness);
            $this->assertGreaterThanOrEqual(-0.35, $props->eyebrowAngle);
            $this->assertLessThanOrEqual(0.35, $props->eyebrowAngle);
            $this->assertGreaterThanOrEqual(0.75, $props->earSize);
            $this->assertLessThanOrEqual(1.35, $props->earSize);
            $this->assertGreaterThanOrEqual(0.00, $props->age);
            $this->assertLessThanOrEqual(1.00, $props->age);
            $this->assertGreaterThanOrEqual(-1.00, $props->buildBias);
            $this->assertLessThanOrEqual(1.00, $props->buildBias);
        }
    }
}
