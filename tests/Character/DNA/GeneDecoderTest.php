<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Character\DNA;

use PHPolygon\Character\DNA\CharacterDNA;
use PHPolygon\Character\DNA\Enum\EyeColor;
use PHPolygon\Character\DNA\Enum\EyeShape;
use PHPolygon\Character\DNA\Enum\HairColor;
use PHPolygon\Character\DNA\Enum\HairStyle;
use PHPolygon\Character\DNA\Enum\SkinTone;
use PHPolygon\Character\DNA\Gene;
use PHPolygon\Character\DNA\GeneDecoder;
use PHPolygon\Character\DNA\Mapping\ContinuousRange;
use PHPolygon\Character\DNA\PlayerProportions;
use PHPUnit\Framework\TestCase;

class GeneDecoderTest extends TestCase
{
    public function testDecodesAllSixteenFields(): void
    {
        $dna = CharacterDNA::random();
        $props = (new GeneDecoder())->decode($dna, PlayerProportions::class);

        $this->assertInstanceOf(PlayerProportions::class, $props);
        $this->assertIsFloat($props->bodyHeight);
        $this->assertIsFloat($props->shoulderWidth);
        $this->assertIsFloat($props->hipWidth);
        $this->assertIsFloat($props->torsoLength);
        $this->assertIsFloat($props->limbLength);
        $this->assertIsFloat($props->limbTaper);
        $this->assertIsFloat($props->skullHeight);
        $this->assertIsFloat($props->skullWidth);
        $this->assertIsFloat($props->jawWidth);
        $this->assertIsFloat($props->browProminence);
        $this->assertIsFloat($props->eyeSpacing);
        $this->assertInstanceOf(SkinTone::class, $props->skinTone);
        $this->assertInstanceOf(HairColor::class, $props->hairColor);
        $this->assertInstanceOf(HairStyle::class, $props->hairStyle);
        $this->assertInstanceOf(EyeColor::class, $props->eyeColor);
        $this->assertInstanceOf(EyeShape::class, $props->eyeShape);
    }

    public function testDecodeIsDeterministic(): void
    {
        $dna = CharacterDNA::random();
        $decoder = new GeneDecoder();
        $a = $decoder->decode($dna, PlayerProportions::class);
        $b = $decoder->decode($dna, PlayerProportions::class);
        $c = $decoder->decode($dna, PlayerProportions::class);
        $this->assertEquals($a, $b);
        $this->assertEquals($b, $c);
    }

    public function testFloatsRespectBounds(): void
    {
        $rand = new \Random\Randomizer(new \Random\Engine\Mt19937(1337));
        $decoder = new GeneDecoder();

        for ($i = 0; $i < 25; $i++) {
            $props = $decoder->decode(CharacterDNA::random($rand), PlayerProportions::class);
            $this->assertBetween(0.85, 1.15, $props->bodyHeight);
            $this->assertBetween(0.70, 1.30, $props->shoulderWidth);
            $this->assertBetween(0.70, 1.20, $props->hipWidth);
            $this->assertBetween(0.90, 1.10, $props->torsoLength);
            $this->assertBetween(0.85, 1.15, $props->limbLength);
            $this->assertBetween(0.60, 1.00, $props->limbTaper);
            $this->assertBetween(0.90, 1.10, $props->skullHeight);
            $this->assertBetween(0.85, 1.15, $props->skullWidth);
            $this->assertBetween(0.70, 1.30, $props->jawWidth);
            $this->assertBetween(0.00, 1.00, $props->browProminence);
            $this->assertBetween(0.85, 1.15, $props->eyeSpacing);
        }
    }

    public function testAllZeroDnaProducesMinAndFirstCase(): void
    {
        $dna = new CharacterDNA(str_repeat("\x00", 18));
        $props = (new GeneDecoder())->decode($dna, PlayerProportions::class);

        $this->assertSame(0.85, $props->bodyHeight);
        $this->assertSame(0.70, $props->shoulderWidth);
        $this->assertSame(0.70, $props->hipWidth);
        $this->assertSame(0.90, $props->torsoLength);
        $this->assertSame(0.85, $props->limbLength);
        $this->assertSame(0.60, $props->limbTaper);
        $this->assertSame(0.90, $props->skullHeight);
        $this->assertSame(0.85, $props->skullWidth);
        $this->assertSame(0.70, $props->jawWidth);
        $this->assertSame(0.00, $props->browProminence);
        $this->assertSame(0.85, $props->eyeSpacing);

        $this->assertSame(SkinTone::cases()[0], $props->skinTone);
        $this->assertSame(HairColor::cases()[0], $props->hairColor);
        $this->assertSame(HairStyle::cases()[0], $props->hairStyle);
        $this->assertSame(EyeColor::cases()[0], $props->eyeColor);
        $this->assertSame(EyeShape::cases()[0], $props->eyeShape);
    }

    public function testAllOneDnaProducesMaxFloats(): void
    {
        $dna = new CharacterDNA(str_repeat("\xFF", 18));
        $props = (new GeneDecoder())->decode($dna, PlayerProportions::class);

        $this->assertEqualsWithDelta(1.15, $props->bodyHeight, 1e-12);
        $this->assertEqualsWithDelta(1.30, $props->shoulderWidth, 1e-12);
        $this->assertEqualsWithDelta(1.20, $props->hipWidth, 1e-12);
        $this->assertEqualsWithDelta(1.10, $props->torsoLength, 1e-12);
        $this->assertEqualsWithDelta(1.15, $props->limbLength, 1e-12);
        $this->assertEqualsWithDelta(1.00, $props->limbTaper, 1e-12);
        $this->assertEqualsWithDelta(1.10, $props->skullHeight, 1e-12);
        $this->assertEqualsWithDelta(1.15, $props->skullWidth, 1e-12);
        $this->assertEqualsWithDelta(1.30, $props->jawWidth, 1e-12);
        $this->assertEqualsWithDelta(1.00, $props->browProminence, 1e-12);
        $this->assertEqualsWithDelta(1.15, $props->eyeSpacing, 1e-12);
    }

    public function testDecodeThrowsForClassWithoutConstructor(): void
    {
        $this->expectException(\LogicException::class);
        (new GeneDecoder())->decode(new CharacterDNA(str_repeat("\x00", 18)), \stdClass::class);
    }

    public function testParametersWithoutGeneAttributeAreSkipped(): void
    {
        $dna = CharacterDNA::random();
        $obj = (new GeneDecoder())->decode($dna, GeneDecoderTestStub::class);

        $this->assertSame('default', $obj->note);
        $this->assertGreaterThanOrEqual(0.0, $obj->value);
        $this->assertLessThanOrEqual(1.0, $obj->value);
    }

    private function assertBetween(float $min, float $max, float $value): void
    {
        $this->assertGreaterThanOrEqual($min, $value);
        $this->assertLessThanOrEqual($max, $value);
    }
}

final readonly class GeneDecoderTestStub
{
    public function __construct(
        #[Gene(0, new ContinuousRange(0.0, 1.0))]
        public float $value,
        public string $note = 'default',
    ) {}
}
