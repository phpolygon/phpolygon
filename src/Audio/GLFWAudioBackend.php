<?php

declare(strict_types=1);

namespace PHPolygon\Audio;

use GL\Audio\Engine as AudioEngine;
use GL\Audio\Sound;

/**
 * Real audio backend using php-glfw's built-in audio engine.
 * Supports WAV playback, 3D positional audio, looping, and volume control.
 */
class GLFWAudioBackend implements AudioBackendInterface
{
    private AudioEngine $engine;

    /** @var array<string, string> clipId => file path */
    private array $clipPaths = [];

    /** @var array<int, Sound> playbackId => Sound */
    private array $activeSounds = [];

    private int $nextPlaybackId = 1;
    private float $masterVolume = 1.0;

    public function __construct()
    {
        $this->engine = new AudioEngine();
        $this->engine->start();
    }

    public function load(string $id, string $path): AudioClip
    {
        $this->clipPaths[$id] = $path;
        return new AudioClip($id, $path);
    }

    public function play(string $clipId, float $volume = 1.0, bool $loop = false): int
    {
        $path = $this->clipPaths[$clipId] ?? null;
        if ($path === null || !file_exists($path)) {
            return 0;
        }

        $sound = $this->engine->soundFromDisk($path);
        $sound->setVolume($volume * $this->masterVolume);
        $sound->setLoop($loop);
        $sound->play();

        $playbackId = $this->nextPlaybackId++;
        $this->activeSounds[$playbackId] = $sound;

        return $playbackId;
    }

    public function stop(int $playbackId): void
    {
        if (isset($this->activeSounds[$playbackId])) {
            $this->activeSounds[$playbackId]->stop();
            unset($this->activeSounds[$playbackId]);
        }
    }

    public function stopAll(): void
    {
        foreach ($this->activeSounds as $sound) {
            $sound->stop();
        }
        $this->activeSounds = [];
    }

    public function setVolume(int $playbackId, float $volume): void
    {
        if (isset($this->activeSounds[$playbackId])) {
            $this->activeSounds[$playbackId]->setVolume($volume * $this->masterVolume);
        }
    }

    public function isPlaying(int $playbackId): bool
    {
        if (!isset($this->activeSounds[$playbackId])) {
            return false;
        }
        return $this->activeSounds[$playbackId]->isPlaying();
    }

    public function setMasterVolume(float $volume): void
    {
        $this->masterVolume = max(0.0, min(1.0, $volume));
        $this->engine->setMasterVolume($this->masterVolume);
    }

    public function getMasterVolume(): float
    {
        return $this->masterVolume;
    }

    public function dispose(): void
    {
        $this->stopAll();
        $this->engine->stop();
    }
}
