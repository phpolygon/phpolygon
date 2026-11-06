<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

/**
 * The look of the image chain for an area and a time of day, sent by the game with
 * {@see \PHPolygon\Rendering\Command\SetImageLook} (sticky). The defaults are the chain of
 * v0.97: one blurred bloom level, fixed exposure 1, ACES, no LUT. While the look equals the
 * defaults nothing of the chain below exists and frames are those of v0.97 byte for byte.
 *
 * Otherwise `VioRenderer3D` runs one finishing draw after bloom (`image_finish.frag.glsl`),
 * in this order:
 *
 *   scene colour (linear HDR) + bloom → exposure (fixed x auto) → tonemap → encode
 *     → LUT (blend of two) → saturation pulse → film grain → deband dither
 *
 * into an RGBA8 image that the present (player grade, vignette, FXAA / FSR 1 / temporal
 * present, sharpening) takes as display-referred colour; the Final passes and the
 * accessibility pass follow as before. Chromatic aberration offsets the scene taps of the
 * same draw. Player-facing gates stay with the player: bloom only while
 * `GraphicsSettings::$bloom`, exposure and tonemap only on the HDR scene path.
 *
 * Comfort options win (`VioRenderer3D` enforces it, not the game): the saturation pulse is
 * dropped while the player lowers world saturation or asks for reduced motion; grain and
 * chromatic aberration are dropped while the contrast mode is on (its outlines would trace
 * them); grain stands still under reduced motion.
 */
final class ImageLook
{
    public const MAX_BLOOM_LEVELS = 7;

    /**
     * @param float        $exposureEv        exposure compensation in EV, on top of auto exposure
     * @param ?AutoExposure $autoExposure     null = fixed exposure
     * @param int          $bloomLevels       1 = the single half-resolution blur of v0.97; 2..7 = mip chain
     *                                        (13-tap down, tent up), each level half the one before
     * @param float        $bloomScatter      weight of each coarser level relative to the finer one (0..1);
     *                                        higher spreads the glow wider. The sum is normalised.
     * @param ?string      $lut               {@see ColorLutRegistry} id
     * @param float        $lutStrength       0..1 mix of the graded over the ungraded image
     * @param ?string      $lutBlend          second table, blended over `$lut` by `$lutBlendWeight` (area borders, dusk)
     * @param float        $saturationPulse   0..1 extra saturation of the moment (a success); the game animates it
     * @param float        $deband            dither amplitude in 8-bit steps (1 hides the banding of sky gradients)
     * @param float        $grain             film grain amplitude (encoded, 0.02 is subtle)
     * @param float        $chromaticAberration radial offset of red and blue at the image corner, in fractions of the width
     * @param float        $sharpen           0..1 contrast-adaptive sharpening of the presented image (FSR 1's RCAS)
     *                                        when no upscaler sharpens already
     */
    public function __construct(
        public readonly float $exposureEv = 0.0,
        public readonly ?AutoExposure $autoExposure = null,
        public readonly Tonemapper $tonemapper = Tonemapper::Aces,
        public readonly float $bloomIntensity = 0.40,
        public readonly float $bloomThreshold = 1.3,
        public readonly int $bloomLevels = 1,
        public readonly float $bloomScatter = 0.7,
        public readonly ?string $lut = null,
        public readonly float $lutStrength = 1.0,
        public readonly ?string $lutBlend = null,
        public readonly float $lutBlendWeight = 0.0,
        public readonly float $saturationPulse = 0.0,
        public readonly float $deband = 0.0,
        public readonly float $grain = 0.0,
        public readonly float $chromaticAberration = 0.0,
        public readonly float $sharpen = 0.0,
    ) {
        if ($bloomLevels < 1 || $bloomLevels > self::MAX_BLOOM_LEVELS) {
            throw new \InvalidArgumentException('ImageLook bloomLevels must be 1..' . self::MAX_BLOOM_LEVELS);
        }
    }

    /** The look of v0.97: nothing of the image chain runs. */
    public function isDefault(): bool
    {
        return $this == new self();
    }

    /**
     * Copy with the given fields replaced.
     *
     * @param array<string, mixed> $changes constructor argument names
     */
    public function with(array $changes): self
    {
        $args = get_object_vars($this);
        foreach ($changes as $name => $value) {
            if (!array_key_exists($name, $args)) {
                throw new \InvalidArgumentException("ImageLook has no field '{$name}'");
            }
            $args[$name] = $value;
        }
        // The constructor checks what it can; the field names were checked above.
        return new self(...$args); // @phpstan-ignore argument.type
    }

    /**
     * Blend two looks for a border or a time of day. Numbers blend linearly; the tonemapper and
     * the level count switch at the middle; LUTs: when both looks name one table and they differ,
     * the result shows `$a`'s table blended into `$b`'s (their own blend tables are dropped).
     */
    public static function lerp(self $a, self $b, float $t): self
    {
        $t = max(0.0, min(1.0, $t));
        if ($t <= 0.0) {
            return $a;
        }
        if ($t >= 1.0) {
            return $b;
        }
        $mix = static fn (float $x, float $y): float => $x + ($y - $x) * $t;
        $auto = match (true) {
            $a->autoExposure !== null && $b->autoExposure !== null => AutoExposure::lerp($a->autoExposure, $b->autoExposure, $t),
            default => $t < 0.5 ? $a->autoExposure : $b->autoExposure,
        };
        [$lut, $strength, $blend, $weight] = self::lerpLut($a, $b, $t);
        return new self(
            exposureEv: $mix($a->exposureEv, $b->exposureEv),
            autoExposure: $auto,
            tonemapper: $t < 0.5 ? $a->tonemapper : $b->tonemapper,
            bloomIntensity: $mix($a->bloomIntensity, $b->bloomIntensity),
            bloomThreshold: $mix($a->bloomThreshold, $b->bloomThreshold),
            bloomLevels: $t < 0.5 ? $a->bloomLevels : $b->bloomLevels,
            bloomScatter: $mix($a->bloomScatter, $b->bloomScatter),
            lut: $lut,
            lutStrength: $strength,
            lutBlend: $blend,
            lutBlendWeight: $weight,
            saturationPulse: $mix($a->saturationPulse, $b->saturationPulse),
            deband: $mix($a->deband, $b->deband),
            grain: $mix($a->grain, $b->grain),
            chromaticAberration: $mix($a->chromaticAberration, $b->chromaticAberration),
            sharpen: $mix($a->sharpen, $b->sharpen),
        );
    }

    /** @return array{0: ?string, 1: float, 2: ?string, 3: float} lut, strength, blend table, blend weight */
    private static function lerpLut(self $a, self $b, float $t): array
    {
        $sa = $a->lut !== null ? $a->lutStrength : 0.0;
        $sb = $b->lut !== null ? $b->lutStrength : 0.0;
        if ($a->lut === null && $b->lut === null) {
            return [null, 1.0, null, 0.0];
        }
        if ($a->lut === null || $b->lut === null || $a->lut === $b->lut) {
            $id = $a->lut ?? $b->lut;
            return [$id, $sa + ($sb - $sa) * $t, $a->lutBlend ?? $b->lutBlend, $a->lutBlendWeight + ($b->lutBlendWeight - $a->lutBlendWeight) * $t];
        }
        return [$a->lut, $sa + ($sb - $sa) * $t, $b->lut, $t];
    }
}
