<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * What the active renderer and GPU can apply. Settings screens use it to disable
 * options that would change nothing on this machine instead of offering them;
 * the settings themselves stay untouched, so a graphics.json carried to a more
 * capable machine keeps its choices.
 *
 * Renderers report it through `graphicsCapabilities()`
 * ({@see \PHPolygon\Rendering\GraphicsSettingsManager::capabilities()}).
 */
final class GraphicsCapabilities
{
    /**
     * @param bool          $shadingRate            Variable rate shading ({@see ShadingRate})
     * @param bool          $hdrOutput              HDR10 swapchain output
     * @param bool          $lowLatency             Waitable swapchain with a one-frame queue
     * @param bool          $msaa                   Multisampled scene targets (MSAA 2x/4x)
     * @param bool          $temporalAntiAliasing   A real TAA pass (otherwise TAA falls back to FXAA)
     * @param bool          $screenSpaceReflections SSR tiers change the image
     * @param bool          $fieldtracingSdf        SDF occlusion/bounce tiers (3D textures)
     * @param bool          $surfaceRelief          Cavity + parallax on procedural patterns
     * @param list<Upscaler> $upscalers             Upscalers this renderer implements
     */
    public function __construct(
        public readonly bool $shadingRate = false,
        public readonly bool $hdrOutput = false,
        public readonly bool $lowLatency = false,
        public readonly bool $msaa = true,
        public readonly bool $temporalAntiAliasing = true,
        public readonly bool $screenSpaceReflections = true,
        public readonly bool $fieldtracingSdf = false,
        public readonly bool $surfaceRelief = true,
        public readonly array $upscalers = [Upscaler::Off],
    ) {}

    /** No renderer to ask (headless, before the window exists): nothing is disabled. */
    public static function unknown(): self
    {
        return new self(
            shadingRate: true,
            hdrOutput: true,
            lowLatency: true,
            msaa: true,
            temporalAntiAliasing: true,
            screenSpaceReflections: true,
            fieldtracingSdf: true,
            surfaceRelief: true,
            upscalers: Upscaler::cases(),
        );
    }

    public function supportsUpscaler(Upscaler $upscaler): bool
    {
        return in_array($upscaler, $this->upscalers, true);
    }

    public function supportsAntiAliasing(AntiAliasing $antiAliasing): bool
    {
        return match ($antiAliasing) {
            AntiAliasing::Msaa2x, AntiAliasing::Msaa4x => $this->msaa,
            AntiAliasing::Taa => $this->temporalAntiAliasing,
            default => true,
        };
    }

    public function supportsFieldtracing(FieldtracingMode $mode): bool
    {
        return match ($mode) {
            FieldtracingMode::SdfOcclusion, FieldtracingMode::SdfBounce => $this->fieldtracingSdf,
            default => true,
        };
    }
}
