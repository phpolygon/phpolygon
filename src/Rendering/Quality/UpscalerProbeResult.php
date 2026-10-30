<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Quality;

/**
 * What a native upscaler provider answered on this device.
 */
final class UpscalerProbeResult
{
    /**
     * @param string       $reason    Why not, in the provider's words ('' when supported)
     * @param list<string> $files     Runtime libraries the answer rests on
     * @param bool         $cacheable False when the provider never got to look at the
     *                                device (plugin or runtime library not found): such an
     *                                answer is cheap and changes with the game's files, not
     *                                with the machine
     */
    public function __construct(
        public readonly bool $supported,
        public readonly string $reason = '',
        public readonly array $files = [],
        public readonly bool $cacheable = true,
    ) {}
}
