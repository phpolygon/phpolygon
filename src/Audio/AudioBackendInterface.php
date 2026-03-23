<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

interface AudioBackendInterface
{
    public function load(string $id, string $path): AudioClip;

    public function play(string $clipId, float $volume = 1.0, bool $loop = false): int;

    public function stop(int $playbackId): void;

    public function stopAll(): void;

    public function setVolume(int $playbackId, float $volume): void;

    public function isPlaying(int $playbackId): bool;

    public function setMasterVolume(float $volume): void;

    public function getMasterVolume(): float;

    public function dispose(): void;
}
