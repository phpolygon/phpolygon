<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

/**
 * Least closing of the comfort vignette (AccessibilitySettings::$comfortVignette) the
 * game asks for, for motion the camera alone does not show: a lift, a ride, swimming.
 * Sticky until the next command; 0 hands it back to the camera motion. Scaled by the
 * player's strength like the rest, so with the option off it changes nothing.
 */
readonly class SetComfortVignette
{
    /** @param float $minimum 0..1 */
    public function __construct(
        public float $minimum,
    ) {}
}
