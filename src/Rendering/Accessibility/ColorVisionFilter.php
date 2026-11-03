<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Accessibility;

use PHPolygon\Rendering\Color;

/**
 * The colour vision option applied to 2D draw colours: what the accessibility pass does to
 * the 3D image, done on the CPU for the UI, which that pass does not reach.
 *
 * 2D colours are encoded (the values that land on the screen), so a colour is decoded,
 * corrected (or simulated, for the developer view) with {@see ColorVision} and encoded again.
 * Alpha stays. Results are kept per packed 0xAARRGGBB value: a UI draws few distinct colours,
 * so after the first frame a draw costs one array lookup.
 */
final class ColorVisionFilter
{
    /** Distinct colours kept before the cache starts over. */
    public const CACHE_LIMIT = 1024;

    /** @var array<int, int> packed colour → packed result */
    private array $cache = [];

    public function __construct(
        public readonly ColorVisionMode $mode,
        public readonly float $strength = 1.0,
        public readonly bool $simulate = false,
    ) {
    }

    /** The filter the settings ask for, null when they leave colours alone. */
    public static function fromSettings(AccessibilitySettings $settings): ?self
    {
        if ($settings->colorVision === ColorVisionMode::Off || $settings->colorVisionStrength <= 0.0) {
            return null;
        }
        return new self($settings->colorVision, $settings->colorVisionStrength, $settings->colorVisionSimulate);
    }

    /** Whether this filter does what $other does (same mode, strength, simulation). */
    public function sameAs(?self $other): bool
    {
        return $other !== null
            && $other->mode === $this->mode
            && $other->strength === $this->strength
            && $other->simulate === $this->simulate;
    }

    /** A packed 0xAARRGGBB colour, filtered; alpha unchanged. */
    public function argb(int $argb): int
    {
        if (isset($this->cache[$argb])) {
            return $this->cache[$argb];
        }
        if (count($this->cache) >= self::CACHE_LIMIT) {
            $this->cache = [];
        }
        [$r, $g, $b] = $this->rgb((($argb >> 16) & 0xFF) / 255.0, (($argb >> 8) & 0xFF) / 255.0, ($argb & 0xFF) / 255.0);
        return $this->cache[$argb] = ($argb & ~0xFFFFFF)
            | ((int) round($r * 255.0) << 16)
            | ((int) round($g * 255.0) << 8)
            | (int) round($b * 255.0);
    }

    /** A colour, filtered; alpha unchanged. Not cached: for colours that leave the 2D renderer (effect parameters, markers). */
    public function color(Color $color): Color
    {
        [$r, $g, $b] = $this->rgb($color->r, $color->g, $color->b);
        return new Color($r, $g, $b, $color->a);
    }

    /** @return array{float, float, float} */
    private function rgb(float $r, float $g, float $b): array
    {
        $linear = ColorVision::decode([$r, $g, $b]);
        $out = $this->simulate
            ? ColorVision::simulate($this->mode, $linear, $this->strength)
            : ColorVision::correct($this->mode, $linear, $this->strength);
        $encoded = ColorVision::encode($out);
        return [min(1.0, $encoded[0]), min(1.0, $encoded[1]), min(1.0, $encoded[2])];
    }
}
