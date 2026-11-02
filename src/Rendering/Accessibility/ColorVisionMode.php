<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Accessibility;

/** The colour vision deficiency the image is corrected for (or, for developers, simulated). */
enum ColorVisionMode: string
{
    case Off = 'off';
    /** No long-wavelength (red) cones. */
    case Protanopia = 'protanopia';
    /** No medium-wavelength (green) cones. */
    case Deuteranopia = 'deuteranopia';
    /** No short-wavelength (blue) cones. */
    case Tritanopia = 'tritanopia';

    /** Shader code: 0 off, 1 protan, 2 deutan, 3 tritan. */
    public function code(): int
    {
        return match ($this) {
            self::Off => 0,
            self::Protanopia => 1,
            self::Deuteranopia => 2,
            self::Tritanopia => 3,
        };
    }
}
