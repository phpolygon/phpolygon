<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Accessibility;

/**
 * The player's accessibility and comfort choices for the 3D image. Immutable, held by
 * {@see \PHPolygon\Rendering\GraphicsSettings::$accessibility} and persisted with it.
 *
 * Kept apart from the quality fields on purpose: the adaptive controller, the auto
 * tuner and quality presets rewrite quality, and none of them may ever switch an
 * accessibility choice. Every default is "off", and with every field at its default
 * the renderer runs no extra pass, so the image is that of a build without this.
 */
final class AccessibilitySettings
{
    public function __construct(
        /** Colour vision deficiency the image is corrected for. */
        public readonly ColorVisionMode $colorVision = ColorVisionMode::Off,
        /** 0..1, how much of the correction (or simulation) is applied. */
        public readonly float $colorVisionStrength = 1.0,
        /** Developer view: show what a viewer with $colorVision sees instead of correcting for them. */
        public readonly bool $colorVisionSimulate = false,
    ) {
    }

    /** Whether any option changes the 3D image, i.e. the renderer has to run the accessibility pass. */
    public function changesImage(): bool
    {
        return $this->colorVision !== ColorVisionMode::Off;
    }

    public function isDefault(): bool
    {
        return $this->toJson() === (new self())->toJson();
    }

    public function with(
        ?ColorVisionMode $colorVision = null,
        ?float $colorVisionStrength = null,
        ?bool $colorVisionSimulate = null,
    ): self {
        return new self(
            colorVision: $colorVision ?? $this->colorVision,
            colorVisionStrength: self::unit($colorVisionStrength ?? $this->colorVisionStrength),
            colorVisionSimulate: $colorVisionSimulate ?? $this->colorVisionSimulate,
        );
    }

    /** @return array<string, mixed> */
    public function toJson(): array
    {
        return [
            'colorVision' => $this->colorVision->value,
            'colorVisionStrength' => $this->colorVisionStrength,
            'colorVisionSimulate' => $this->colorVisionSimulate,
        ];
    }

    /** A field that is missing or of the wrong type keeps its default. */
    public static function fromJson(mixed $data): self
    {
        $d = new self();
        if (!is_array($data)) {
            return $d;
        }
        $float = static fn (string $key, float $default): float => is_int($data[$key] ?? null) || is_float($data[$key] ?? null) ? self::unit((float) $data[$key]) : $default;
        $bool = static fn (string $key, bool $default): bool => is_bool($data[$key] ?? null) ? $data[$key] : $default;
        $mode = is_string($data['colorVision'] ?? null) ? ColorVisionMode::tryFrom($data['colorVision']) : null;
        return new self(
            colorVision: $mode ?? $d->colorVision,
            colorVisionStrength: $float('colorVisionStrength', $d->colorVisionStrength),
            colorVisionSimulate: $bool('colorVisionSimulate', $d->colorVisionSimulate),
        );
    }

    private static function unit(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }
}
