<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

use PHPolygon\Rendering\PostProcess\ImageLook;

/**
 * Look of the image chain (exposure, bloom, tonemap, LUT, ...) from this frame on, sticky
 * until the next command. Null or the default look hands the chain back to the v0.97 frame.
 * A game blends looks itself ({@see ImageLook::lerp()}) by area and time of day.
 */
readonly class SetImageLook
{
    public function __construct(
        public ?ImageLook $look,
    ) {}
}
