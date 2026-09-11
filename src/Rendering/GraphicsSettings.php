<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\ColorGradingPreset;
use PHPolygon\Rendering\Quality\FieldtracingMode;
use PHPolygon\Rendering\Quality\MeshLodTier;
use PHPolygon\Rendering\Quality\QualityMode;
use PHPolygon\Rendering\Quality\ScreenSpaceAO;
use PHPolygon\Rendering\Quality\ScreenSpaceReflections;
use PHPolygon\Rendering\Quality\ShaderQuality;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\Quality\ShadingRate;
use PHPolygon\Rendering\Quality\SurfaceRelief;
use PHPolygon\Rendering\Quality\TextureQuality;
use PHPolygon\Rendering\Quality\Upscaler;

/**
 * Immutable graphics-settings value object.
 *
 * Defaults reproduce the engine's pre-existing rendering behaviour 1:1, so
 * shipping games that have no graphics.json on disk render identically to
 * before this system was introduced. All updates produce a new instance via
 * the with() helper - never mutate fields directly.
 *
 * JSON serialisation is hand-written here (and not driven by Component
 * attributes) because GraphicsSettings is not a Component - it is a player
 * settings record persisted alongside save games.
 */
final class GraphicsSettings
{
    public function __construct(
        public readonly QualityMode $mode = QualityMode::Manual,
        public readonly float $targetFps = 60.0,
        public readonly float $renderScale = 1.0,
        public readonly ShadowQuality $shadowQuality = ShadowQuality::Medium,
        public readonly float $shadowDistance = 50.0,
        public readonly float $viewDistance = 200.0,
        public readonly AntiAliasing $antiAliasing = AntiAliasing::Fxaa,
        public readonly int $anisotropy = 4,
        public readonly bool $vsync = true,
        public readonly int $fpsCap = 0,
        public readonly TextureQuality $textureQuality = TextureQuality::Full,
        public readonly ShaderQuality $shaderQuality = ShaderQuality::Full,
        public readonly bool $cloudShadows = true,
        public readonly bool $bloom = true,
        public readonly bool $hdr = true,
        /**
         * Low input latency: cap the CPU at ONE frame ahead of presentation
         * (php-vio 'frame_latency' => 1, a waitable swapchain on D3D11 / D3D12)
         * instead of DXGI's default queue of three. Costs a little throughput
         * when the GPU is the bottleneck; applied when the window is created,
         * so a change takes effect on the next start.
         */
        public readonly bool $lowLatency = false,
        /**
         * Variable rate shading for the 3D scene pass (php-vio >= 2.19,
         * VIO_FEATURE_SHADING_RATE): Half shades 2x2 pixel blocks once, Quarter
         * 4x4 - the cheapest performance tier since geometry, depth and the
         * resolution stay untouched. UI and post-processing run at full rate;
         * backends without the feature ignore it.
         */
        public readonly ShadingRate $shadingRate = ShadingRate::Full,
        /**
         * HDR10 display output (php-vio 'hdr_output', D3D11 / D3D12): a 10-bit
         * ST 2084 backbuffer when the display is in HDR mode; the resolve
         * shaders then PQ-encode the tonemapped image with hdrPaperWhite nits
         * as display white. Applied at window creation. Independent of $hdr
         * (the FP16 scene target), which stays the source of the highlights.
         */
        public readonly bool $hdrOutput = false,
        public readonly float $hdrPaperWhite = 200.0,
        public readonly bool $fog = true,
        public readonly MeshLodTier $meshLod = MeshLodTier::High,
        public readonly ScreenSpaceAO $ambientOcclusion = ScreenSpaceAO::Medium,
        public readonly ColorGradingPreset $colorGrading = ColorGradingPreset::Neutral,
        public readonly float $vignetteIntensity = 0.0,
        public readonly bool $volumetricFog = false,
        public readonly ScreenSpaceReflections $ssr = ScreenSpaceReflections::Off,
        public readonly FieldtracingMode $fieldtracing = FieldtracingMode::Off,
        /**
         * Relief of materials with a procedural normal pattern (Material::$cavity
         * and $parallaxDepth): Parallax applies both, Cavity only darkens the
         * recesses, Off renders the flat pattern. Materials without relief values
         * render the same in every tier.
         */
        public readonly SurfaceRelief $surfaceRelief = SurfaceRelief::Parallax,
        /**
         * Upscaler for render scales below 1: Off stretches bilinearly, Fsr1 runs
         * AMD FidelityFX Super Resolution 1 (edge-adaptive upscale + contrast-
         * adaptive sharpening). No effect at render scale 1 and on renderers that
         * do not implement it (see GraphicsCapabilities).
         */
        public readonly Upscaler $upscaler = Upscaler::Off,
        /** Sharpening of the FSR upscaler: 0 = soft, 1 = strongest. */
        public readonly float $upscaleSharpness = 0.9,
    ) {
    }

