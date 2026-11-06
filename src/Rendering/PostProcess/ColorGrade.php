<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

/**
 * A colour grade described by numbers, baked into a {@see ColorLut} on the CPU. Works on
 * the display-referred image (sRGB-encoded 0..1, gamma 2.2) after the tonemap, in this order:
 *
 *  1. white balance in linear light: `temperature` warms (+) or cools (-), `tint` pushes
 *     magenta (+) or green (-); luminance is kept
 *  2. contrast in log space around linear mid grey 0.18
 *  3. saturation in linear light, luminance kept
 *  4. split toning: `shadows` and `highlights` multiply linear light, blended by luminance
 *  5. lift / gamma / gain per channel on the encoded value (the formula of
 *     {@see \PHPolygon\Rendering\ColorGradingPreset})
 *
 * The defaults are the identity. Values are the game's: the engine holds no look.
 */
final class ColorGrade
{
    private const LUMA = [0.2126, 0.7152, 0.0722];

    /**
     * @param list<float> $lift       added, scaled by (1 - value): moves the blacks
     * @param list<float> $gamma      > 1 brightens the mids
     * @param list<float> $gain       multiplies: moves the whites
     * @param list<float> $shadows    linear multiplier of the dark end
     * @param list<float> $highlights linear multiplier of the bright end
     */
    public function __construct(
        public readonly float $temperature = 0.0,
        public readonly float $tint = 0.0,
        public readonly float $contrast = 1.0,
        public readonly float $saturation = 1.0,
        public readonly array $shadows = [1.0, 1.0, 1.0],
        public readonly array $highlights = [1.0, 1.0, 1.0],
        public readonly array $lift = [0.0, 0.0, 0.0],
        public readonly array $gamma = [1.0, 1.0, 1.0],
        public readonly array $gain = [1.0, 1.0, 1.0],
    ) {
        foreach (['shadows' => $shadows, 'highlights' => $highlights, 'lift' => $lift, 'gamma' => $gamma, 'gain' => $gain] as $name => $v) {
            if (count($v) !== 3) {
                throw new \InvalidArgumentException("ColorGrade {$name} needs three values");
            }
        }
    }

    public function isIdentity(): bool
    {
        return $this == new self();
    }

    /**
     * Grade one encoded colour.
     *
     * @param array{0: float, 1: float, 2: float} $rgb sRGB-encoded 0..1
     * @return array{0: float, 1: float, 2: float}
     */
    public function apply(array $rgb): array
    {
        $lin = [self::decode($rgb[0]), self::decode($rgb[1]), self::decode($rgb[2])];
        $luma = self::luma($lin);

        if ($this->temperature !== 0.0 || $this->tint !== 0.0) {
            // A first-order white balance: red/blue against each other for temperature,
            // green against both for tint. Renormalised to the input luminance.
            $t = $this->temperature * 0.2;
            $g = $this->tint * 0.15;
            $lin = [$lin[0] * (1.0 + $t + $g * 0.5), $lin[1] * (1.0 - $g), $lin[2] * (1.0 - $t + $g * 0.5)];
            $after = self::luma($lin);
            if ($after > 1e-9) {
                $k = $luma / $after;
                $lin = [$lin[0] * $k, $lin[1] * $k, $lin[2] * $k];
            }
        }
        if ($this->contrast !== 1.0) {
            for ($i = 0; $i < 3; $i++) {
                $lin[$i] = $lin[$i] > 0.0 ? 0.18 * (($lin[$i] / 0.18) ** $this->contrast) : 0.0;
            }
        }
        if ($this->saturation !== 1.0) {
            $l = self::luma($lin);
            for ($i = 0; $i < 3; $i++) {
                $lin[$i] = max(0.0, $l + ($lin[$i] - $l) * $this->saturation);
            }
        }
        if ($this->shadows !== [1.0, 1.0, 1.0] || $this->highlights !== [1.0, 1.0, 1.0]) {
            $w = self::smoothstep(0.0, 0.6, self::luma($lin));
            for ($i = 0; $i < 3; $i++) {
                $lin[$i] *= $this->shadows[$i] + ($this->highlights[$i] - $this->shadows[$i]) * $w;
            }
        }

        $out = [];
        for ($i = 0; $i < 3; $i++) {
            $c = self::encode($lin[$i]);
            $c = $c + $this->lift[$i] * (1.0 - $c);
            $c = max(0.0, $c * $this->gain[$i]);
            $c = $c ** (1.0 / max(1e-3, $this->gamma[$i]));
            $out[$i] = max(0.0, min(1.0, $c));
        }
        return [$out[0], $out[1], $out[2]];
    }

    /** Blend two grades; the LUT of the result is the blend of the two LUTs only for linear parameters. */
    public static function lerp(self $a, self $b, float $t): self
    {
        $mix = static fn (float $x, float $y): float => $x + ($y - $x) * $t;
        return new self(
            $mix($a->temperature, $b->temperature),
            $mix($a->tint, $b->tint),
            $mix($a->contrast, $b->contrast),
            $mix($a->saturation, $b->saturation),
            self::mix3($t, $a->shadows, $b->shadows),
            self::mix3($t, $a->highlights, $b->highlights),
            self::mix3($t, $a->lift, $b->lift),
            self::mix3($t, $a->gamma, $b->gamma),
            self::mix3($t, $a->gain, $b->gain),
        );
    }

    /**
     * @param list<float> $x
     * @param list<float> $y
     * @return list<float>
     */
    private static function mix3(float $t, array $x, array $y): array
    {
        return [$x[0] + ($y[0] - $x[0]) * $t, $x[1] + ($y[1] - $x[1]) * $t, $x[2] + ($y[2] - $x[2]) * $t];
    }

    private static function decode(float $c): float
    {
        return max(0.0, $c) ** 2.2;
    }

    private static function encode(float $c): float
    {
        return max(0.0, $c) ** (1.0 / 2.2);
    }

    /** @param array{0: float, 1: float, 2: float}|list<float> $lin */
    private static function luma(array $lin): float
    {
        return $lin[0] * self::LUMA[0] + $lin[1] * self::LUMA[1] + $lin[2] * self::LUMA[2];
    }

    private static function smoothstep(float $e0, float $e1, float $x): float
    {
        $t = max(0.0, min(1.0, ($x - $e0) / ($e1 - $e0)));
        return $t * $t * (3.0 - 2.0 * $t);
    }
}
