<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

use PHPolygon\Audio\Spatial\Attenuation;
use PHPolygon\Math\Vec3;

/**
 * Everything a single playback starts with.
 *
 * - $pitch: playback rate factor (1 = original, 2 = an octave up and twice as
 *   fast); fixed for the playback's lifetime.
 * - $pan: −1 (left) … +1 (right) for non-positioned voices; ignored when
 *   $position is set (the direction pans then).
 * - $position: world position – the voice becomes a 3D source, attenuated by
 *   distance and panned by direction relative to the listener.
 * - $attenuation: roll-off of a 3D source; null = the manager's default.
 */
final class PlaybackOptions
{
    public function __construct(
        public readonly float $volume = 1.0,
        public readonly bool $loop = false,
        public readonly float $pitch = 1.0,
        public readonly float $pan = 0.0,
        public readonly ?Vec3 $position = null,
        public readonly ?Attenuation $attenuation = null,
    ) {}

    public static function at(Vec3 $position, float $volume = 1.0, bool $loop = false, ?Attenuation $attenuation = null, float $pitch = 1.0): self
    {
        return new self($volume, $loop, $pitch, 0.0, $position, $attenuation);
    }
}
