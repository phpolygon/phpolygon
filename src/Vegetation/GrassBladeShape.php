<?php

declare(strict_types=1);

namespace PHPolygon\Vegetation;

/**
 * The shape of the blades in one grass tuft, consumed by {@see GrassTuftMesh}.
 *
 * Every quality level of {@see GrassDetail} carries one of these; the levels
 * share the same form language (tapered, slightly curved blades leaning out of
 * a tuft) and only resolve it finer: more segments, more and thinner blades,
 * a folded midrib for the realistic look.
 *
 * Lengths are metres in object space; the tuft is scaled per instance.
 */
final readonly class GrassBladeShape
{
    /**
     * @param int   $segments     Segments along a blade (>= 1). A blade is a
     *                            tapered strip of `segments - 1` quads and a
     *                            tip triangle: 2 * segments - 1 triangles (twice
     *                            that when folded).
     * @param int   $blades       Blades per tuft.
     * @param float $tuftRadius   Radius of the disc the blade roots scatter on.
     * @param float $heightMin    Shortest blade.
     * @param float $heightMax    Tallest blade.
     * @param float $width        Blade width at the root.
     * @param float $curvature    Bend of the blade toward its lean side, as a
     *                            fraction of its height at the tip (0 = straight).
     * @param float $lean         Maximum lean of the blade at the root, radians.
     * @param float $taper        Width profile exponent: width = w * (1 - t^taper);
     *                            1 = straight taper, 2-3 = blade stays wide and
     *                            narrows late.
     * @param bool  $fold         Fold every blade along a midrib (a shallow V
     *                            cross-section with two normals) - catches light
     *                            like a real blade; doubles the triangles.
     * @param float $upBias       0..1: how far the shading normal leans toward
     *                            world up. Thin blades with pure side normals
     *                            flicker between light and dark as they sway; a
     *                            normal partly pointing up shades a field
     *                            calmly, like the ground below it.
     */
    public function __construct(
        public int $segments = 3,
        public int $blades = 9,
        public float $tuftRadius = 0.12,
        public float $heightMin = 0.28,
        public float $heightMax = 0.5,
        public float $width = 0.045,
        public float $curvature = 0.28,
        public float $lean = 0.35,
        public float $taper = 1.4,
        public bool $fold = false,
        public float $upBias = 0.55,
    ) {}

    /** Triangles of one blade. */
    public function trianglesPerBlade(): int
    {
        $n = max(1, $this->segments);
        return (2 * $n - 1) * ($this->fold && $n >= 2 ? 2 : 1);
    }

    /** Triangles of one tuft. */
    public function trianglesPerTuft(): int
    {
        return $this->trianglesPerBlade() * max(1, $this->blades);
    }

    /** The same blades (same roots, heights, lean) with another segment count - the far LOD. */
    public function withSegments(int $segments): self
    {
        return new self(
            segments: max(1, $segments),
            blades: $this->blades,
            tuftRadius: $this->tuftRadius,
            heightMin: $this->heightMin,
            heightMax: $this->heightMax,
            width: $this->width,
            curvature: $this->curvature,
            lean: $this->lean,
            taper: $this->taper,
            fold: $this->fold,
            upBias: $this->upBias,
        );
    }

    /** Stable text key of the shape (mesh ids). */
    public function key(): string
    {
        return sprintf(
            's%d_b%d_r%.3f_h%.3f-%.3f_w%.4f_c%.3f_l%.3f_t%.2f_f%d_u%.2f',
            $this->segments, $this->blades, $this->tuftRadius, $this->heightMin, $this->heightMax,
            $this->width, $this->curvature, $this->lean, $this->taper, $this->fold ? 1 : 0, $this->upBias,
        );
    }
}
