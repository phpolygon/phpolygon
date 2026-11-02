<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Accessibility;

/**
 * Colour vision deficiency on the CPU: the same simulation and correction the
 * accessibility pass runs (`a11y_apply.frag.glsl`), for tests, for checking a UI
 * palette, and for correcting 2D colours, which the image pass does not reach.
 *
 * Simulation: Machado, Oliveira & Fernandes, "A Physiologically-based Model for
 * Simulation of Color Vision Deficiency" (IEEE TVCG 2009), severity 1.0, on linear
 * RGB. Correction ("daltonisation"): the colour difference a viewer with the
 * deficiency cannot see is moved into channels they can see (Fidaner, Lin & Ozguven
 * 2005); protan and deutan move it into green and blue, tritan into red and green.
 *
 * Colours are linear RGB triples 0..1 unless a method says otherwise. The engine
 * encodes the final image with a 2.2 power ({@see decode()}, {@see encode()}).
 */
final class ColorVision
{
    /** @var array<string, array{0: array{float, float, float}, 1: array{float, float, float}, 2: array{float, float, float}}> rows of the simulation matrices */
    public const SIMULATION = [
        'protanopia' => [
            [0.152286, 1.052583, -0.204868],
            [0.114503, 0.786281, 0.099216],
            [-0.003882, -0.048116, 1.051998],
        ],
        'deuteranopia' => [
            [0.367322, 0.860646, -0.227968],
            [0.280085, 0.672501, 0.047413],
            [-0.011820, 0.042940, 0.968881],
        ],
        'tritanopia' => [
            [1.255528, -0.076749, -0.178779],
            [-0.078411, 0.930809, 0.147602],
            [0.004733, 0.691367, 0.303900],
        ],
    ];

    /** @var array{0: array{float, float, float}, 1: array{float, float, float}, 2: array{float, float, float}} error redistribution for protan and deutan */
    public const SHIFT_RED_GREEN = [
        [0.0, 0.0, 0.0],
        [0.7, 1.0, 0.0],
        [0.7, 0.0, 1.0],
    ];

    /** @var array{0: array{float, float, float}, 1: array{float, float, float}, 2: array{float, float, float}} error redistribution for tritan */
    public const SHIFT_BLUE_YELLOW = [
        [1.0, 0.0, 0.7],
        [0.0, 1.0, 0.7],
        [0.0, 0.0, 0.0],
    ];

    /**
     * What a viewer with the deficiency sees, blended by $strength (0 = the colour itself).
     *
     * @param array{float, float, float} $rgb
     * @return array{float, float, float}
     */
    public static function simulate(ColorVisionMode $mode, array $rgb, float $strength = 1.0): array
    {
        if ($mode === ColorVisionMode::Off) {
            return $rgb;
        }
        $sim = self::multiply(self::SIMULATION[$mode->value], $rgb);
        return self::clamp([
            $rgb[0] + ($sim[0] - $rgb[0]) * $strength,
            $rgb[1] + ($sim[1] - $rgb[1]) * $strength,
            $rgb[2] + ($sim[2] - $rgb[2]) * $strength,
        ]);
    }

    /**
     * The corrected colour: the part the viewer cannot see, moved where they can.
     *
     * @param array{float, float, float} $rgb
     * @return array{float, float, float}
     */
    public static function correct(ColorVisionMode $mode, array $rgb, float $strength = 1.0): array
    {
        if ($mode === ColorVisionMode::Off) {
            return $rgb;
        }
        $sim = self::multiply(self::SIMULATION[$mode->value], $rgb);
        $error = [$rgb[0] - $sim[0], $rgb[1] - $sim[1], $rgb[2] - $sim[2]];
        $shift = self::multiply($mode === ColorVisionMode::Tritanopia ? self::SHIFT_BLUE_YELLOW : self::SHIFT_RED_GREEN, $error);
        return self::clamp([
            $rgb[0] + $shift[0] * $strength,
            $rgb[1] + $shift[1] * $strength,
            $rgb[2] + $shift[2] * $strength,
        ]);
    }

    /**
     * CIE 1976 colour difference of two linear RGB colours (sRGB primaries, D65).
     * About 2.3 is a just noticeable difference.
     *
     * @param array{float, float, float} $a
     * @param array{float, float, float} $b
     */
    public static function deltaE(array $a, array $b): float
    {
        $la = self::lab($a);
        $lb = self::lab($b);
        return sqrt(($la[0] - $lb[0]) ** 2 + ($la[1] - $lb[1]) ** 2 + ($la[2] - $lb[2]) ** 2);
    }

    /**
     * Encoded (0..1, as written to the screen) to linear.
     *
     * @param array{float, float, float} $encoded
     * @return array{float, float, float}
     */
    public static function decode(array $encoded): array
    {
        return [max(0.0, $encoded[0]) ** 2.2, max(0.0, $encoded[1]) ** 2.2, max(0.0, $encoded[2]) ** 2.2];
    }

    /**
     * @param array{float, float, float} $linear
     * @return array{float, float, float}
     */
    public static function encode(array $linear): array
    {
        return [max(0.0, $linear[0]) ** (1 / 2.2), max(0.0, $linear[1]) ** (1 / 2.2), max(0.0, $linear[2]) ** (1 / 2.2)];
    }

    /**
     * @param array{float, float, float} $rgb
     * @return array{float, float, float}
     */
    private static function lab(array $rgb): array
    {
        $x = (0.4124 * $rgb[0] + 0.3576 * $rgb[1] + 0.1805 * $rgb[2]) / 0.95047;
        $y = 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
        $z = (0.0193 * $rgb[0] + 0.1192 * $rgb[1] + 0.9505 * $rgb[2]) / 1.08883;
        $f = static fn (float $t): float => $t > 0.008856 ? $t ** (1 / 3) : 7.787 * $t + 16 / 116;
        [$fx, $fy, $fz] = [$f($x), $f($y), $f($z)];
        return [116 * $fy - 16, 500 * ($fx - $fy), 200 * ($fy - $fz)];
    }

    /**
     * @param array{0: array{float, float, float}, 1: array{float, float, float}, 2: array{float, float, float}} $rows
     * @param array{float, float, float} $v
     * @return array{float, float, float}
     */
    private static function multiply(array $rows, array $v): array
    {
        return [
            $rows[0][0] * $v[0] + $rows[0][1] * $v[1] + $rows[0][2] * $v[2],
            $rows[1][0] * $v[0] + $rows[1][1] * $v[1] + $rows[1][2] * $v[2],
            $rows[2][0] * $v[0] + $rows[2][1] * $v[1] + $rows[2][2] * $v[2],
        ];
    }

    /**
     * @param array{float, float, float} $v
     * @return array{float, float, float}
     */
    private static function clamp(array $v): array
    {
        return [max(0.0, min(1.0, $v[0])), max(0.0, min(1.0, $v[1])), max(0.0, min(1.0, $v[2]))];
    }
}
