<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

/**
 * Parameters of one plant species variant. Pure data, game-agnostic: the game
 * supplies every value (sizes, counts, seed) as its preset; the engine only
 * turns them into geometry with {@see PlantGenerator}.
 *
 * All lengths in metres, angles in radians. The plant stands at the local
 * origin with its trunk foot (or bush/hedge base) on y = 0, growing up +Y.
 * A hedge runs along local X, centred on the origin.
 */
final readonly class PlantSpec
{
    /**
     * @param PlantForm $form          Silhouette family.
     * @param float     $height        Full-grown height (top of the crown / hedge).
     * @param float     $crownRadius   Horizontal crown radius (bush: half width).
     * @param int       $seed          Deterministic variation; same seed = same mesh.
     * @param float|null $clearTrunk   Branch-free trunk height (people walk under
     *                                 it). Null derives it from form and height.
     * @param float|null $trunkRadius  Trunk radius above the root flare. Null
     *                                 derives it from the height.
     * @param float     $lean          Tilt of the trunk top away from vertical.
     * @param float     $leanDirection Heading of the lean around +Y (0 = +X).
     * @param int       $branchCount   Main branches of a broadleaf tree.
     * @param int       $clumpCount    Foliage clumps of a crown or bush (5-9 reads
     *                                 best; LOD1 and LOD2 use fewer).
     * @param int       $frondCount    Palm fronds.
     * @param float|null $frondLength  Palm frond length; null derives it from height.
     * @param float     $length        Hedge length along local X.
     * @param float     $depth         Hedge depth along local Z.
     * @param int       $accentCount   Blossoms / fruit of the accent part.
     * @param BareStyle $bareStyle     Look of {@see GrowthStage::Bare}.
     * @param PlantCanopy|null $canopy Open foliage of leaf tufts on secondary
     *                                 branches (broadleaf, bush, hedge). Null
     *                                 keeps the closed clumps.
     * @param bool      $compactImpostor The impostor is one mesh and one draw:
     *                                 the trunk strip joins the foliage part
     *                                 and takes its material. Always on with a
     *                                 canopy.
     */
    public function __construct(
        public PlantForm $form,
        public float $height,
        public float $crownRadius,
        public int $seed = 1,
        public ?float $clearTrunk = null,
        public ?float $trunkRadius = null,
        public float $lean = 0.0,
        public float $leanDirection = 0.0,
        public int $branchCount = 5,
        public int $clumpCount = 7,
        public int $frondCount = 14,
        public ?float $frondLength = null,
        public float $length = 0.0,
        public float $depth = 0.8,
        public int $accentCount = 36,
        public BareStyle $bareStyle = BareStyle::Twigs,
        public ?PlantCanopy $canopy = null,
        public bool $compactImpostor = false,
    ) {
        if ($height <= 0.0 || !is_finite($height)) {
            throw new \InvalidArgumentException('PlantSpec: height must be > 0');
        }
        if ($crownRadius <= 0.0 || !is_finite($crownRadius)) {
            throw new \InvalidArgumentException('PlantSpec: crownRadius must be > 0');
        }
        if ($form === PlantForm::Hedge && $length <= 0.0) {
            throw new \InvalidArgumentException('PlantSpec: a hedge needs a length > 0');
        }
        if ($clumpCount < 1 || $branchCount < 0 || $frondCount < 1 || $accentCount < 0) {
            throw new \InvalidArgumentException('PlantSpec: counts must be positive');
        }
    }

    /** Broadleaf or needle tree. */
    public static function tree(
        PlantForm $form,
        float $height,
        float $crownRadius,
        int $seed = 1,
        ?float $clearTrunk = null,
        float $lean = 0.03,
        int $clumpCount = 7,
        int $branchCount = 5,
        int $accentCount = 36,
        BareStyle $bareStyle = BareStyle::Twigs,
        ?PlantCanopy $canopy = null,
    ): self {
        return new self(
            form: $form,
            height: $height,
            crownRadius: $crownRadius,
            seed: $seed,
            clearTrunk: $clearTrunk,
            lean: $lean,
            leanDirection: self::hashAngle($seed),
            branchCount: $branchCount,
            clumpCount: $clumpCount,
            accentCount: $accentCount,
            bareStyle: $bareStyle,
            canopy: $canopy,
        );
    }

    /** Palm with a curved trunk; fronds default to a third of the height (2.5-4 m). */
    public static function palm(
        float $height,
        int $seed = 1,
        float $lean = 0.18,
        int $frondCount = 14,
        ?float $frondLength = null,
        int $accentCount = 6,
        BareStyle $bareStyle = BareStyle::Stump,
        bool $compactImpostor = false,
    ): self {
        $frond = $frondLength ?? max(1.2, min(4.2, $height * 0.36));
        return new self(
            form: PlantForm::Palm,
            height: $height,
            crownRadius: $frond * 0.9,
            seed: $seed,
            lean: $lean,
            leanDirection: self::hashAngle($seed),
            frondCount: $frondCount,
            frondLength: $frond,
            accentCount: $accentCount,
            bareStyle: $bareStyle,
            compactImpostor: $compactImpostor,
        );
    }

    /** Free-standing bush; $width is the full diameter. */
    public static function bush(float $height, float $width, int $seed = 1, int $clumpCount = 5, int $accentCount = 14, ?PlantCanopy $canopy = null): self
    {
        return new self(
            form: PlantForm::Bush,
            height: $height,
            crownRadius: $width * 0.5,
            seed: $seed,
            clumpCount: $clumpCount,
            accentCount: $accentCount,
            canopy: $canopy,
        );
    }

    /** Hedge segment along local X. */
    public static function hedge(float $length, float $height, float $depth = 0.8, int $seed = 1, int $accentCount = 0, ?PlantCanopy $canopy = null): self
    {
        return new self(
            form: PlantForm::Hedge,
            height: $height,
            crownRadius: $depth * 0.5,
            seed: $seed,
            length: $length,
            depth: $depth,
            accentCount: $accentCount,
            canopy: $canopy,
        );
    }

    /** Effective trunk radius above the root flare. */
    public function trunkRadiusResolved(): float
    {
        if ($this->trunkRadius !== null) {
            return $this->trunkRadius;
        }
        return match ($this->form) {
            PlantForm::Palm => 0.14 + $this->height * 0.012,
            PlantForm::Conifer => 0.1 + $this->height * 0.016,
            PlantForm::Bush, PlantForm::Hedge => 0.03,
            default => 0.1 + $this->height * 0.02,
        };
    }

    /** Effective branch-free trunk height. */
    public function clearTrunkResolved(): float
    {
        if ($this->clearTrunk !== null) {
            return max(0.0, min($this->height * 0.9, $this->clearTrunk));
        }
        return match ($this->form) {
            PlantForm::Umbrella => $this->height * 0.6,
            PlantForm::Column => $this->height * 0.16,
            PlantForm::Conifer => $this->height * 0.14,
            PlantForm::Weeping => $this->height * 0.42,
            PlantForm::Palm => $this->height,
            PlantForm::Bush, PlantForm::Hedge => 0.0,
            default => $this->height * 0.36,
        };
    }

    /** Effective palm frond length. */
    public function frondLengthResolved(): float
    {
        return $this->frondLength ?? max(1.2, min(4.2, $this->height * 0.36));
    }

    private static function hashAngle(int $seed): float
    {
        $x = sin($seed * 12.9898 + 4.1414) * 43758.5453;
        return ($x - floor($x)) * 2.0 * M_PI;
    }
}
