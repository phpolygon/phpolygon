<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

class NullAudioBackend implements AudioBackendInterface
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
