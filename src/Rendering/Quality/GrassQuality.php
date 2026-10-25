<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

use PHPolygon\Vegetation\GrassDetail;

/**
 * Grass blade detail (see {@see \PHPolygon\System\GrassSystem}). Every level is a
 * parameter set of {@see GrassDetail} - same form language, finer resolution:
 *
 * - Off: no blades, the ground material alone.
 * - Low / Medium / High / Ultra: stylised geometric blades (2 / 3 / 4 / 7
 *   segments), flat colour bands, more density and reach per level.
 * - Realistic: many thin folded blades, smooth colour gradient with noise
 *   variation, strong light transmission against the sun, deep root shading.
 */
enum GrassQuality: string
{
    case Off = 'off';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Ultra = 'ultra';
    case Realistic = 'realistic';

    public function detail(): ?GrassDetail
    {
        return GrassDetail::forQuality($this);
    }

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Low => 'Low',
            self::Medium => 'Medium',
            self::High => 'High',
            self::Ultra => 'Ultra',
            self::Realistic => 'Realistic',
        };
    }
}
