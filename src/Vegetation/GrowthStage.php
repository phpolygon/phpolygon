<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

/**
 * Growth state of one plant instance. The same plant at the same place moves
 * through these stages ("more, not different"); each stage is a baked mesh set
 * of the same variant, so a stage change swaps meshes but never the position.
 */
enum GrowthStage: int
{
    /** No foliage: bare branches with twigs, or a stump (see {@see BareStyle}). */
    case Bare = 0;
    /** Young plant: smaller, about a third of the foliage, tied to a stake. */
    case Sprouting = 1;
    /** Most of the size, about two thirds of the foliage. */
    case Half = 2;
    /** Fully grown. */
    case Full = 3;
    /** Fully grown plus the accent part (blossoms, fruit, glowing flowers). */
    case Bloom = 4;

    /** Fraction of the full foliage this stage carries (0..1). */
    public function foliage(): float
    {
        return match ($this) {
            self::Bare => 0.0,
            self::Sprouting => 0.35,
            self::Half => 0.7,
            self::Full, self::Bloom => 1.0,
        };
    }

    /** Height of the plant relative to its full size. */
    public function size(): float
    {
        return match ($this) {
            self::Bare => 1.0,
            self::Sprouting => 0.55,
            self::Half => 0.85,
            self::Full, self::Bloom => 1.0,
        };
    }

    /** Whether the stage adds a support stake beside the trunk. */
    public function hasStake(): bool
    {
        return $this === self::Sprouting;
    }

    /** Whether the stage carries the accent part. */
    public function hasAccent(): bool
    {
        return $this === self::Bloom;
    }

    /**
     * Stage for a progress value 0..1 (0 = Bare, 1 = Bloom), e.g. a level's
     * rebuild progress. Values outside the range clamp.
     */
    public static function fromProgress(float $progress): self
    {
        $p = max(0.0, min(1.0, $progress));
        return self::from((int) round($p * 4.0));
    }
}
