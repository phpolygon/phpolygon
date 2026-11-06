<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\PostProcess;

/**
 * Curve that maps the linear HDR scene colour to the display in the image chain
 * ({@see ImageLook::$tonemapper}). Only the HDR scene path tonemaps in the chain; an
 * LDR scene is tonemapped by the mesh shader and keeps ACES.
 */
enum Tonemapper: string
{
    /** Narkowicz's ACES fit, then gamma 2.2: the curve of the mesh shader and the present. */
    case Aces = 'aces';

    /**
     * Log-encoded sigmoid in the manner of AgX (own constants, not Blender's curve): the
     * colour is pulled a little towards grey, encoded as log2 over 16.5 stops, shaped by a
     * sigmoid that puts mid grey where ACES puts it, and partly pushed back out. Highlights
     * run to white without the hue skew and the hard shoulder of ACES.
     */
    case Sigmoid = 'sigmoid';

    public function code(): int
    {
        return match ($this) {
            self::Aces => 0,
            self::Sigmoid => 1,
        };
    }
}
