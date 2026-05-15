<?php

declare(strict_types=1);

namespace PHPolygon\Character\DNA;

use PHPolygon\Character\DNA\Enum\Accessory;
use PHPolygon\Character\DNA\Enum\EyeColor;
use PHPolygon\Character\DNA\Enum\EyeShape;
use PHPolygon\Character\DNA\Enum\FacialHair;
use PHPolygon\Character\DNA\Enum\HairColor;
use PHPolygon\Character\DNA\Enum\HairStyle;
use PHPolygon\Character\DNA\Enum\NoseShape;
use PHPolygon\Character\DNA\Enum\SkinTone;
use PHPolygon\Character\DNA\Mapping\ContinuousRange;
use PHPolygon\Character\DNA\Mapping\EnumChoice;

/**
 * Decoded character proportions and cosmetics derived from a CharacterDNA strand.
 * Consumed by the procedural humanoid mesh builder. All 24 loci are now active.
 */
final readonly class PlayerProportions
{
    public function __construct(
        // Body (Locus 0..5)
        #[Gene(0, new ContinuousRange(0.85, 1.15))]
        public float $bodyHeight,
        #[Gene(1, new ContinuousRange(0.70, 1.30))]
        public float $shoulderWidth,
        #[Gene(2, new ContinuousRange(0.70, 1.20))]
        public float $hipWidth,
        #[Gene(3, new ContinuousRange(0.90, 1.10))]
        public float $torsoLength,
        #[Gene(4, new ContinuousRange(0.85, 1.15))]
        public float $limbLength,
        #[Gene(5, new ContinuousRange(0.60, 1.00))]
        public float $limbTaper,

        // Skull / Face (Locus 6..10) - facial shape emerges from these
        #[Gene(6, new ContinuousRange(0.90, 1.10))]
        public float $skullHeight,
        #[Gene(7, new ContinuousRange(0.85, 1.15))]
        public float $skullWidth,
        #[Gene(8, new ContinuousRange(0.70, 1.30))]
        public float $jawWidth,
        #[Gene(9, new ContinuousRange(0.00, 1.00))]
        public float $browProminence,
        #[Gene(10, new ContinuousRange(0.85, 1.15))]
        public float $eyeSpacing,

        // Cosmetic (Locus 11..15)
        #[Gene(11, new EnumChoice(SkinTone::class))]
        public SkinTone $skinTone,
        #[Gene(12, new EnumChoice(HairColor::class))]
        public HairColor $hairColor,
        #[Gene(13, new EnumChoice(HairStyle::class))]
        public HairStyle $hairStyle,
        #[Gene(14, new EnumChoice(EyeColor::class))]
        public EyeColor $eyeColor,
        #[Gene(15, new EnumChoice(EyeShape::class))]
        public EyeShape $eyeShape,

        // Detail / Variation (Locus 16..23) - v2 traits activated
        #[Gene(16, new EnumChoice(FacialHair::class))]
        public FacialHair $facialHair,
        #[Gene(17, new ContinuousRange(0.60, 1.50))]
        public float $eyebrowThickness,
        #[Gene(18, new ContinuousRange(-0.35, 0.35))]
        public float $eyebrowAngle,
        #[Gene(19, new EnumChoice(NoseShape::class))]
        public NoseShape $noseShape,
        #[Gene(20, new ContinuousRange(0.75, 1.35))]
        public float $earSize,
        #[Gene(21, new ContinuousRange(0.00, 1.00))]
        public float $age,
        #[Gene(22, new ContinuousRange(-1.00, 1.00))]
        public float $buildBias,
        #[Gene(23, new EnumChoice(Accessory::class))]
        public Accessory $accessory,
    ) {}
}
