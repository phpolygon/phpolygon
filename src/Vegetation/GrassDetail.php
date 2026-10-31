<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

use PHPolygon\Rendering\Quality\GrassQuality;

/**
 * Everything that decides how a grass field looks and what it costs: the blade
 * shape, density, the distance bands, wind and push response and the shading
 * look. The quality levels ({@see GrassQuality}) are nothing but presets of this
 * object ({@see forQuality()}); a game can build its own or derive one with
 * {@see with()}.
 *
 * Distance bands (camera distance of a tuft, metres):
 * - up to {@see $fullRange}: every tuft at full height,
 * - {@see $fullRange} .. {@see $fadeRange}: tufts thin out (an instance hash
 *   against a falling density, so no tuft pops - it shrinks into the ground
 *   over a short distance) and get shorter toward {@see $farHeight},
 * - beyond {@see $fadeRange}: nothing; the ground material carries the look.
 */
final readonly class GrassDetail
{
    /**
     * @param GrassBladeShape $shape         Blades of a tuft (near LOD).
     * @param int    $farSegments            Segments of the far LOD mesh (same blades).
     * @param float  $lodDistance            Cells nearer than this use the near LOD.
     * @param float  $tuftsPerSquareMetre    Tufts per m² where the mask is fully open.
     * @param float  $fullRange              Full density up to here.
     * @param float  $fadeRange              No grass beyond; thinning in between.
     * @param float  $farHeight              Height factor of the last tufts at $fadeRange.
     * @param int    $variants               Tuft meshes with different seeds (one draw each per LOD).
     * @param int    $maxInstances           Hard budget of tufts per frame and field.
     * @param bool   $interaction            Blades bend away from {@see \PHPolygon\Component\GrassBender}s.
     * @param float  $bend                   Tip displacement per unit wind, as a fraction of the blade height.
     * @param float  $pushStrength           How far a bender lays the blades down (0..1).
     * @param int    $colorBands             0 = smooth root-to-tip gradient; n = n flat colour bands (stylised).
     * @param float  $variation              Brightness/hue variation per blade and over the field (0..1).
     * @param float  $rootShade              Darkening at the root (contact shadow), 0..1.
     * @param float  $patchScale             Frequency (1/m) of the field-wide colour noise.
     * @param float  $translucency           Light through the blades against the sun (0..1), strongest at the tips.
     * @param float  $generateBudgetMs       CPU time per frame for building new cells, all fields together.
     * @param float  $generateBurst          Budget factor while a missing cell is in sight: all of it at
     *                                       {@see $fullRange}, none at {@see $fadeRange}, by the distance
     *                                       to the camera. A longer frame instead of a hole; 1 = the
     *                                       budget is hard.
     */
    public function __construct(
        public GrassBladeShape $shape = new GrassBladeShape(),
        public int $farSegments = 2,
        public float $lodDistance = 14.0,
        public float $tuftsPerSquareMetre = 7.0,
        public float $fullRange = 25.0,
        public float $fadeRange = 60.0,
        public float $farHeight = 0.55,
        public int $variants = 3,
        public int $maxInstances = 40000,
        public bool $interaction = true,
        public float $bend = 0.3,
        public float $pushStrength = 0.8,
        public int $colorBands = 4,
        public float $variation = 0.18,
        public float $rootShade = 0.45,
        public float $patchScale = 0.12,
        public float $translucency = 0.25,
        public float $generateBudgetMs = 2.0,
        public float $generateBurst = 4.0,
    ) {}

    /** The preset of a quality level; null for {@see GrassQuality::Off}. */
    public static function forQuality(GrassQuality $quality): ?self
    {
        return match ($quality) {
            GrassQuality::Off => null,
            GrassQuality::Low => new self(
                shape: new GrassBladeShape(segments: 2, blades: 6, tuftRadius: 0.12, width: 0.055),
                farSegments: 1,
                lodDistance: 8.0,
                tuftsPerSquareMetre: 4.5,
                fullRange: 12.0,
                fadeRange: 30.0,
                farHeight: 0.5,
                variants: 2,
                maxInstances: 15000,
                interaction: false,
                colorBands: 3,
                variation: 0.15,
                rootShade: 0.35,
                translucency: 0.2,
                generateBudgetMs: 1.5,
            ),
            GrassQuality::Medium => new self(),
            GrassQuality::High => new self(
                shape: new GrassBladeShape(segments: 4, blades: 12, tuftRadius: 0.13, width: 0.04),
                farSegments: 2,
                lodDistance: 20.0,
                tuftsPerSquareMetre: 9.0,
                fullRange: 35.0,
                fadeRange: 80.0,
                maxInstances: 80000,
                colorBands: 5,
                variation: 0.2,
                rootShade: 0.5,
                translucency: 0.3,
            ),
            GrassQuality::Ultra => new self(
                shape: new GrassBladeShape(segments: 7, blades: 12, tuftRadius: 0.13, width: 0.04),
                farSegments: 3,
                lodDistance: 25.0,
                tuftsPerSquareMetre: 14.0,
                fullRange: 50.0,
                fadeRange: 100.0,
                farHeight: 0.6,
                maxInstances: 200000,
                colorBands: 6,
                variation: 0.2,
                rootShade: 0.5,
                translucency: 0.3,
                generateBudgetMs: 3.0,
            ),
            GrassQuality::Realistic => new self(
                shape: new GrassBladeShape(
                    segments: 4,
                    blades: 16,
                    tuftRadius: 0.15,
                    heightMin: 0.3,
                    heightMax: 0.6,
                    width: 0.016,
                    curvature: 0.34,
                    lean: 0.42,
                    taper: 2.2,
                    fold: true,
                    upBias: 0.35,
                ),
                farSegments: 2,
                lodDistance: 15.0,
                tuftsPerSquareMetre: 12.0,
                fullRange: 30.0,
                fadeRange: 70.0,
                farHeight: 0.6,
                variants: 4,
                maxInstances: 120000,
                bend: 0.34,
                colorBands: 0,
                variation: 0.32,
                rootShade: 0.62,
                patchScale: 0.08,
                translucency: 0.6,
                generateBudgetMs: 3.0,
            ),
        };
    }

    /** The far LOD shape (same blades, fewer segments). */
    public function farShape(): GrassBladeShape
    {
        return $this->shape->withSegments(min($this->shape->segments, max(1, $this->farSegments)));
    }

    /** Stable key (cache and mesh ids). */
    public function key(): string
    {
        return $this->shape->key() . sprintf(
            '|f%d|v%d|d%.3f|%.2f-%.2f-%.2f|%d|%.2f',
            $this->farSegments, $this->variants, $this->tuftsPerSquareMetre,
            $this->fullRange, $this->fadeRange, $this->farHeight, $this->maxInstances, $this->lodDistance,
        );
    }

    /** Key of everything that changes the generated instances (shape changes only the meshes). */
    public function instanceKey(): string
    {
        return sprintf('d%.4f|v%d|r%.3f', $this->tuftsPerSquareMetre, max(1, $this->variants), $this->shape->tuftRadius);
    }

    /**
     * Copy with single values replaced.
     */
    public function with(
        ?GrassBladeShape $shape = null,
        ?float $tuftsPerSquareMetre = null,
        ?float $fullRange = null,
        ?float $fadeRange = null,
        ?bool $interaction = null,
        ?float $translucency = null,
        ?int $colorBands = null,
        ?int $maxInstances = null,
        ?float $generateBudgetMs = null,
        ?float $generateBurst = null,
    ): self {
        return new self(
            shape: $shape ?? $this->shape,
            farSegments: $this->farSegments,
            lodDistance: $this->lodDistance,
            tuftsPerSquareMetre: $tuftsPerSquareMetre ?? $this->tuftsPerSquareMetre,
            fullRange: $fullRange ?? $this->fullRange,
            fadeRange: $fadeRange ?? $this->fadeRange,
            farHeight: $this->farHeight,
            variants: $this->variants,
            maxInstances: $maxInstances ?? $this->maxInstances,
            interaction: $interaction ?? $this->interaction,
            bend: $this->bend,
            pushStrength: $this->pushStrength,
            colorBands: $colorBands ?? $this->colorBands,
            variation: $this->variation,
            rootShade: $this->rootShade,
            patchScale: $this->patchScale,
            translucency: $translucency ?? $this->translucency,
            generateBudgetMs: $generateBudgetMs ?? $this->generateBudgetMs,
            generateBurst: $generateBurst ?? $this->generateBurst,
        );
    }
}
