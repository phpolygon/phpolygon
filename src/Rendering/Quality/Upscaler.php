<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * How the scene is brought to the display resolution when it renders below it
 * ({@see \PHPolygon\Rendering\GraphicsSettings::effectiveRenderScale()} < 1).
 *
 *   Off  - bilinear stretch in the present pass (the previous behaviour).
 *   Fsr1 - AMD FidelityFX Super Resolution 1: the scene is resolved at render
 *          resolution (tonemap, grade, FXAA), upscaled with EASU (edge-adaptive
 *          spatial upsampling) and sharpened with RCAS. Spatial only, so it
 *          needs no motion vectors and runs on every GPU the vio backend runs on.
 *   Taau - the engine's own temporal anti-aliasing with upsampling: jittered
 *          projection + motion vectors, accumulated over frames.
 *   Fsr3 - AMD FidelityFX Super Resolution 3.1 upscaling (native module,
 *          D3D12 / Vulkan).
 *   Dlss - NVIDIA DLSS Super Resolution (native module, RTX GPUs).
 *
 * The temporal ones ({@see isTemporal()}) need per-pixel motion vectors and a
 * jittered projection. A renderer that cannot run the chosen upscaler walks its
 * {@see fallbackChain()} ({@see GraphicsCapabilities::resolveUpscaler()}), so a
 * graphics.json written on a more capable machine still loads and degrades.
 */
enum Upscaler: string
{
    case Off = 'off';
    case Fsr1 = 'fsr1';
    case Taau = 'taau';
    case Fsr3 = 'fsr3';
    case Dlss = 'dlss';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off (bilinear)',
            self::Fsr1 => 'AMD FSR 1',
            self::Taau => 'Temporal (TAAU)',
            self::Fsr3 => 'AMD FSR 3',
            self::Dlss => 'NVIDIA DLSS',
        };
    }

    /** Needs motion vectors, a jittered projection and frame history. */
    public function isTemporal(): bool
    {
        return match ($this) {
            self::Taau, self::Fsr3, self::Dlss => true,
            self::Off, self::Fsr1 => false,
        };
    }

    /**
     * This upscaler followed by what replaces it when the renderer cannot run
     * it, best first: Dlss -> Fsr3 -> Taau -> Fsr1 -> Off.
     *
     * @return non-empty-list<self>
     */
    public function fallbackChain(): array
    {
        return match ($this) {
            self::Dlss => [self::Dlss, self::Fsr3, self::Taau, self::Fsr1, self::Off],
            self::Fsr3 => [self::Fsr3, self::Taau, self::Fsr1, self::Off],
            self::Taau => [self::Taau, self::Fsr1, self::Off],
            self::Fsr1 => [self::Fsr1, self::Off],
            self::Off => [self::Off],
        };
    }
}
