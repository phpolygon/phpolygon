<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

interface AudioBackendInterface
{
    public function load(string $id, string $path): AudioClip;

    /**
     * Start a clip at exactly the given volume. Callers going through
     * AudioManager pass the final voice × channel × master product.
     *
     * @return int playback id, 0 when the clip could not be started
     */
    public function play(string $clipId, float $volume = 1.0, bool $loop = false): int;

    public function stop(int $playbackId): void;

    public function stopAll(): void;

    /**
     * Set a running voice to exactly the given volume.
     */
    public function setVolume(int $playbackId, float $volume): void;

    public function isPlaying(int $playbackId): bool;

    /**
     * The backend's own output gain, applied on top of every voice.
     * AudioManager never sets it – it folds its master volume into each
     * voice instead – so it stays at 1.0 unless a caller drives the backend
     * directly.
     */
    public function setMasterVolume(float $volume): void;

    public function getMasterVolume(): float;

    public function dispose(): void;
}
