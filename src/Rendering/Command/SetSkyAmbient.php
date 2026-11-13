<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

/**
 * Diffuse ambient light that follows the sky (opt-in). Without it the ambient is the flat
 * {@see SetAmbientLight} colour on every surface; with it the ambient of a surface is the zenith colour of the frame's
 * {@see SetSky} from above, its horizon colour from the sides and its ground colour times $groundBounce from below,
 * blended by the surface normal's elevation (a first-order SH-like irradiance): a wall in the shade of a blue
 * sky takes the sky's blue, the underside of a ledge the warm ground bounce, and the ambient moves with the sky
 * as the sky changes. $gain scales it (the SetAmbientLight intensity is ignored while it is on). With no SetSky in
 * the frame the flat ambient stays. For a sky drawn by the physical model pass colours that match it (the colours
 * of SetSky are what the ambient reads, whatever the sky is drawn with).
 */
readonly class SetSkyAmbient
{
    public function __construct(
        public float $gain = 1.0,
        public float $groundBounce = 0.4,
        /**
         * Take the diffuse ambient from the sky cube the renderer draws for the frame's SetSky (the same image the reflections use):
         * its six faces are read back once per sky change and projected to second-order spherical harmonics, so clouds, the
         * sun's glow and the haze colour the ambient too, not only the three gradient colours. The cube is an 8-bit image
         * (the sun disc is clipped): the sun itself stays the directional light's business. Needs a backend with render-target
         * cubemaps; without it (or before the first cube) the three-colour ambient is used.
         */
        public bool $fromCubemap = false,
    ) {}
}
