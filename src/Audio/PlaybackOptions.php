<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

use InvalidArgumentException;
use PHPolygon\Audio\Spatial\Attenuation;
use PHPolygon\Audio\Spatial\SoundCone;
use PHPolygon\Math\Vec3;

/**
 * Everything a single playback starts with.
 *
 * - $pitch: playback rate factor (1 = original, 2 = an octave up and twice as
 *   fast). Changeable later with AudioManager::setPlaybackPitch() on a native
 *   spatial backend (php-vio ≥ 2.36); fixed otherwise.
 * - $pan: −1 (left) … +1 (right) for non-positioned voices; ignored when
 *   $position is set (the direction pans then).
 * - $position: world position – the voice becomes a 3D source, attenuated by
 *   distance and panned by direction relative to the listener.
 * - $attenuation: roll-off of a 3D source; null = the manager's default.
 * - $velocity: world units per second of a 3D source for the Doppler effect;
 *   null = derived from setPlaybackPosition() calls.
 * - $doppler: this voice's Doppler factor (0 = off, 1 = physical).
 * - $direction + $cone: a directional 3D source – full volume inside the
 *   cone around $direction, the cone's outer gain behind it. Without a cone
 *   the direction has no audible effect.
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
        public readonly ?Vec3 $velocity = null,
        public readonly float $doppler = 1.0,
        public readonly ?Vec3 $direction = null,
        public readonly ?SoundCone $cone = null,
    ) {
        if (!is_finite($doppler) || $doppler < 0.0) {
            throw new InvalidArgumentException("PlaybackOptions: doppler must be >= 0, got {$doppler}");
        }
    }

    public static function at(Vec3 $position, float $volume = 1.0, bool $loop = false, ?Attenuation $attenuation = null, float $pitch = 1.0): self
    {
        return new self($volume, $loop, $pitch, 0.0, $position, $attenuation);
    }
}