    /**
     * Produce a new GraphicsSettings with selected fields overridden.
     * Pass null (or omit) to keep the existing value for any field.
     */
    public function with(
        ?QualityMode $mode = null,
        ?float $targetFps = null,
        ?float $renderScale = null,
        ?ShadowQuality $shadowQuality = null,
        ?float $shadowDistance = null,
        ?float $viewDistance = null,
        ?AntiAliasing $antiAliasing = null,
        ?int $anisotropy = null,
        ?bool $vsync = null,
        ?int $fpsCap = null,
        ?TextureQuality $textureQuality = null,
        ?ShaderQuality $shaderQuality = null,
        ?bool $cloudShadows = null,
        ?bool $bloom = null,
        ?bool $hdr = null,
        ?bool $lowLatency = null,
        ?ShadingRate $shadingRate = null,
        ?bool $hdrOutput = null,
        ?float $hdrPaperWhite = null,
        ?bool $fog = null,
        ?MeshLodTier $meshLod = null,
        ?ScreenSpaceAO $ambientOcclusion = null,
        ?ColorGradingPreset $colorGrading = null,
        ?float $vignetteIntensity = null,
        ?bool $volumetricFog = null,
        ?ScreenSpaceReflections $ssr = null,
        ?FieldtracingMode $fieldtracing = null,
        ?SurfaceRelief $surfaceRelief = null,
        ?Upscaler $upscaler = null,
        ?float $upscaleSharpness = null,
    ): self {
        return new self(
            mode: $mode ?? $this->mode,
            targetFps: $targetFps ?? $this->targetFps,
            renderScale: $renderScale !== null ? self::clampRenderScale($renderScale) : $this->renderScale,
            shadowQuality: $shadowQuality ?? $this->shadowQuality,
            shadowDistance: $shadowDistance ?? $this->shadowDistance,
            viewDistance: $viewDistance ?? $this->viewDistance,
            antiAliasing: $antiAliasing ?? $this->antiAliasing,
            anisotropy: $anisotropy !== null ? self::clampAnisotropy($anisotropy) : $this->anisotropy,
            vsync: $vsync ?? $this->vsync,
            fpsCap: $fpsCap !== null ? self::clampFpsCap($fpsCap) : $this->fpsCap,
            textureQuality: $textureQuality ?? $this->textureQuality,
            shaderQuality: $shaderQuality ?? $this->shaderQuality,
            cloudShadows: $cloudShadows ?? $this->cloudShadows,
            bloom: $bloom ?? $this->bloom,
            hdr: $hdr ?? $this->hdr,
            lowLatency: $lowLatency ?? $this->lowLatency,
            shadingRate: $shadingRate ?? $this->shadingRate,
            hdrOutput: $hdrOutput ?? $this->hdrOutput,
            hdrPaperWhite: max(80.0, min(1000.0, $hdrPaperWhite ?? $this->hdrPaperWhite)),
            fog: $fog ?? $this->fog,
            meshLod: $meshLod ?? $this->meshLod,
            ambientOcclusion: $ambientOcclusion ?? $this->ambientOcclusion,
            colorGrading: $colorGrading ?? $this->colorGrading,
            vignetteIntensity: $vignetteIntensity !== null ? max(0.0, min(1.0, $vignetteIntensity)) : $this->vignetteIntensity,
            volumetricFog: $volumetricFog ?? $this->volumetricFog,
            ssr: $ssr ?? $this->ssr,
            fieldtracing: $fieldtracing ?? $this->fieldtracing,
            surfaceRelief: $surfaceRelief ?? $this->surfaceRelief,
            upscaler: $upscaler ?? $this->upscaler,
            upscaleSharpness: $upscaleSharpness !== null ? max(0.0, min(1.0, $upscaleSharpness)) : $this->upscaleSharpness,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toJson(): array
    {
        return [
            'mode' => $this->mode->value,
            'targetFps' => $this->targetFps,
            'renderScale' => $this->renderScale,
            'shadowQuality' => $this->shadowQuality->value,
            'shadowDistance' => $this->shadowDistance,
            'viewDistance' => $this->viewDistance,
            'antiAliasing' => $this->antiAliasing->value,
            'anisotropy' => $this->anisotropy,
            'vsync' => $this->vsync,
            'fpsCap' => $this->fpsCap,
            'textureQuality' => $this->textureQuality->value,
            'shaderQuality' => $this->shaderQuality->value,
            'cloudShadows' => $this->cloudShadows,
            'bloom' => $this->bloom,
            'hdr' => $this->hdr,
            'lowLatency' => $this->lowLatency,
            'shadingRate' => $this->shadingRate->value,
            'hdrOutput' => $this->hdrOutput,
            'hdrPaperWhite' => $this->hdrPaperWhite,
            'fog' => $this->fog,
            'meshLod' => $this->meshLod->value,
            'ambientOcclusion' => $this->ambientOcclusion->value,
            'colorGrading' => $this->colorGrading->value,
            'vignetteIntensity' => $this->vignetteIntensity,
            'volumetricFog' => $this->volumetricFog,
            'ssr' => $this->ssr->value,
            'fieldtracing' => $this->fieldtracing->value,
            'surfaceRelief' => $this->surfaceRelief->value,
            'upscaler' => $this->upscaler->value,
            'upscaleSharpness' => $this->upscaleSharpness,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromJson(array $data): self
    {
        $defaults = new self();
        return new self(
            mode: self::enumFrom(QualityMode::class, $data['mode'] ?? null) ?? $defaults->mode,
            targetFps: self::asFloat($data['targetFps'] ?? null) ?? $defaults->targetFps,
            renderScale: ($v = self::asFloat($data['renderScale'] ?? null)) !== null ? self::clampRenderScale($v) : $defaults->renderScale,
            shadowQuality: self::enumFrom(ShadowQuality::class, $data['shadowQuality'] ?? null) ?? $defaults->shadowQuality,
            shadowDistance: self::asFloat($data['shadowDistance'] ?? null) ?? $defaults->shadowDistance,
            viewDistance: self::asFloat($data['viewDistance'] ?? null) ?? $defaults->viewDistance,
            antiAliasing: self::enumFrom(AntiAliasing::class, $data['antiAliasing'] ?? null) ?? $defaults->antiAliasing,
            anisotropy: ($v = self::asInt($data['anisotropy'] ?? null)) !== null ? self::clampAnisotropy($v) : $defaults->anisotropy,
            vsync: self::asBool($data['vsync'] ?? null) ?? $defaults->vsync,
            fpsCap: ($v = self::asInt($data['fpsCap'] ?? null)) !== null ? self::clampFpsCap($v) : $defaults->fpsCap,
            textureQuality: self::enumFrom(TextureQuality::class, $data['textureQuality'] ?? null) ?? $defaults->textureQuality,
            shaderQuality: self::enumFrom(ShaderQuality::class, $data['shaderQuality'] ?? null) ?? $defaults->shaderQuality,
            cloudShadows: self::asBool($data['cloudShadows'] ?? null) ?? $defaults->cloudShadows,
            bloom: self::asBool($data['bloom'] ?? null) ?? $defaults->bloom,
            hdr: self::asBool($data['hdr'] ?? null) ?? $defaults->hdr,
            lowLatency: self::asBool($data['lowLatency'] ?? null) ?? $defaults->lowLatency,
            shadingRate: self::enumFrom(ShadingRate::class, $data['shadingRate'] ?? null) ?? $defaults->shadingRate,
            hdrOutput: self::asBool($data['hdrOutput'] ?? null) ?? $defaults->hdrOutput,
            hdrPaperWhite: max(80.0, min(1000.0, self::asFloat($data['hdrPaperWhite'] ?? null) ?? $defaults->hdrPaperWhite)),
            fog: self::asBool($data['fog'] ?? null) ?? $defaults->fog,
            meshLod: self::enumFrom(MeshLodTier::class, $data['meshLod'] ?? null) ?? $defaults->meshLod,
            ambientOcclusion: self::enumFrom(ScreenSpaceAO::class, $data['ambientOcclusion'] ?? null) ?? $defaults->ambientOcclusion,
            colorGrading: self::enumFrom(ColorGradingPreset::class, $data['colorGrading'] ?? null) ?? $defaults->colorGrading,
            vignetteIntensity: ($v = self::asFloat($data['vignetteIntensity'] ?? null)) !== null ? max(0.0, min(1.0, $v)) : $defaults->vignetteIntensity,
            volumetricFog: self::asBool($data['volumetricFog'] ?? null) ?? $defaults->volumetricFog,
            ssr: self::enumFrom(ScreenSpaceReflections::class, $data['ssr'] ?? null) ?? $defaults->ssr,
            fieldtracing: self::enumFrom(FieldtracingMode::class, $data['fieldtracing'] ?? null) ?? $defaults->fieldtracing,
            surfaceRelief: self::enumFrom(SurfaceRelief::class, $data['surfaceRelief'] ?? null) ?? $defaults->surfaceRelief,
            upscaler: self::enumFrom(Upscaler::class, $data['upscaler'] ?? null) ?? $defaults->upscaler,
            upscaleSharpness: ($v = self::asFloat($data['upscaleSharpness'] ?? null)) !== null ? max(0.0, min(1.0, $v)) : $defaults->upscaleSharpness,
        );
    }

    private static function asFloat(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float)$v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (float)$v;
        }
        return null;
    }

    private static function asInt(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (int)$v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int)$v;
        }
        return null;
    }

    private static function asBool(mixed $v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        return null;
    }

    private static function clampRenderScale(float $v): float
    {
        return max(0.5, min(2.0, $v));
    }

    private static function clampAnisotropy(int $v): int
    {
        $allowed = [1, 2, 4, 8, 16];
        if (in_array($v, $allowed, true)) {
            return $v;
        }
        // Snap to closest allowed value
        $best = 4;
        $bestDist = PHP_INT_MAX;
        foreach ($allowed as $a) {
            $d = abs($a - $v);
            if ($d < $bestDist) {
                $bestDist = $d;
                $best = $a;
            }
        }
        return $best;
    }

    private static function clampFpsCap(int $v): int
    {
        if ($v <= 0) {
            return 0;
        }
        $allowed = [30, 60, 120, 144];
        if (in_array($v, $allowed, true)) {
            return $v;
        }
        return 0;
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @param mixed $value
     * @return T|null
     */
    private static function enumFrom(string $enum, mixed $value): ?\BackedEnum
    {
        if (!is_string($value)) {
            return null;
        }
        return $enum::tryFrom($value);
    }
}
