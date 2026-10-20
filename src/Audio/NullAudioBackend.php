<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

/**
 * Silent backend: every play gets an id and finishes at once. For headless
 * runs; tests that need voices to stay alive use {@see SimulatedAudioBackend}.
 */
class NullAudioBackend implements SpatialAudioBackendInterface
{
    private float $masterVolume = 1.0;
    private int $nextPlaybackId = 1;

    public function load(string $id, string $path): AudioClip
    {
        return new AudioClip($id, $path);
    }

    public function play(string $clipId, float $volume = 1.0, bool $loop = false): int
    {
        return $this->nextPlaybackId++;
    }

    public function playVoice(
        string $clipId,
        float $volume = 1.0,
        bool $loop = false,
        float $pitch = 1.0,
        float $pan = 0.0,
        ?array $direction = null,
    ): int {
        return $this->nextPlaybackId++;
    }

    public function setDirection(int $playbackId, float $x, float $y, float $z): void {}

    public function pause(int $playbackId): void {}

    public function resume(int $playbackId): void {}

    public function isPaused(int $playbackId): bool
    {
        return false;
    }

    public function stop(int $playbackId): void {}

    public function stopAll(): void {}

    public function setVolume(int $playbackId, float $volume): void {}

    public function isPlaying(int $playbackId): bool
    {
        return false;
    }

    public function setMasterVolume(float $volume): void
    {
        $this->masterVolume = $volume;
    }

    public function getMasterVolume(): float
    {
        return $this->masterVolume;
    }

    public function dispose(): void {}
}
