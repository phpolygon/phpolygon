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
        /** 1 = unchanged, 0 = grey: saturation of the 3D image (the UI keeps its colours). */
        public readonly float $worldSaturation = 1.0,
        /** 0 = off, 1 = strongest: steeper tone curve plus dark outlines along luminance edges. */
        public readonly float $contrast = 0.0,
        /**
         * Hold or slow the shader time of effects by their register entry (ReducedMotion::
         * Frozen / Off hold it, Reduced runs at $motionScale). Changes no pixel by itself and
         * leaves every effect without an entry, and the base mesh program, alone.
         */
        public readonly bool $reducedMotion = false,
        /** 0..1, speed of ReducedMotion::Reduced effects while $reducedMotion is on. */
        public readonly float $motionScale = 0.25,
    ) {
    }

    /** Whether any option changes the 3D image, i.e. the renderer has to run the accessibility pass. */
    public function changesImage(): bool
    {
        return $this->colorVision !== ColorVisionMode::Off
            || $this->worldSaturation < 1.0
            || $this->contrast > 0.0;
    }

    public function isDefault(): bool
    {
        return $this->toJson() === (new self())->toJson();
    }

    public function with(
        ?ColorVisionMode $colorVision = null,
        ?float $colorVisionStrength = null,
        ?bool $colorVisionSimulate = null,
        ?float $worldSaturation = null,
        ?float $contrast = null,
        ?bool $reducedMotion = null,
        ?float $motionScale = null,
    ): self {
        return new self(
            colorVision: $colorVision ?? $this->colorVision,
            colorVisionStrength: self::unit($colorVisionStrength ?? $this->colorVisionStrength),
            colorVisionSimulate: $colorVisionSimulate ?? $this->colorVisionSimulate,
            worldSaturation: self::unit($worldSaturation ?? $this->worldSaturation),
            contrast: self::unit($contrast ?? $this->contrast),
            reducedMotion: $reducedMotion ?? $this->reducedMotion,
            motionScale: self::unit($motionScale ?? $this->motionScale),
        );
    }

    /** @return array<string, mixed> */
    public function toJson(): array
    {
        return [
            'colorVision' => $this->colorVision->value,
            'colorVisionStrength' => $this->colorVisionStrength,
            'colorVisionSimulate' => $this->colorVisionSimulate,
            'worldSaturation' => $this->worldSaturation,
            'contrast' => $this->contrast,
            'reducedMotion' => $this->reducedMotion,
            'motionScale' => $this->motionScale,
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
            worldSaturation: $float('worldSaturation', $d->worldSaturation),
            contrast: $float('contrast', $d->contrast),
            reducedMotion: $bool('reducedMotion', $d->reducedMotion),
            motionScale: $float('motionScale', $d->motionScale),
        );
    }

    private static function unit(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }
}
