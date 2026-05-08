<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Rendering\Quality\AntiAliasing;
use PHPolygon\Rendering\Quality\MeshLodTier;
use PHPolygon\Rendering\Quality\QualityMode;
use PHPolygon\Rendering\Quality\ShaderQuality;
use PHPolygon\Rendering\Quality\ShadowQuality;
use PHPolygon\Rendering\Quality\TextureQuality;

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
        public readonly bool $fog = true,
        public readonly MeshLodTier $meshLod = MeshLodTier::High,
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
        ?bool $fog = null,
        ?MeshLodTier $meshLod = null,
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
            fog: $fog ?? $this->fog,
            meshLod: $meshLod ?? $this->meshLod,
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
            'fog' => $this->fog,
            'meshLod' => $this->meshLod->value,
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
            fog: self::asBool($data['fog'] ?? null) ?? $defaults->fog,
            meshLod: self::enumFrom(MeshLodTier::class, $data['meshLod'] ?? null) ?? $defaults->meshLod,
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
