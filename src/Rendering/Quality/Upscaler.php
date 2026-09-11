<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * How the scene is brought to the display resolution when it renders below it
 * ({@see \PHPolygon\Rendering\GraphicsSettings::$renderScale} < 1).
 *
 *   Off  - bilinear stretch in the present pass (the previous behaviour).
 *   Fsr1 - AMD FidelityFX Super Resolution 1: the scene is resolved at render
 *          resolution (tonemap, grade, FXAA), upscaled with EASU (edge-adaptive
 *          spatial upsampling) and sharpened with RCAS. Spatial only, so it
 *          needs no motion vectors and runs on every GPU the vio backend runs on.
 *
 * Temporal upscalers (FSR 2/3, DLSS, XeSS) need per-pixel motion vectors and a
 * jittered projection, which the renderer does not produce yet; they are not
 * offered until it does.
 */
enum Upscaler: string
{
    case Off = 'off';
    case Fsr1 = 'fsr1';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off (bilinear)',
            self::Fsr1 => 'AMD FSR 1',
        };
    }
}
