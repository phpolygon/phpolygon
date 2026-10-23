<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

use PHPolygon\Audio\Spatial\AudioListener;
use PHPolygon\Audio\Spatial\SpatialSource;

/**
 * A spatial backend that places 3D voices in the world itself: the native
 * listener follows the camera, the backend attenuates, pans, Doppler-shifts
 * and cones each source, and 2D voices bypass the spatializer entirely.
 * Pitch and pan of a running voice can change.
 *
 * {@see hasNativeSpatial()} is the switch: when it returns false (e.g. php-vio
 * older than 2.36) AudioManager treats the backend as a plain
 * {@see SpatialAudioBackendInterface} – it computes distance gain itself and
 * passes listener-space directions, and pitch/pan stay at their start values.
 */
interface NativeSpatialAudioBackendInterface extends SpatialAudioBackendInterface
{
    /** True when this backend currently does the 3D work itself. Must not change after the first voice. */
    public function hasNativeSpatial(): bool;

    /**
     * Start a 3D voice. $volume already holds voice × channel × master ×
     * ducks (and 0 out of range); the backend adds distance, cone and
     * Doppler.
     *
     * @return int playback id, 0 when the clip could not be started
     */
    public function playSource(string $clipId, float $volume, bool $loop, float $pitch, SpatialSource $source): int;

    /** New position, velocity, roll-off, Doppler factor, facing or cone of a running 3D voice. */
    public function updateSource(int $playbackId, SpatialSource $source): void;

    /** Pose and velocity of the ears; velocity is never null here. */
    public function setListenerPose(AudioListener $listener): void;

    /** Speed of sound in world units per second (Doppler). */
    public function setSpeedOfSound(float $speed): void;

    /** Playback rate of a running voice (> 0). */
    public function setPitch(int $playbackId, float $pitch): void;

    /** Stereo pan of a running voice (−1…+1). */
    public function setPan(int $playbackId, float $pan): void;
}
