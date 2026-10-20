<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

/**
 * A backend that can pitch, pan, position and pause single voices.
 *
 * AudioManager does all listener and distance arithmetic itself; the backend
 * only receives the voice's final volume (distance gain included) and a unit
 * direction in listener space (+X right, +Y up, −Z forward) – the zero vector
 * means "not positioned" (centred, no panning). Backends that do not implement
 * this interface still work: pitch and pan are dropped, distance attenuation
 * still applies through setVolume(), and pause is emulated by muting.
 */
interface SpatialAudioBackendInterface extends AudioBackendInterface
{
    /**
     * Start a clip with pitch (rate factor), pan (−1…+1, for unpositioned
     * voices) and an optional listener-space unit direction ([x, y, z]).
     *
     * @param array{float, float, float}|null $direction
     * @return int playback id, 0 when the clip could not be started
     */
    public function playVoice(
        string $clipId,
        float $volume = 1.0,
        bool $loop = false,
        float $pitch = 1.0,
        float $pan = 0.0,
        ?array $direction = null,
    ): int;

    /** Listener-space unit direction of a running voice; (0, 0, 0) = centred. */
    public function setDirection(int $playbackId, float $x, float $y, float $z): void;

    /** Hold a voice where it is. A paused voice still counts as playing (it has not finished). */
    public function pause(int $playbackId): void;

    public function resume(int $playbackId): void;

    public function isPaused(int $playbackId): bool;
}
