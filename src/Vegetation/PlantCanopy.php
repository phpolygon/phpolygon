<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

/**
 * Open foliage of a broadleaf tree, bush or hedge: instead of a few closed
 * clumps the plant carries many small leaf tufts in groups, with gaps between
 * them that show branches and sky. Needs no texture: a tuft is geometry, a
 * shallow star whose points read as leaves. Set on
 * {@see PlantSpec::$canopy}; without it the plant keeps its closed clumps.
 *
 * Trees: every group sits on the tip of a secondary branch that leaves a main
 * branch, so wood runs inside the crown. LOD0 draws all tufts, LOD1 fewer and
 * larger ones on the same groups, LOD2 a few large ones, the impostor a jagged
 * outline. Pair the leaf material with `Material::foliage(tufts: true)` so
 * flutter moves tuft by tuft (the UVs carry the phase).
 *
 * Conifers and palms ignore it: their tiers and fronds are open already.
 */
final readonly class PlantCanopy
{
    /** Shares of the LOD0 tufts on LOD1 and LOD2; the size grows so the coverage stays. */
    public const float LOD1_TUFTS = 0.33;
    public const float LOD2_TUFTS = 0.14;

    /**
     * @param int|null $tufts     Leaf tufts at LOD0. Null: 90 on a tree, 26 on a
     *                            bush, 13 per metre on a hedge.
     * @param int|null $groups    Tuft groups (trees: tips of secondary branches).
     *                            Null: a fifth of the tufts.
     * @param float    $tuftSize  Scale of the tufts: below 1 opens the crown
     *                            further, above 1 closes it.
     * @param int      $points    Leaf points per tuft at LOD0 (3-10; one less on a
     *                            hedge and on the far LODs); each costs two
     *                            triangles.
     * @param float    $hollow    How far the groups stay away from the crown
     *                            centre as a share of the crown radius (0..0.9).
     */
    public function __construct(
        public ?int $tufts = null,
        public ?int $groups = null,
        public float $tuftSize = 1.0,
        public int $points = 5,
        public float $hollow = 0.55,
    ) {
        if (($tufts !== null && $tufts < 1) || ($groups !== null && $groups < 1)) {
            throw new \InvalidArgumentException('PlantCanopy: counts must be positive');
        }
        if ($tuftSize <= 0.0 || !is_finite($tuftSize)) {
            throw new \InvalidArgumentException('PlantCanopy: tuftSize must be > 0');
        }
        if ($points < 3 || $points > 10) {
            throw new \InvalidArgumentException('PlantCanopy: points must be 3-10');
        }
        if ($hollow < 0.0 || $hollow > 0.9) {
            throw new \InvalidArgumentException('PlantCanopy: hollow must be 0-0.9');
        }
    }

    /** Tufts at LOD0 for a plant of this form (a hedge counts per its length). */
    public function tuftsFor(PlantForm $form, float $length = 0.0): int
    {
        return $this->tufts ?? match ($form) {
            PlantForm::Bush => 26,
            PlantForm::Hedge => max(4, (int) round($length * 13.0)),
            default => 90,
        };
    }

    public function groupsFor(int $tufts): int
    {
        return max(1, min($tufts, $this->groups ?? (int) round($tufts * 0.2)));
    }
}
