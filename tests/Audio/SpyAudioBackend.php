<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Audio;

use PHPolygon\Audio\AudioBackendInterface;
use PHPolygon\Audio\AudioClip;

/**
 * Test backend that records the exact volume each playback receives.
 */
final class SpyAudioBackend implements AudioBackendInterface
{
    /** @var array<int, float> playbackId => last volume received */
    public array $volumes = [];

    /** @var array<int, float> playbackId => volume of the last setVolume() */
    public array $setVolumeCalls = [];

    public bool $failPlays = false;

    /** @var array<int, bool> playbackId => still playing */
    private array $playing = [];

    private int $nextId = 1;
    private float $master = 1.0;

    public function load(string $id, string $path): AudioClip
    {
        return new AudioClip($id, $path);
    }

    public function play(string $clipId, float $volume = 1.0, bool $loop = false): int
    {
        if ($this->failPlays) {
            return 0;
        }
        $id = $this->nextId++;
        $this->volumes[$id] = $volume;
        $this->playing[$id] = true;
        return $id;
    }

    /** Simulate a one-shot reaching its end. */
    public function finish(int $playbackId): void
    {
        $this->playing[$playbackId] = false;
    }

    public function stop(int $playbackId): void
    {
        $this->playing[$playbackId] = false;
    }

    public function stopAll(): void
    {
        $this->playing = [];
    }

    public function setVolume(int $playbackId, float $volume): void
    {
        $this->volumes[$playbackId] = $volume;
        $this->setVolumeCalls[$playbackId] = $volume;
    }

    public function isPlaying(int $playbackId): bool
    {
        return $this->playing[$playbackId] ?? false;
    }

    public function setMasterVolume(float $volume): void
    {
        $this->master = $volume;
    }

    public function getMasterVolume(): float
    {
        return $this->master;
    }

    public function dispose(): void
    {
    }
}
