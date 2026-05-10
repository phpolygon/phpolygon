<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * Color-grading preset selection.
 *
 * PHPolygon does not ship 3D-LUT image files. Stylisation is delivered
 * via analytic Lift/Gamma/Gain (LGG) plus saturation, evaluated in the
 * fragment shader. Each preset resolves to four shader-side values:
 *
 *   - lift  (vec3): added to the linear color, controls black levels
 *   - gamma (vec3): power curve applied per-channel, controls midtones
 *   - gain  (vec3): multiplied into the linear color, controls highlights
 *   - saturation (float): 0 = greyscale, 1 = neutral, > 1 = punchier
 *
 * The helper {@see params()} returns these four values; the renderer
 * binds them as uniforms, the shader applies them after tone-mapping.
 */
enum ColorGradingPreset: string
{
    case Neutral   = 'neutral';
    case Warm      = 'warm';
    case Cool      = 'cool';
    case Cinematic = 'cinematic';
    case Vibrant   = 'vibrant';
    case Muted     = 'muted';

    /**
     * @return array{lift: array{0: float, 1: float, 2: float}, gamma: array{0: float, 1: float, 2: float}, gain: array{0: float, 1: float, 2: float}, saturation: float}
     */
    public function params(): array
    {
        return match ($this) {
            self::Neutral => [
                'lift'       => [0.0, 0.0, 0.0],
                'gamma'      => [1.0, 1.0, 1.0],
                'gain'       => [1.0, 1.0, 1.0],
                'saturation' => 1.0,
            ],
            // Warm: lift the reds in shadows, slight orange tint in highlights.
            self::Warm => [
                'lift'       => [0.02, 0.005, -0.01],
                'gamma'      => [0.95, 1.00, 1.05],
                'gain'       => [1.06, 1.02, 0.96],
                'saturation' => 1.05,
            ],
            // Cool: cyan/blue lean across the curve.
            self::Cool => [
                'lift'       => [-0.01, 0.005, 0.02],
                'gamma'      => [1.05, 1.00, 0.95],
                'gain'       => [0.96, 1.02, 1.08],
                'saturation' => 1.0,
            ],
            // Cinematic: teal shadows + orange highlights ("Hollywood teal-orange").
            self::Cinematic => [
                'lift'       => [-0.02, 0.0, 0.03],
                'gamma'      => [1.05, 1.00, 0.95],
                'gain'       => [1.08, 1.02, 0.92],
                'saturation' => 0.95,
            ],
            // Vibrant: punchier saturation, mild gain bump.
            self::Vibrant => [
                'lift'       => [0.0, 0.0, 0.0],
                'gamma'      => [1.0, 1.0, 1.0],
                'gain'       => [1.05, 1.05, 1.05],
                'saturation' => 1.25,
            ],
            // Muted: desaturated, slightly lifted blacks (filmic).
            self::Muted => [
                'lift'       => [0.015, 0.015, 0.015],
                'gamma'      => [1.0, 1.0, 1.0],
                'gain'       => [0.95, 0.95, 0.95],
                'saturation' => 0.75,
            ],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Neutral   => 'Neutral',
            self::Warm      => 'Warm',
            self::Cool      => 'Cool',
            self::Cinematic => 'Cinematic',
            self::Vibrant   => 'Vibrant',
            self::Muted     => 'Muted',
        };
    }
}
