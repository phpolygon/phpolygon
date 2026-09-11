<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * How much relief materials with a procedural normal pattern get
 * ({@see \PHPolygon\Rendering\Material::$cavity} and $parallaxDepth).
 *
 * Parallax marches the view ray through the pattern's height field (up to 12
 * height lookups per fragment on those materials), Cavity only darkens the
 * recesses (one lookup), Off renders the flat lit pattern. Materials without
 * relief values render the same in every tier, so the setting costs nothing
 * where no material uses it.
 */
enum SurfaceRelief: string
{
    case Off = 'off';
    case Cavity = 'cavity';
    case Parallax = 'parallax';

    public function cavityEnabled(): bool
    {
        return $this !== self::Off;
    }

    public function parallaxEnabled(): bool
    {
        return $this === self::Parallax;
    }

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Cavity => 'Cavities',
            self::Parallax => 'Parallax',
        };
    }
}
